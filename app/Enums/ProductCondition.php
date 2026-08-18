<?php

namespace App\Enums;

enum ProductCondition: string
{
    case New = 'new';
    case Used = 'used';
    case Refurbished = 'refurbished';

    /**
     * Live birds and animals are neither new nor used — a farmer buying
     * point-of-lay pullets is buying a living thing, and the catalogue should
     * say so rather than forcing it into a hardware vocabulary.
     */
    case Live = 'live';

    case Fresh = 'fresh';

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
            self::New => __('New'),
            self::Used => __('Used'),
            self::Refurbished => __('Refurbished'),
            self::Live => __('Live'),
            self::Fresh => __('Fresh'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $condition): array => [$condition->value => $condition->label()])
            ->all();
    }
}
