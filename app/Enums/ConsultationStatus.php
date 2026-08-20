<?php

namespace App\Enums;

/**
 * Where a consultation has got to.
 *
 * The order is the flow: somebody books, we ring them, we agree a price, they
 * pay, we do the work, we write it up. Nothing is priced until after the
 * conversation, which is why `quoted` sits between `contacted` and any mention
 * of money.
 */
enum ConsultationStatus: string
{
    case Submitted = 'submitted';
    case Contacted = 'contacted';
    case Quoted = 'quoted';
    case AwaitingPayment = 'awaiting_payment';
    case Paid = 'paid';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

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
            self::Submitted => __('Waiting for us to call'),
            self::Contacted => __('We have spoken'),
            self::Quoted => __('Price agreed'),
            self::AwaitingPayment => __('Waiting for payment'),
            self::Paid => __('Paid'),
            self::InProgress => __('Work under way'),
            self::Completed => __('Finished'),
            self::Cancelled => __('Cancelled'),
        };
    }

    /**
     * The same state from the company's side of the desk.
     */
    public function adminLabel(): string
    {
        return match ($this) {
            self::Submitted => __('Needs a call'),
            self::Contacted => __('Spoken to, not quoted'),
            default => $this->label(),
        };
    }

    /**
     * Whether the response clock is still running.
     *
     * It stops the moment somebody makes contact — the promise was to get back
     * to them, and that promise has then been kept whatever happens next.
     */
    public function awaitsFirstResponse(): bool
    {
        return $this === self::Submitted;
    }

    /**
     * Whether there is money outstanding on this.
     */
    public function awaitsPayment(): bool
    {
        return in_array($this, [self::Quoted, self::AwaitingPayment], true);
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    /**
     * Whether the work has been paid for and is real.
     */
    public function isLive(): bool
    {
        return in_array($this, [self::Paid, self::InProgress], true);
    }

    public function badgeTone(): string
    {
        return match ($this) {
            self::Submitted, self::Quoted, self::AwaitingPayment => 'pending',
            self::Contacted, self::Paid, self::InProgress => 'active',
            self::Completed => 'active',
            self::Cancelled => 'danger',
        };
    }

    public function filamentColour(): string
    {
        return match ($this) {
            self::Submitted => 'warning',
            self::Quoted, self::AwaitingPayment => 'warning',
            self::Contacted, self::Paid, self::InProgress, self::Completed => 'success',
            self::Cancelled => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status): array => [$status->value => $status->label()])
            ->all();
    }
}
