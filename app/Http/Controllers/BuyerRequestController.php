<?php

namespace App\Http\Controllers;

use App\Enums\BuyerRequestStatus;
use App\Enums\UnitOfMeasure;
use App\Models\BuyerRequest;
use App\Models\Category;
use App\Models\Offer;
use App\Services\Offers\BuyerRequestService;
use App\Services\Offers\OfferService;
use App\Support\Money;
use App\Support\Nigeria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The wanted-ad board.
 *
 * The public sees requests and how many offers each has drawn, but never what
 * anybody offered. A board where the current best price is visible is a board
 * where everybody undercuts by a naira and nobody bids their real number.
 */
class BuyerRequestController extends Controller
{
    public function __construct(
        private readonly BuyerRequestService $requests,
        private readonly OfferService $offers,
    ) {}

    public function index(Request $request): Response
    {
        $query = BuyerRequest::query()
            ->publiclyVisible()
            ->with(['category', 'buyer.profile'])
            ->withCount('offers');

        if ($slug = $request->string('category')->toString()) {
            $category = Category::query()->where('slug', $slug)->first();

            if ($category !== null) {
                // The whole branch, not just the leaf: somebody browsing
                // "Poultry" wants everything under it.
                $query->whereIn('category_id', [$category->id, ...$category->descendantIds()]);
            }
        }

        if ($state = $request->string('state')->toString()) {
            $query->where('delivery_state', $state);
        }

        if ($request->boolean('open_only', true)) {
            $query->where('status', BuyerRequestStatus::Open);
        }

        $results = $query
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [BuyerRequestStatus::Open->value])
            ->latest('approved_at')
            ->paginate(12)
            ->withQueryString();

        return Inertia::render('Requests/Index', [
            'requests' => collect($results->items())
                ->map(fn (BuyerRequest $item): array => $this->card($item))
                ->all(),
            'pagination' => [
                'links' => $results->linkCollection()->toArray(),
                'from' => $results->firstItem(),
                'to' => $results->lastItem(),
                'total' => $results->total(),
            ],
            'filters' => [
                'category' => $request->string('category')->toString() ?: null,
                'state' => $request->string('state')->toString() ?: null,
                'open_only' => $request->boolean('open_only', true),
            ],
            'categories' => Category::query()
                ->roots()
                ->active()
                ->ordered()
                ->get(['id', 'name', 'slug'])
                ->map(fn (Category $c): array => ['value' => $c->slug, 'label' => $c->name])
                ->all(),
            'states' => Nigeria::states(),
        ]);
    }

    public function show(Request $request, BuyerRequest $buyerRequest): Response|RedirectResponse
    {
        if (! $buyerRequest->status->isPublic()) {
            // The author can always see their own, whatever state it is in.
            if ($request->user()?->getKey() !== $buyerRequest->user_id) {
                abort(404);
            }

            return redirect()->route('requests.manage', $buyerRequest->slug);
        }

        $buyerRequest->loadCount('offers');
        $buyerRequest->load(['category', 'buyer.profile']);

        $seller = $request->user()?->activeSellerProfile();

        return Inertia::render('Requests/Show', [
            'request' => [
                ...$this->card($buyerRequest),
                'description' => $buyerRequest->description,
                'images' => $buyerRequest->imageUrls(),
                'accepts_partial' => $buyerRequest->accepts_partial_fulfilment,
                'needed_by' => $buyerRequest->needed_by?->format('j M Y'),
                'closes_at' => $buyerRequest->expires_at?->format('j M Y'),
            ],
            // What the seller is allowed to do, worked out on the server so the
            // form cannot be talked into appearing.
            'seller' => $seller === null ? null : [
                'name' => $seller->business_name,
                'may_offer' => $this->sellerMayOffer($buyerRequest, $seller),
                'reason' => $this->sellerBlockedReason($buyerRequest, $seller),
                'existing_offer' => ($mine = $this->offers->openOfferFromSeller($buyerRequest, $seller)) === null
                    ? null
                    : [
                        'quantity' => $mine->quantity,
                        'unit_price' => $mine->unitPrice(),
                        'total' => $mine->totalPrice(),
                        'expires_at' => $mine->expires_at?->format('j M, H:i'),
                    ],
            ],
            'isMine' => $request->user()?->getKey() === $buyerRequest->user_id,
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Requests/Create', [
            'categories' => $this->categoryOptions(),
            'states' => Nigeria::states(),
            'units' => UnitOfMeasure::options(),
            'profile' => [
                'state' => $request->user()->profile?->state,
                'lga' => $request->user()->profile?->lga,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:8', 'max:160'],
            'description' => ['required', 'string', 'min:20', 'max:4000'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'unit' => ['required', 'string', 'max:32'],
            'budget_min' => ['nullable', 'numeric', 'min:0'],
            'budget_max' => ['nullable', 'numeric', 'min:0', 'gte:budget_min'],
            'delivery_state' => ['required', 'string', Rule::in(Nigeria::states())],
            'delivery_lga' => ['required', 'string', 'max:96'],
            'needed_by' => ['nullable', 'date', 'after:today'],
            'accepts_partial_fulfilment' => ['boolean'],
            'images' => ['nullable', 'array', 'max:5'],
            'images.*' => ['image', 'max:5120'],
        ]);

        $paths = collect($request->file('images') ?? [])
            ->map(fn ($file): string => $file->store('requests', 'public'))
            ->all();

        $buyerRequest = new BuyerRequest($validated);
        $buyerRequest->forceFill([
            'user_id' => $request->user()->getKey(),
            'budget_min_kobo' => isset($validated['budget_min']) ? Money::toKobo($validated['budget_min']) : null,
            'budget_max_kobo' => isset($validated['budget_max']) ? Money::toKobo($validated['budget_max']) : null,
            'images' => $paths === [] ? null : $paths,
            'status' => BuyerRequestStatus::PendingApproval,
        ])->save();

        return redirect()
            ->route('requests.mine')
            ->with('success', __('Thank you. Somebody will check it over and it usually goes up the same day.'));
    }

    /**
     * The buyer's own requests.
     */
    public function mine(Request $request): Response
    {
        $requests = BuyerRequest::query()
            ->ownedBy($request->user())
            ->with('category')
            ->withCount('offers')
            ->latest()
            ->paginate(10);

        return Inertia::render('Requests/Mine', [
            'requests' => collect($requests->items())->map(fn (BuyerRequest $item): array => [
                ...$this->card($item),
                'rejection_reason' => $item->rejection_reason,
                'live_offer_count' => $item->offers()->open()->count(),
            ])->all(),
            'pagination' => [
                'links' => $requests->linkCollection()->toArray(),
                'from' => $requests->firstItem(),
                'to' => $requests->lastItem(),
                'total' => $requests->total(),
            ],
        ]);
    }

    /**
     * One of the buyer's own requests, with every offer laid out side by side.
     */
    public function manage(Request $request, BuyerRequest $buyerRequest): Response
    {
        Gate::authorize('manage', $buyerRequest);

        $buyerRequest->load(['category', 'offers.seller.user', 'offers.initiator']);

        return Inertia::render('Requests/Manage', [
            'request' => [
                ...$this->card($buyerRequest),
                'description' => $buyerRequest->description,
                'images' => $buyerRequest->imageUrls(),
                'rejection_reason' => $buyerRequest->rejection_reason,
                'closes_at' => $buyerRequest->expires_at?->format('j M Y, H:i'),
                'can_close' => ! $buyerRequest->status->isFinished(),
            ],
            'offers' => $buyerRequest->offers
                ->sortBy('total_price_kobo')
                ->values()
                ->map(fn (Offer $offer): array => [
                    'id' => $offer->id,
                    'seller' => $offer->seller?->business_name ?? __('A seller'),
                    'seller_slug' => $offer->seller?->slug,
                    'location' => $offer->seller?->location(),
                    'quantity' => $offer->quantity,
                    'unit_price' => $offer->unitPrice(),
                    'unit_price_kobo' => $offer->unit_price_kobo,
                    'total' => $offer->totalPrice(),
                    'total_kobo' => $offer->total_price_kobo,
                    'delivery_days' => $offer->delivery_days,
                    'message' => $offer->message,
                    'status' => $offer->status->value,
                    'status_label' => $offer->status->label(),
                    'is_open' => $offer->isOpen(),
                    'expires_at' => $offer->expires_at?->format('j M, H:i'),
                    'round' => $offer->round(),
                    'covers_all' => $offer->quantity >= $buyerRequest->quantity,
                ])->all(),
        ]);
    }

    public function close(Request $request, BuyerRequest $buyerRequest): RedirectResponse
    {
        Gate::authorize('manage', $buyerRequest);

        try {
            $this->requests->close($buyerRequest, $request->user());
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Closed. Any sellers still waiting have been told.'));
    }

    /**
     * A seller answers a request.
     */
    public function offer(Request $request, BuyerRequest $buyerRequest): RedirectResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'unit_price' => ['required', 'numeric', 'min:1'],
            'delivery_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'message' => ['nullable', 'string', 'max:1000'],
        ], [], ['unit_price' => __('your price')]);

        try {
            $this->offers->offerOnRequest(
                $buyerRequest,
                $request->user()->activeSellerProfile(),
                (int) $validated['quantity'],
                Money::toKobo($validated['unit_price']),
                $validated['message'] ?? null,
                isset($validated['delivery_days']) ? (int) $validated['delivery_days'] : null,
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Your offer is with the buyer.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function card(BuyerRequest $item): array
    {
        return [
            'slug' => $item->slug,
            'reference' => $item->reference,
            'title' => $item->title,
            'category' => $item->category?->name,
            'quantity' => $item->quantity,
            'unit' => $item->unit,
            'budget' => $item->budgetLabel(),
            'location' => $item->location(),
            'state' => $item->delivery_state,
            'status' => $item->status->value,
            'status_label' => $item->status->label(),
            'offer_count' => $item->offers_count ?? $item->offers()->count(),
            'posted_at' => $item->approved_at?->diffForHumans() ?? $item->created_at->diffForHumans(),
            'buyer' => $item->buyer?->displayName(),
            'accepts_offers' => $item->isOpen(),
        ];
    }

    private function sellerMayOffer(BuyerRequest $request, $seller): bool
    {
        try {
            $this->offers->assertSellerMayAnswer($request, $seller);
        } catch (RuntimeException) {
            return false;
        }

        return $request->isOpen()
            && $request->user_id !== $seller->user_id
            && $this->offers->openOfferFromSeller($request, $seller) === null;
    }

    private function sellerBlockedReason(BuyerRequest $request, $seller): ?string
    {
        try {
            $this->offers->assertSellerMayAnswer($request, $seller);
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }

        return match (true) {
            $request->user_id === $seller->user_id => __('This is your own request.'),
            ! $request->isOpen() => __('This request is no longer taking offers.'),
            $this->offers->openOfferFromSeller($request, $seller) !== null => __('You already have an offer waiting on this request.'),
            default => null,
        };
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    private function categoryOptions(): array
    {
        return Category::query()
            ->active()
            ->with('parent')
            ->orderBy('name')
            ->get()
            ->map(fn (Category $c): array => ['value' => $c->id, 'label' => $c->pathName()])
            ->sortBy('label')
            ->values()
            ->all();
    }
}
