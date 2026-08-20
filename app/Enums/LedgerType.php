<?php

namespace App\Enums;

enum LedgerType: string
{
    /** A seller's payout for goods sold, net of commission. */
    case Sale = 'sale';

    /** The platform's cut of a sale. */
    case Commission = 'commission';

    /** Money returned to a buyer. Recorded for audit; never spendable. */
    case Refund = 'refund';

    /** Money paid out of a wallet to a bank account. */
    case Withdrawal = 'withdrawal';

    /** A manual correction by an administrator. */
    case Adjustment = 'adjustment';

    /** A mentor's fee for a consultation, from a later phase. */
    case MentorshipEarning = 'mentorship_earning';

    /** Cancels an entry that had already been released. */
    case Reversal = 'reversal';

    /**
     * A course sold by the company itself.
     *
     * Its own type rather than Commission, because it is not a cut of somebody
     * else's sale — the whole amount is the platform's, there is no seller and
     * nothing is held. Folding it into commission would make the marketplace
     * look twice as profitable as it is.
     */
    case CourseSale = 'course_sale';

    /**
     * A consultation the company was paid for directly.
     *
     * Same reasoning as a course sale: the company did the work, there is
     * nobody to split with, and nothing is held. Kept apart from CourseSale
     * because "how much did training earn" and "how much did advice earn" are
     * two questions the business will want answered separately.
     */
    case ConsultationFee = 'consultation_fee';

    /**
     * The fee charged before a farm proposal is written.
     *
     * Kept apart from ConsultationFee because it answers a different question.
     * A consultation fee is paid for advice that was given; a study fee is paid
     * for a proposal that may or may not turn into a project, and the business
     * will want to know what proportion of them did. It is also the only
     * revenue line that can later be discounted against work billed elsewhere,
     * which is exactly why it needs to be countable on its own.
     */
    case QuotationStudyFee = 'quotation_study_fee';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Sale => __('Sale'),
            self::Commission => __('Commission'),
            self::Refund => __('Refund'),
            self::Withdrawal => __('Withdrawal'),
            self::Adjustment => __('Adjustment'),
            self::MentorshipEarning => __('Mentorship'),
            self::Reversal => __('Reversal'),
            self::CourseSale => __('Course sale'),
            self::ConsultationFee => __('Consultation'),
            self::QuotationStudyFee => __('Study fee'),
        };
    }

    /**
     * The types that count as somebody having earned something, for lifetime
     * earnings.
     */
    public function isEarning(): bool
    {
        return in_array($this, [self::Sale, self::MentorshipEarning], true);
    }
}
