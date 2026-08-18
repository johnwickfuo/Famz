<?php

namespace App\Enums;

/**
 * How a thing is sold. Deliberately covers the units a Nigerian farm market
 * actually uses — crates, bags, paint buckets, congo — not just SI units.
 */
enum UnitOfMeasure: string
{
    case Piece = 'piece';
    case Bird = 'bird';
    case Dozen = 'dozen';
    case Crate = 'crate';
    case Kilogram = 'kg';
    case Tonne = 'tonne';
    case Bag = 'bag';
    case Litre = 'litre';
    case Bundle = 'bundle';
    case Metre = 'metre';
    case Pack = 'pack';
    case Set = 'set';
    case Bucket = 'bucket';
    case Congo = 'congo';
    case Hour = 'hour';
    case Day = 'day';
    case Service = 'service';

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
            self::Piece => __('per piece'),
            self::Bird => __('per bird'),
            self::Dozen => __('per dozen'),
            self::Crate => __('per crate'),
            self::Kilogram => __('per kg'),
            self::Tonne => __('per tonne'),
            self::Bag => __('per bag'),
            self::Litre => __('per litre'),
            self::Bundle => __('per bundle'),
            self::Metre => __('per metre'),
            self::Pack => __('per pack'),
            self::Set => __('per set'),
            self::Bucket => __('per paint bucket'),
            self::Congo => __('per congo'),
            self::Hour => __('per hour'),
            self::Day => __('per day'),
            self::Service => __('per job'),
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Piece => __('pc'),
            self::Bird => __('bird'),
            self::Dozen => __('dozen'),
            self::Crate => __('crate'),
            self::Kilogram => __('kg'),
            self::Tonne => __('t'),
            self::Bag => __('bag'),
            self::Litre => __('L'),
            self::Bundle => __('bundle'),
            self::Metre => __('m'),
            self::Pack => __('pack'),
            self::Set => __('set'),
            self::Bucket => __('bucket'),
            self::Congo => __('congo'),
            self::Hour => __('hr'),
            self::Day => __('day'),
            self::Service => __('job'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $unit): array => [$unit->value => $unit->label()])
            ->all();
    }
}
