<?php

namespace App\Enums;

/**
 * Where a quotation request stands.
 *
 * The important line in this enum is between `study_fee_pending` and
 * `study_fee_paid`: that is the gate. Preparing a farm proposal is days of
 * somebody's work — costing housing, equipment, stocking and labour against a
 * particular site — and doing it for everybody who fills in a form is how the
 * service dies. The fee is what turns an enquiry into a job.
 */
enum QuotationRequestStatus: string
{
    case Submitted = 'submitted';
    case StudyFeePending = 'study_fee_pending';
    case StudyFeePaid = 'study_fee_paid';
    case InPreparation = 'in_preparation';
    case QuoteSent = 'quote_sent';
    case AcceptedOffline = 'accepted_offline';
    case Declined = 'declined';
    case Expired = 'expired';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * What the client is told.
     */
    public function label(): string
    {
        return match ($this) {
            self::Submitted => __('Received'),
            self::StudyFeePending => __('Study fee to pay'),
            self::StudyFeePaid => __('With our team'),
            self::InPreparation => __('Being prepared'),
            self::QuoteSent => __('Proposal sent'),
            self::AcceptedOffline => __('Going ahead'),
            self::Declined => __('Not going ahead'),
            self::Expired => __('Lapsed'),
        };
    }

    /**
     * What the company calls it internally, which is not always the same thing.
     *
     * "Waiting on the fee" is a fact about the client; "Study fee to pay" is an
     * instruction to them. Each side reads the one that tells it what to do.
     */
    public function adminLabel(): string
    {
        return match ($this) {
            self::Submitted => __('Just in'),
            self::StudyFeePending => __('Waiting on the fee'),
            self::StudyFeePaid => __('Ready to start'),
            self::InPreparation => __('Being written'),
            self::QuoteSent => __('Sent — waiting on them'),
            self::AcceptedOffline => __('Won'),
            self::Declined => __('Lost'),
            self::Expired => __('Lapsed'),
        };
    }

    /**
     * The fee has cleared, so somebody owes this client a proposal.
     *
     * This is the whole admin work queue, and it is deliberately not "every
     * request": a request whose fee has not cleared is not work.
     */
    public function isPaidWork(): bool
    {
        return in_array($this, [self::StudyFeePaid, self::InPreparation], true);
    }

    public function awaitsStudyFee(): bool
    {
        return in_array($this, [self::Submitted, self::StudyFeePending], true);
    }

    /**
     * Nothing more will happen on this one without somebody starting again.
     */
    public function isClosed(): bool
    {
        return in_array($this, [self::AcceptedOffline, self::Declined, self::Expired], true);
    }

    public function badgeTone(): string
    {
        return match ($this) {
            self::Submitted, self::StudyFeePending => 'warning',
            self::StudyFeePaid, self::InPreparation => 'info',
            self::QuoteSent => 'primary',
            self::AcceptedOffline => 'success',
            self::Declined, self::Expired => 'muted',
        };
    }

    public function filamentColour(): string
    {
        return match ($this) {
            self::Submitted, self::StudyFeePending => 'warning',
            self::StudyFeePaid, self::InPreparation => 'info',
            self::QuoteSent => 'primary',
            self::AcceptedOffline => 'success',
            self::Declined, self::Expired => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->adminLabel()])
            ->all();
    }
}
