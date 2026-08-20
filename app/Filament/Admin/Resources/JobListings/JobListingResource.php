<?php

namespace App\Filament\Admin\Resources\JobListings;

use App\Filament\Admin\Resources\JobListings\Pages\ListJobListings;
use App\Filament\Admin\Resources\JobListings\Tables\JobListingsTable;
use App\Models\JobListing;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Every job on the board.
 *
 * Moderation rather than management: employers run their own listings, and an
 * administrator is here to take down something that should not be up — a job
 * asking for a fee, one that reads like trafficking, one with a phone number in
 * the description trying to route round the platform.
 */
class JobListingResource extends Resource
{
    protected static ?string $model = JobListing::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static string|UnitEnum|null $navigationGroup = 'Farm jobs';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationLabel(): string
    {
        return __('Listings');
    }

    public static function getModelLabel(): string
    {
        return __('job listing');
    }

    /**
     * Employers post their own. There is no admin-side create.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return JobListingsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJobListings::route('/'),
        ];
    }
}
