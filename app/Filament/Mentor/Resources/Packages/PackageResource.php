<?php

namespace App\Filament\Mentor\Resources\Packages;

use App\Filament\Mentor\Resources\Packages\Pages\CreatePackage;
use App\Filament\Mentor\Resources\Packages\Pages\EditPackage;
use App\Filament\Mentor\Resources\Packages\Pages\ListPackages;
use App\Filament\Mentor\Resources\Packages\Schemas\PackageForm;
use App\Filament\Mentor\Resources\Packages\Tables\PackagesTable;
use App\Models\MentorProfile;
use App\Models\MentorshipPackage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * What this mentor sells.
 *
 * Scoped to the signed-in mentor's own profile, the same way the seller panel
 * scopes listings: a query that could return somebody else's row is a bug
 * waiting for a URL to be edited.
 */
class PackageResource extends Resource
{
    protected static ?string $model = MentorshipPackage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationLabel(): string
    {
        return __('What I offer');
    }

    public static function getModelLabel(): string
    {
        return __('package');
    }

    /**
     * The signed-in mentor, or null for somebody who is not one.
     */
    public static function currentMentor(): ?MentorProfile
    {
        return auth()->user()?->mentorProfile;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('mentor_profile_id', static::currentMentor()?->getKey() ?? 0);
    }

    public static function form(Schema $schema): Schema
    {
        return PackageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PackagesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPackages::route('/'),
            'create' => CreatePackage::route('/create'),
            'edit' => EditPackage::route('/{record}/edit'),
        ];
    }
}
