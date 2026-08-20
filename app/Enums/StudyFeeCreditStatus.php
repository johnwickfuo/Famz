<?php

namespace App\Enums;

/**
 * What happened to the study fee once the project was decided.
 *
 * This is an audit trail, not a workflow. The fee is charged before the work,
 * and if the client signs the project the company usually knocks it off the
 * first invoice — but that happens offline, in a conversation, possibly months
 * later. Six months after that, somebody will ask whether the fee was credited,
 * and the only defensible answer is a row saying who decided, when, and why.
 *
 * `uncredited` is therefore not a failure state. It is the honest default for a
 * fee that was paid for work that was done.
 */
enum StudyFeeCreditStatus: string
{
    case Uncredited = 'uncredited';
    case Credited = 'credited';
    case Expired = 'expired';
    case Refunded = 'refunded';

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
            self::Uncredited => __('Not credited'),
            self::Credited => __('Credited to the project'),
            self::Expired => __('Credit window passed'),
            self::Refunded => __('Refunded'),
        };
    }

    /**
     * The same fact in a table cell.
     *
     * The long labels are written for the record page, where somebody is
     * reading one fee and wants the sentence. In a queue they are just wide,
     * and a column that pushes the row's actions off the screen has stopped
     * being an audit trail and become an obstacle.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Uncredited => __('Not credited'),
            self::Credited => __('Credited'),
            self::Expired => __('Lapsed'),
            self::Refunded => __('Refunded'),
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Uncredited => __('Paid for the study. Nothing has been knocked off anything.'),
            self::Credited => __('Taken off the project price after the client signed.'),
            self::Expired => __('The client did not go ahead in time, so the fee stands.'),
            self::Refunded => __('Given back.'),
        };
    }

    /**
     * Whether a note explaining the decision is required.
     *
     * Leaving a fee uncredited needs no explanation — that is simply what was
     * paid for. Every other transition is somebody deciding to move money, and
     * a decision with no reason recorded is the one that starts an argument.
     */
    public function needsNote(): bool
    {
        return $this !== self::Uncredited;
    }

    /**
     * Whether the company still holds this money.
     *
     * A refunded fee has gone back; everything else is still revenue, whether
     * or not it was later discounted off a project invoice raised elsewhere.
     */
    public function isRetained(): bool
    {
        return $this !== self::Refunded;
    }

    public function badgeTone(): string
    {
        return match ($this) {
            self::Uncredited => 'muted',
            self::Credited => 'success',
            self::Expired => 'warning',
            self::Refunded => 'danger',
        };
    }

    public function filamentColour(): string
    {
        return match ($this) {
            self::Uncredited => 'gray',
            self::Credited => 'success',
            self::Expired => 'warning',
            self::Refunded => 'danger',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])
            ->all();
    }
}
