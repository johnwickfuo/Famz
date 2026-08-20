<?php

namespace App\Filament\Admin\Resources\BreedStandards;

use App\Filament\Admin\Resources\BreedStandards\Pages\CreateBreedStandard;
use App\Filament\Admin\Resources\BreedStandards\Pages\EditBreedStandard;
use App\Filament\Admin\Resources\BreedStandards\Pages\ListBreedStandards;
use App\Filament\Admin\Resources\BreedStandards\Schemas\BreedStandardForm;
use App\Filament\Admin\Resources\BreedStandards\Tables\BreedStandardsTable;
use App\Models\BreedStandard;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The figures the assistant is allowed to quote.
 *
 * This is not a reference table sitting quietly in a corner. The assistant is
 * forbidden from recalling or calculating any number; every feed figure it
 * states is read from a row here and cited back to the source named on it. Edit
 * a row and the answer a farmer gets tomorrow changes.
 *
 * Which is exactly why it is editable. Breeders revise their guides, and what a
 * bird actually eats in Oyo is not always what a European management guide says
 * — an administrator who knows better needs to be able to say so without
 * waiting for a deployment.
 */
class BreedStandardResource extends Resource
{
    protected static ?string $model = BreedStandard::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = 'Assistant';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'breed';

    public static function getNavigationLabel(): string
    {
        return __('Feeding tables');
    }

    public static function getModelLabel(): string
    {
        return __('feeding table row');
    }

    public static function getPluralModelLabel(): string
    {
        return __('feeding tables');
    }

    public static function form(Schema $schema): Schema
    {
        return BreedStandardForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BreedStandardsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBreedStandards::route('/'),
            'create' => CreateBreedStandard::route('/create'),
            'edit' => EditBreedStandard::route('/{record}/edit'),
        ];
    }
}
