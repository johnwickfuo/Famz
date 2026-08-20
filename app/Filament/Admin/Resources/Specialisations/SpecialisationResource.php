<?php

namespace App\Filament\Admin\Resources\Specialisations;

use App\Filament\Admin\Resources\Specialisations\Pages\CreateSpecialisation;
use App\Filament\Admin\Resources\Specialisations\Pages\EditSpecialisation;
use App\Filament\Admin\Resources\Specialisations\Pages\ListSpecialisations;
use App\Filament\Admin\Resources\Specialisations\Schemas\SpecialisationForm;
use App\Filament\Admin\Resources\Specialisations\Tables\SpecialisationsTable;
use App\Models\Specialisation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The taxonomy matching runs on.
 *
 * Editing this changes which mentors get found, so it is not a settings screen
 * — it is the ranking's vocabulary. The keywords in particular are what the
 * fallback matcher reads when the AI layer is unavailable, which is most of the
 * time on a deployment with no API key.
 */
class SpecialisationResource extends Resource
{
    protected static ?string $model = Specialisation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Mentorship';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return __('Specialisations');
    }

    public static function getModelLabel(): string
    {
        return __('specialisation');
    }

    public static function form(Schema $schema): Schema
    {
        return SpecialisationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SpecialisationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSpecialisations::route('/'),
            'create' => CreateSpecialisation::route('/create'),
            'edit' => EditSpecialisation::route('/{record}/edit'),
        ];
    }
}
