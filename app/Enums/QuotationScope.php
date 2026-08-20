<?php

namespace App\Enums;

/**
 * What the client wants the company to actually do.
 *
 * Stored as a JSON list on the request rather than as boolean columns, because
 * this is a menu that will grow — and because what matters downstream is the
 * set, not any one item. It is also what the proposal's sections are built
 * around, which is why the labels here read like headings.
 */
enum QuotationScope: string
{
    case LandPreparation = 'land_preparation';
    case Construction = 'construction';
    case EquipmentSupply = 'equipment_supply';
    case Installation = 'installation';
    case Stocking = 'stocking';
    case StaffTraining = 'staff_training';
    case OngoingManagement = 'ongoing_management';

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
            self::LandPreparation => __('Land preparation'),
            self::Construction => __('Construction'),
            self::EquipmentSupply => __('Equipment supply'),
            self::Installation => __('Installation'),
            self::Stocking => __('Stocking'),
            self::StaffTraining => __('Staff training'),
            self::OngoingManagement => __('Ongoing management'),
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::LandPreparation => __('Clearing, levelling, access, drainage.'),
            self::Construction => __('Houses, pens, stores, perimeter.'),
            self::EquipmentSupply => __('Cages, feeders, drinkers, fans, incubators.'),
            self::Installation => __('Fitting and commissioning what is supplied.'),
            self::Stocking => __('Day-old chicks, fingerlings, breeding stock.'),
            self::StaffTraining => __('Teaching your people to run it.'),
            self::OngoingManagement => __('We run it, or supervise whoever does.'),
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

    /**
     * Turn stored values back into labels, skipping anything unrecognised.
     *
     * A request written before a case was renamed must not blow up a dashboard;
     * it just shows the scope items that still exist.
     *
     * @param  array<int, string>|null  $values
     * @return array<int, string>
     */
    public static function labelsFor(?array $values): array
    {
        return collect($values ?? [])
            ->map(fn (string $value): ?self => self::tryFrom($value))
            ->filter()
            ->map(fn (self $case): string => $case->label())
            ->values()
            ->all();
    }
}
