<?php

namespace App\Enums;

/**
 * What the site runs on.
 *
 * This drives real money on a Nigerian farm quotation: a site on `none` needs
 * generating capacity costed into the proposal before a single fan is quoted,
 * and one on `grid` still usually needs a backup.
 */
enum QuotationPowerSituation: string
{
    case Grid = 'grid';
    case Generator = 'generator';
    case Solar = 'solar';
    case None = 'none';
    case Mixed = 'mixed';

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
            self::Grid => __('Public supply'),
            self::Generator => __('Generator'),
            self::Solar => __('Solar'),
            self::None => __('Nothing yet'),
            self::Mixed => __('More than one of these'),
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
