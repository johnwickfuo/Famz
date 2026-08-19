<?php

namespace App\Http\Controllers;

use App\Enums\DisputeReason;
use App\Models\Dispute;
use App\Models\DisputeMessage;
use App\Models\SubOrder;
use App\Services\Disputes\DisputeService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The buyer's and seller's side of a dispute.
 *
 * Both parties read and write the same thread. An administrator's internal
 * notes are the one thing withheld, and they are withheld from both — an
 * arbitrator taking one side into a private room is not arbitrating.
 */
class DisputeController extends Controller
{
    public function __construct(private readonly DisputeService $disputes) {}

    public function create(Request $request, SubOrder $subOrder): Response|RedirectResponse
    {
        Gate::authorize('raiseDispute', $subOrder);

        if (! $this->disputes->canRaise($subOrder, $request->user())) {
            $existing = $this->disputes->liveDisputeFor($subOrder);

            return $existing !== null
                ? redirect()->route('disputes.show', $existing)
                : redirect()
                    ->route('orders.show', $subOrder->order)
                    ->with('error', __('The time to dispute this order has passed.'));
        }

        $subOrder->load(['seller', 'items', 'order']);

        return Inertia::render('Disputes/Create', [
            'subOrder' => [
                'reference' => $subOrder->reference,
                'order_reference' => $subOrder->order->reference,
                'seller' => $subOrder->seller->business_name,
                'total' => Money::fromKobo($subOrder->grandTotalKobo()),
                'delivered_at' => $subOrder->delivered_at?->format('j M Y'),
                'items' => $subOrder->items->map(fn ($item): array => [
                    'name' => $item->label(),
                    'quantity' => $item->quantity,
                ])->all(),
            ],
            'reasons' => collect(DisputeReason::cases())
                ->map(fn (DisputeReason $reason): array => [
                    'value' => $reason->value,
                    'label' => $reason->label(),
                ])->all(),
            'closesAt' => $this->disputes->windowClosesAt($subOrder)?->format('j M Y'),
        ]);
    }

    public function store(Request $request, SubOrder $subOrder): RedirectResponse
    {
        Gate::authorize('raiseDispute', $subOrder);

        $validated = $request->validate([
            'reason' => ['required', Rule::enum(DisputeReason::class)],
            'description' => ['required', 'string', 'min:15', 'max:2000'],
            'evidence' => ['nullable', 'array', 'max:6'],
            'evidence.*' => ['image', 'max:5120'],
        ], [], [
            'description' => __('what went wrong'),
        ]);

        $paths = collect($request->file('evidence') ?? [])
            ->map(fn ($file): string => $file->store('disputes', 'public'))
            ->all();

        try {
            $dispute = $this->disputes->raise(
                $subOrder,
                $request->user(),
                DisputeReason::from($validated['reason']),
                $validated['description'],
                $paths,
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('disputes.show', $dispute)
            ->with('success', __('We have your complaint. The seller\'s money is on hold until this is settled.'));
    }

    public function index(Request $request): Response
    {
        $disputes = Dispute::query()
            ->whereHas('subOrder.order', fn ($query) => $query->where('user_id', $request->user()->id))
            ->with(['subOrder.seller'])
            ->latest()
            ->paginate(15);

        return Inertia::render('Disputes/Index', [
            'disputes' => collect($disputes->items())->map(fn (Dispute $dispute): array => [
                'id' => $dispute->id,
                'reference' => $dispute->subOrder->reference,
                'seller' => $dispute->subOrder->seller->business_name,
                'reason' => $dispute->reason->label(),
                'status' => $dispute->status->value,
                'status_label' => $dispute->status->label(),
                'raised_at' => $dispute->created_at->format('j M Y'),
                'amount' => Money::fromKobo($dispute->amountAtStakeKobo()),
                'refunded' => $dispute->refund_amount_kobo > 0
                    ? Money::fromKobo($dispute->refund_amount_kobo)
                    : null,
            ])->all(),
            'pagination' => [
                'links' => $disputes->linkCollection()->toArray(),
                'from' => $disputes->firstItem(),
                'to' => $disputes->lastItem(),
                'total' => $disputes->total(),
            ],
        ]);
    }

    public function show(Request $request, Dispute $dispute): Response
    {
        Gate::authorize('view', $dispute);

        $dispute->load(['subOrder.seller', 'subOrder.order', 'messages.author', 'resolver']);

        return Inertia::render('Disputes/Show', [
            'dispute' => [
                'id' => $dispute->id,
                'reason' => $dispute->reason->label(),
                'description' => $dispute->description,
                'status' => $dispute->status->value,
                'status_label' => $dispute->status->label(),
                'is_live' => $dispute->isLive(),
                'raised_at' => $dispute->created_at->format('j M Y, H:i'),
                'amount' => Money::fromKobo($dispute->amountAtStakeKobo()),
                'refunded' => $dispute->refund_amount_kobo > 0
                    ? Money::fromKobo($dispute->refund_amount_kobo)
                    : null,
                'resolution_note' => $dispute->resolution_note,
                'resolved_at' => $dispute->resolved_at?->format('j M Y'),
                'evidence' => $dispute->evidenceUrls(),
            ],
            'subOrder' => [
                'reference' => $dispute->subOrder->reference,
                'order_reference' => $dispute->subOrder->order->reference,
                'seller' => $dispute->subOrder->seller->business_name,
                'status_label' => $dispute->subOrder->status->label(),
            ],
            'messages' => $dispute->messages
                // Internal notes are the arbitrator's own; neither side sees
                // them.
                ->reject(fn (DisputeMessage $message): bool => $message->is_internal)
                ->map(fn (DisputeMessage $message): array => [
                    'id' => $message->id,
                    'body' => $message->body,
                    'author' => $message->author?->displayName() ?? __('Somebody'),
                    'is_mine' => $message->user_id === $request->user()->id,
                    'at' => $message->created_at->format('j M, H:i'),
                ])->values()->all(),
            'canReply' => $dispute->isLive(),
        ]);
    }

    public function reply(Request $request, Dispute $dispute): RedirectResponse
    {
        Gate::authorize('reply', $dispute);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $this->disputes->comment($dispute, $request->user(), $validated['body']);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Sent.'));
    }
}
