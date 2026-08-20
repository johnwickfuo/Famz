<?php

namespace App\Services\Quotations;

use App\Documents\QuotationProposalDocument;
use App\Enums\QuotationRequestStatus;
use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Models\QuotationRequest;
use App\Models\User;
use App\Services\Branding\BrandingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Writing, revising and sending a proposal.
 *
 * There is no pricing engine here and there is not meant to be one. What it
 * costs to put up a 20,000-bird layer house in Oyo depends on the site, the
 * season, who is supplying the galvanised sheet and what the client already
 * owns. A formula would produce a number nobody in the company could stand
 * behind on the phone.
 *
 * The one rule the code does enforce is that a sent proposal is immutable. A
 * revision is a new row at the next version number and the old one becomes
 * superseded, so the document a client is holding — and may have taken to a
 * bank — keeps saying exactly what it said.
 */
class QuotationService
{
    public const DISK = 'local';

    public const DIRECTORY = 'quotations';

    public function __construct(private readonly BrandingService $branding) {}

    /**
     * Start a draft for a request whose fee has cleared.
     *
     * Refuses on an unpaid fee rather than warning about it. The gate is the
     * product: without it, preparing proposals for everybody who fills in a
     * form is how the service stops being offered.
     */
    public function startDraft(QuotationRequest $request, User $admin, array $attributes = []): Quotation
    {
        if (! $request->studyFeePaid()) {
            throw new RuntimeException(__('The study fee has not been paid, so there is nothing to prepare yet.'));
        }

        if ($request->isClosed()) {
            throw new RuntimeException(__('This request is closed.'));
        }

        return DB::transaction(function () use ($request, $admin, $attributes): Quotation {
            $existingDraft = $request->quotations()
                ->where('status', QuotationStatus::Draft)
                ->lockForUpdate()
                ->first();

            // One draft at a time. Two people writing two drafts against one
            // request is not a version history, it is a mistake waiting to be
            // sent to a client.
            if ($existingDraft !== null) {
                return $existingDraft;
            }

            $quotation = new Quotation($attributes);

            $quotation->forceFill([
                'quotation_request_id' => $request->getKey(),
                'version' => $this->nextVersion($request),
                'title' => $attributes['title'] ?? $this->defaultTitle($request),
                'currency' => $request->currency,
                'prepared_by' => $admin->getKey(),
                'status' => QuotationStatus::Draft,
            ])->save();

            if ($request->status === QuotationRequestStatus::StudyFeePaid) {
                $request->forceFill(['status' => QuotationRequestStatus::InPreparation])->save();
            }

            return $quotation->refresh();
        });
    }

    /**
     * Open a revision of a sent proposal.
     *
     * Copies the previous version's prose and lines so an administrator is
     * changing a document rather than retyping one — the usual reason for a
     * revision is that two prices moved, not that everything was wrong.
     *
     * The previous version is NOT superseded here. It stays live until the
     * revision is actually sent, because a client should not be left holding a
     * proposal marked "replaced" by something that does not exist yet.
     */
    public function reviseFrom(Quotation $previous, User $admin): Quotation
    {
        $request = $previous->request;

        if ($request === null) {
            throw new RuntimeException(__('This quotation has no request behind it.'));
        }

        if ($previous->isDraft()) {
            throw new RuntimeException(__('That version has not been sent yet — edit it instead of revising it.'));
        }

        return DB::transaction(function () use ($previous, $request, $admin): Quotation {
            $existingDraft = $request->quotations()
                ->where('status', QuotationStatus::Draft)
                ->lockForUpdate()
                ->first();

            if ($existingDraft !== null) {
                return $existingDraft;
            }

            $revision = new Quotation;

            $revision->forceFill([
                'quotation_request_id' => $request->getKey(),
                'version' => $this->nextVersion($request),
                'title' => $previous->title,
                'executive_summary' => $previous->executive_summary,
                'scope_of_work' => $previous->scope_of_work,
                'assumptions' => $previous->assumptions,
                'exclusions' => $previous->exclusions,
                'timeline_description' => $previous->timeline_description,
                'payment_terms' => $previous->payment_terms,
                'contingency_percent' => $previous->contingency_percent,
                'currency' => $previous->currency,
                'prepared_by' => $admin->getKey(),
                'status' => QuotationStatus::Draft,
            ])->save();

            foreach ($previous->lineItems as $item) {
                $revision->lineItems()->create([
                    'section' => $item->section,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'unit_price_kobo' => $item->unit_price_kobo,
                    'sort_order' => $item->sort_order,
                ]);
            }

            $revision->load('lineItems')->recalculate()->save();

            $request->forceFill(['status' => QuotationRequestStatus::InPreparation])->save();

            return $revision->refresh();
        });
    }

    /**
     * Send a draft to the client.
     *
     * Three things happen here that cannot happen anywhere else: the validity
     * date is set from the send date, the company's name is frozen onto the
     * record, and the PDF is rendered and stored. After this the document is
     * immutable — the only way to change anything is a revision.
     */
    public function send(Quotation $quotation, ?User $admin = null): Quotation
    {
        if ($quotation->isSent()) {
            throw new RuntimeException(__('This version has already been sent.'));
        }

        if ($quotation->status !== QuotationStatus::Draft) {
            throw new RuntimeException(__('Only a draft can be sent.'));
        }

        $quotation->load('lineItems');

        if ($quotation->lineItems->isEmpty()) {
            throw new RuntimeException(__('A proposal with no priced lines is not a proposal.'));
        }

        $request = $quotation->request;

        if ($request === null) {
            throw new RuntimeException(__('This quotation has no request behind it.'));
        }

        return DB::transaction(function () use ($quotation, $request, $admin): Quotation {
            $sentAt = now();
            $days = max(1, (int) settings('quote_validity_days', 30));

            $quotation->recalculate()->forceFill([
                'status' => QuotationStatus::Sent,
                'sent_at' => $sentAt,
                'valid_until' => $sentAt->copy()->addDays($days)->toDateString(),
                /*
                 * The company as it is today, written down.
                 *
                 * Branding is read live everywhere else, and that is right for
                 * a website. It is wrong for a document somebody already has:
                 * reissuing March's proposal must produce March's proposal, not
                 * one wearing this quarter's name.
                 */
                'issuer_name' => $this->branding->name(),
                'prepared_by' => $quotation->prepared_by ?? $admin?->getKey(),
            ])->save();

            // Every earlier version steps aside, now that there is something
            // real to step aside for.
            $request->quotations()
                ->whereKeyNot($quotation->getKey())
                ->whereIn('status', [QuotationStatus::Sent, QuotationStatus::Expired])
                ->update(['status' => QuotationStatus::Superseded, 'updated_at' => now()]);

            $request->forceFill(['status' => QuotationRequestStatus::QuoteSent])->save();

            $this->storePdf($quotation->refresh());

            return $quotation->refresh();
        });
    }

    /**
     * Render the proposal and keep the bytes.
     *
     * Stored rather than re-rendered on demand so that a copy downloaded in
     * December is the same document as the one downloaded in March, even after
     * the templates have moved on.
     */
    public function storePdf(Quotation $quotation): string
    {
        $document = new QuotationProposalDocument($quotation);
        $path = self::DIRECTORY.'/'.$quotation->getKey().'-v'.$quotation->version.'.pdf';

        Storage::disk(self::DISK)->put($path, $document->output());

        $quotation->forceFill(['pdf_path' => $path])->save();

        return $path;
    }

    /**
     * The stored PDF, rendering it once if it is somehow missing.
     */
    public function pdfContents(Quotation $quotation): string
    {
        $path = $quotation->pdf_path;

        if ($path !== null && Storage::disk(self::DISK)->exists($path)) {
            return Storage::disk(self::DISK)->get($path);
        }

        // A proposal the client cannot download because a file went missing is
        // worse than one rendered fresh from a record that has not changed.
        return (new QuotationProposalDocument($quotation))->output();
    }

    /**
     * Mark lapsed proposals, returning how many moved.
     *
     * Run on a schedule. Nigerian input prices move fast enough that a proposal
     * nobody withdrew is a proposal the company is still standing behind at
     * last quarter's cement price.
     *
     * @return array<int, Quotation>
     */
    public function expireLapsed(): array
    {
        $lapsed = Quotation::query()->lapsed()->with('request.user')->get();

        foreach ($lapsed as $quotation) {
            DB::transaction(function () use ($quotation): void {
                $quotation->forceFill(['status' => QuotationStatus::Expired])->save();

                $request = $quotation->request;

                // Only a request still sitting at "sent" follows the proposal
                // into expiry. One already won or lost keeps its outcome —
                // a signed project does not lapse because its paperwork did.
                if ($request !== null && $request->status === QuotationRequestStatus::QuoteSent) {
                    $request->forceFill(['status' => QuotationRequestStatus::Expired])->save();
                }
            });
        }

        return $lapsed->all();
    }

    /**
     * Close a request for reporting.
     *
     * Deliberately the end of the line. Acceptance happens offline, over the
     * phone and on paper, and there is no project tracking after this: the
     * company runs the build with the client, not through this application.
     */
    public function close(
        QuotationRequest $request,
        QuotationRequestStatus $outcome,
        User $admin,
        ?string $note = null,
    ): QuotationRequest {
        if (! in_array($outcome, [QuotationRequestStatus::AcceptedOffline, QuotationRequestStatus::Declined], true)) {
            throw new RuntimeException(__('A request is closed as either accepted or declined.'));
        }

        $request->forceFill([
            'status' => $outcome,
            'outcome_note' => trim((string) $note) ?: null,
            'closed_at' => now(),
            'admin_notes' => $this->appendNote($request->admin_notes, $note, $admin),
        ])->save();

        return $request->refresh();
    }

    /**
     * Reopen a request somebody closed by mistake.
     */
    public function reopen(QuotationRequest $request): QuotationRequest
    {
        if (! $request->isClosed()) {
            return $request;
        }

        $request->forceFill([
            'status' => $request->quotations()->where('status', QuotationStatus::Sent)->exists()
                ? QuotationRequestStatus::QuoteSent
                : ($request->studyFeePaid()
                    ? QuotationRequestStatus::StudyFeePaid
                    : QuotationRequestStatus::StudyFeePending),
            'closed_at' => null,
        ])->save();

        return $request->refresh();
    }

    // -----------------------------------------------------------------------

    private function nextVersion(QuotationRequest $request): int
    {
        return ((int) $request->quotations()->max('version')) + 1;
    }

    private function defaultTitle(QuotationRequest $request): string
    {
        return __(':type — :farm', [
            'type' => $request->project_type->label(),
            'farm' => $request->farm_type,
        ]);
    }

    /**
     * Append a dated, attributed note without losing what was there.
     */
    private function appendNote(?string $existing, ?string $note, User $admin): ?string
    {
        $note = trim((string) $note);

        if ($note === '') {
            return $existing;
        }

        $stamped = sprintf('[%s · %s] %s', now()->format('j M Y, H:i'), $admin->name, $note);

        return trim(($existing ? $existing."\n\n" : '').$stamped);
    }
}
