<?php

namespace App\Enums;

/**
 * Where the water comes from.
 *
 * A site with no water source needs a borehole costed in, which is often the
 * single largest line on a poultry proposal after the housing itself.
 */
enum QuotationWaterSource: string
{
    case Borehole = 'borehole';
    case Well = 'well';
    case Municipal = 'municipal';
    case Stream = 'stream';
    case None = 'none';

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
            self::Borehole => __('Borehole'),
            self::Well => __('Hand-dug well'),
            self::Municipal => __('Public water'),
            self::Stream => __('Stream or river'),
            self::None => __('Nothing yet'),
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
