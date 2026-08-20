<?php

namespace App\Enums;

/**
 * Why somebody is disputing.
 *
 * Categories rather than free text alone, so an administrator can see at a
 * glance which sellers keep producing the same complaint — and free text as
 * well, because no list survives contact with a live-animal marketplace.
 *
 * The list is split by what is being argued about. Offering a client "the
 * animals arrived dead" for a mentoring engagement, or a mentor "it was damaged
 * on the way", makes the category useless and the platform look like it was
 * built for something else.
 */
enum DisputeReason: string
{
    case NotDelivered = 'not_delivered';
    case WrongItem = 'wrong_item';
    case QuantityShort = 'quantity_short';
    case QualityPoor = 'quality_poor';
    case ArrivedDeadOrSick = 'arrived_dead_or_sick';
    case ArrivedSpoiled = 'arrived_spoiled';
    case DamagedInTransit = 'damaged_in_transit';

    /*
     * Mentorship. Some are the client's complaint and some are the mentor's:
     * either party may raise a dispute on an engagement.
     */
    case NoContact = 'no_contact';
    case SessionsNotHeld = 'sessions_not_held';
    case NotAsAgreed = 'not_as_agreed';
    case ClientUnreachable = 'client_unreachable';

    case Other = 'other';

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
            self::NotDelivered => __('It never arrived'),
            self::WrongItem => __('The wrong thing arrived'),
            self::QuantityShort => __('Less arrived than I paid for'),
            self::QualityPoor => __('The quality is not what was described'),
            self::ArrivedDeadOrSick => __('The animals arrived dead or sick'),
            self::ArrivedSpoiled => __('It arrived spoiled'),
            self::DamagedInTransit => __('It was damaged on the way'),
            self::NoContact => __('They never made contact'),
            self::SessionsNotHeld => __('The sessions did not happen'),
            self::NotAsAgreed => __('The work was not what we agreed'),
            self::ClientUnreachable => __('The client stopped answering'),
            self::Other => __('Something else'),
        };
    }

    /**
     * Whether this reason makes sense for a mentoring engagement.
     */
    public function suitsMentorship(): bool
    {
        return in_array($this, self::mentorshipCases(), true);
    }

    /**
     * @return array<int, self>
     */
    public static function mentorshipCases(): array
    {
        return [
            self::NoContact,
            self::SessionsNotHeld,
            self::NotAsAgreed,
            self::ClientUnreachable,
            self::NotDelivered,
            self::Other,
        ];
    }

    /**
     * @return array<int, self>
     */
    public static function marketplaceCases(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $reason): bool => ! in_array($reason, [
                self::NoContact,
                self::SessionsNotHeld,
                self::NotAsAgreed,
                self::ClientUnreachable,
            ], true),
        ));
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $reason): array => [$reason->value => $reason->label()])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function mentorshipOptions(): array
    {
        return collect(self::mentorshipCases())
            ->mapWithKeys(fn (self $reason): array => [$reason->value => $reason->label()])
            ->all();
    }
}
