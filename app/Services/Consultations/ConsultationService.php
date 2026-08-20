<?php

namespace App\Services\Consultations;

use App\Enums\ConsultationStatus;
use App\Enums\ConsultationTier;
use App\Models\Consultation;
use App\Models\ConsultationFollowup;
use App\Models\ConsultationReport;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Booking a consultation with the company, and everything that follows.
 *
 * The flow is book first, pay later, and that ordering is the product rather
 * than an implementation detail: a farmer with dying birds will not stop to
 * agree a price, and the company cannot quote sensibly before it knows what is
 * actually wrong. So a booking asks for four things, and the money is settled
 * after a phone call.
 */
class ConsultationService
{
    public function __construct(private readonly ResponseClock $clock) {}

    /**
     * Take a booking.
     *
     * @param  array<string, mixed>  $data
     */
    public function book(array $data, ?User $user = null): Consultation
    {
        $consultation = new Consultation;

        $tier = ConsultationTier::from($data['tier']);

        $consultation->forceFill([
            'user_id' => $user?->getKey(),

            'full_name' => trim($data['full_name']),
            'phone' => trim($data['phone']),
            'email' => mb_strtolower(trim($data['email'])),
            'tier' => $tier,

            // Everything below is optional. A booking with none of it is a
            // perfectly good booking.
            'category' => $data['category'] ?? null,
            'situation' => $this->cleanOrNull($data['situation'] ?? null),
            'farm_type' => $this->cleanOrNull($data['farm_type'] ?? null),
            'animal_type' => $this->cleanOrNull($data['animal_type'] ?? null),
            'flock_size' => isset($data['flock_size']) && $data['flock_size'] !== ''
                ? (int) $data['flock_size']
                : null,
            'state' => $this->cleanOrNull($data['state'] ?? null),
            'lga' => $this->cleanOrNull($data['lga'] ?? null),
            'attachments' => ($data['attachments'] ?? []) === [] ? null : array_values($data['attachments']),

            'status' => ConsultationStatus::Submitted,

            /*
             * The promise, fixed at submission from the tier and the settings
             * in force right now. Stored rather than derived: changing the
             * standard window next month must not retroactively make last
             * week's bookings late, or — worse — on time.
             */
            'response_due_at' => $this->clock->dueAt($tier),
        ])->save();

        return $consultation->refresh();
    }

    /**
     * Attach any guest bookings this person made before they registered.
     *
     * Matched on email, which is the only thing a guest booking and a new
     * account reliably share. Called when somebody registers and when they
     * verify, so a booking made at 2am from a phone turns up in the dashboard
     * of the account made the next morning.
     *
     * @return int how many were claimed
     */
    public function claimFor(User $user): int
    {
        if (blank($user->email)) {
            return 0;
        }

        return Consultation::query()
            ->whereNull('user_id')
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($user->email))])
            ->update(['user_id' => $user->getKey(), 'updated_at' => now()]);
    }

    /**
     * Somebody rang them.
     *
     * This is what stops the response clock, so it is a deliberate act with a
     * timestamp rather than a side effect of opening the record. Recorded once:
     * `first_responded_at` is the first response, and a second call does not
     * make the first one earlier.
     */
    public function recordContact(Consultation $consultation, User $admin, ?string $note = null): Consultation
    {
        if ($consultation->status->isFinished()) {
            throw new RuntimeException(__('This consultation is closed.'));
        }

        $consultation->forceFill([
            'first_responded_at' => $consultation->first_responded_at ?? now(),
            'status' => $consultation->status === ConsultationStatus::Submitted
                ? ConsultationStatus::Contacted
                : $consultation->status,
            'admin_notes' => $this->appendNote($consultation->admin_notes, $note, $admin),
        ])->save();

        return $consultation->refresh();
    }

    /**
     * Agree a price.
     *
     * There is no price list. This is a number a person decided after a
     * conversation, so who decided it and when are recorded beside it.
     */
    public function quote(Consultation $consultation, User $admin, int $amountKobo, ?string $note = null): Consultation
    {
        if ($amountKobo < 1) {
            throw new RuntimeException(__('A quote has to be more than nothing.'));
        }

        if ($consultation->isPaid()) {
            throw new RuntimeException(__('This consultation has already been paid for.'));
        }

        if ($consultation->status->isFinished()) {
            throw new RuntimeException(__('This consultation is closed.'));
        }

        $consultation->forceFill([
            'quoted_amount_kobo' => $amountKobo,
            'quote_note' => $this->cleanOrNull($note),
            'quoted_by' => $admin->getKey(),
            'quoted_at' => now(),
            'status' => ConsultationStatus::Quoted,

            // Quoting is also a response. An administrator who quotes without
            // pressing "record contact" has plainly been in touch.
            'first_responded_at' => $consultation->first_responded_at ?? now(),
        ])->save();

        return $consultation->refresh();
    }

    /**
     * The money cleared.
     *
     * Called from the payment layer once a webhook has been verified, never
     * from a controller and never on the strength of a redirect back from a
     * gateway. Idempotent: a webhook delivered three times moves this once.
     */
    public function markPaid(Consultation $consultation, ?string $orderReference = null): bool
    {
        $paid = DB::transaction(function () use ($consultation, $orderReference): bool {
            $locked = Consultation::query()->whereKey($consultation->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->isPaid()) {
                return false;
            }

            $locked->forceFill([
                'paid_at' => now(),
                'order_reference' => $orderReference ?? $locked->order_reference,
                'status' => ConsultationStatus::Paid,
            ])->save();

            return true;
        });

        // The write goes through a locked copy, so the caller's instance would
        // otherwise still read as unpaid — and the next thing anybody does with
        // it is start the work.
        $consultation->refresh();

        return $paid;
    }

    public function start(Consultation $consultation): Consultation
    {
        if (! $consultation->isPaid()) {
            throw new RuntimeException(__('Nothing has been paid for on this consultation.'));
        }

        $consultation->forceFill(['status' => ConsultationStatus::InProgress])->save();

        return $consultation->refresh();
    }

    public function complete(Consultation $consultation): Consultation
    {
        if ($consultation->status->isFinished()) {
            throw new RuntimeException(__('This consultation is already closed.'));
        }

        $consultation->forceFill([
            'status' => ConsultationStatus::Completed,
            'completed_at' => $consultation->completed_at ?? now(),
        ])->save();

        return $consultation->refresh();
    }

    public function cancel(Consultation $consultation, ?string $reason = null, ?User $admin = null): Consultation
    {
        if ($consultation->isPaid()) {
            throw new RuntimeException(__('Money has changed hands. Refund it rather than cancelling.'));
        }

        $consultation->forceFill([
            'status' => ConsultationStatus::Cancelled,
            'cancelled_at' => now(),
            'admin_notes' => $this->appendNote($consultation->admin_notes, $reason, $admin),
        ])->save();

        return $consultation->refresh();
    }

    // -----------------------------------------------------------------------
    // Reports and follow-ups
    // -----------------------------------------------------------------------

    /**
     * Publish a report, which is the moment the client can read it.
     */
    public function publish(ConsultationReport $report): ConsultationReport
    {
        if (trim((string) $report->findings) === '' || trim((string) $report->recommendations) === '') {
            // Publishing an empty report tells the client something untrue
            // about the work that was done for them.
            throw new RuntimeException(__('A report needs findings and recommendations before it goes out.'));
        }

        $report->forceFill(['published_at' => $report->published_at ?? now()])->save();

        return $report->refresh();
    }

    public function unpublish(ConsultationReport $report): ConsultationReport
    {
        $report->forceFill(['published_at' => null])->save();

        return $report->refresh();
    }

    /**
     * Add to the follow-up thread.
     *
     * @param  array<int, string>  $attachments
     */
    public function followUp(
        Consultation $consultation,
        string $sender,
        string $message,
        ?User $author = null,
        array $attachments = [],
        bool $internal = false,
    ): ConsultationFollowup {
        if (trim($message) === '') {
            throw new RuntimeException(__('Write something first.'));
        }

        // The company can always write; the client only while the window is
        // open. An internal note is the company talking to itself, so it is
        // never blocked either.
        if ($sender === ConsultationFollowup::FROM_CLIENT && ! $consultation->followupsOpen()) {
            throw new RuntimeException(
                $consultation->completed_at === null
                    ? __('Follow-up opens once the consultation has been paid for.')
                    : __('The follow-up window on this consultation has closed.')
            );
        }

        $followup = new ConsultationFollowup;

        $followup->forceFill([
            'consultation_id' => $consultation->getKey(),
            'user_id' => $author?->getKey(),
            'sender' => $sender,
            'message' => trim($message),
            'attachments' => $attachments === [] ? null : array_values($attachments),
            'is_internal' => $internal,
        ])->save();

        return $followup->refresh();
    }

    /**
     * What the booking form promises, straight from the settings.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tierPromises(): array
    {
        return collect(ConsultationTier::cases())
            ->map(fn (ConsultationTier $tier): array => [
                'value' => $tier->value,
                'label' => $tier->label(),
                'blurb' => $tier->blurb(),
                'promise' => $this->clock->promiseFor($tier),
                'hours' => $tier->hours(),
                'working_hours_only' => $tier->countsWorkingHoursOnly(),
                'is_premium' => $tier->isUrgent(),
            ])
            ->all();
    }

    private function cleanOrNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Notes are appended with a name and a date rather than overwritten: the
     * next administrator to pick this up needs the history, not the latest
     * sentence.
     */
    private function appendNote(?string $existing, ?string $note, ?User $author): ?string
    {
        $note = trim((string) $note);

        if ($note === '') {
            return $existing;
        }

        $line = sprintf(
            '[%s · %s] %s',
            now()->format('j M Y, H:i'),
            $author?->displayName() ?? __('System'),
            $note,
        );

        return trim(($existing ? $existing."\n\n" : '').$line);
    }

    /**
     * A quote in words, for an email or a dashboard.
     */
    public function quoteLabel(Consultation $consultation): ?string
    {
        return $consultation->quoted_amount_kobo === null
            ? null
            : Money::fromKobo($consultation->quoted_amount_kobo);
    }
}
