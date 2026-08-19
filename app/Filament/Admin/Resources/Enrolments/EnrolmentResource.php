<?php

namespace App\Filament\Admin\Resources\Enrolments;

use App\Filament\Admin\Resources\Enrolments\Pages\ListEnrolments;
use App\Filament\Admin\Resources\Enrolments\Tables\EnrolmentsTable;
use App\Models\Enrolment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Every enrolment across the academy.
 *
 * Read-only, like the per-course list: access follows payment, and there is no
 * screen here for handing it out by hand.
 */
class EnrolmentResource extends Resource
{
    protected static ?string $model = Enrolment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Academy';

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?int $navigationSort = 30;

    public static function getNavigationLabel(): string
    {
        return __('Students');
    }

    public static function getModelLabel(): string
    {
        return __('enrolment');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return EnrolmentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEnrolments::route('/'),
        ];
    }
}
