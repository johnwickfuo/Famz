<?php

namespace App\Enums;

enum BusinessType: string
{
    case SoleProprietor = 'sole_proprietor';
    case RegisteredCompany = 'registered_company';
    case Cooperative = 'cooperative';
    case Farm = 'farm';
    case Distributor = 'distributor';

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
            self::SoleProprietor => __('Sole proprietor / individual trader'),
            self::RegisteredCompany => __('Registered company'),
            self::Cooperative => __('Cooperative society'),
            self::Farm => __('Farm'),
            self::Distributor => __('Distributor / wholesaler'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $type): array => [$type->value => $type->label()])
            ->all();
    }
}
