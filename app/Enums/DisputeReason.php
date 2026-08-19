<?php

namespace App\Enums;

/**
 * Why a buyer is disputing.
 *
 * Categories rather than free text alone, so an administrator can see at a
 * glance which sellers keep producing the same complaint — and free text as
 * well, because no list survives contact with a live-animal marketplace.
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
            self::Other => __('Something else'),
        };
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
}
