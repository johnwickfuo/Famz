<?php

namespace App\Enums;

/**
 * What kind of job this is.
 *
 * The four cases are not decoration: they change what the company has to price.
 * A new build carries land preparation and civil work that an equipment-only
 * order does not, and a renovation is priced against what is already standing
 * rather than against a drawing.
 */
enum QuotationProjectType: string
{
    case NewBuild = 'new_build';
    case Expansion = 'expansion';
    case Renovation = 'renovation';
    case EquipmentOnly = 'equipment_only';

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
            self::NewBuild => __('Building from scratch'),
            self::Expansion => __('Expanding what I have'),
            self::Renovation => __('Fixing up what I have'),
            self::EquipmentOnly => __('Equipment only'),
        };
    }

    /**
     * The same thing in a table cell.
     *
     * The long labels are written for the client's form, where they read as
     * questions somebody answers about themselves. An administrator scanning a
     * queue wants the noun.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::NewBuild => __('New build'),
            self::Expansion => __('Expansion'),
            self::Renovation => __('Renovation'),
            self::EquipmentOnly => __('Equipment'),
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::NewBuild => __('Bare land, or nothing built yet.'),
            self::Expansion => __('A working farm that needs to get bigger.'),
            self::Renovation => __('Existing houses or pens that need work.'),
            self::EquipmentOnly => __('Supply and installation, no building.'),
        };
    }

    /**
     * Whether the land questions are worth asking.
     *
     * Somebody buying a set of cages does not need to tell us how many hectares
     * they own, and a form that asks anyway reads as a form that is not paying
     * attention.
     */
    public function needsLand(): bool
    {
        return $this !== self::EquipmentOnly;
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
