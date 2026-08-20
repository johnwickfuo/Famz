<?php

namespace App\Filament\Admin\Resources\JobRatings;

use App\Enums\RatingStatus;
use App\Filament\Admin\Resources\JobRatings\Pages\ListJobRatings;
use App\Filament\Admin\Resources\JobRatings\Tables\JobRatingsTable;
use App\Models\JobRating;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Ratings waiting to be read.
 *
 * Nothing on this board is published until somebody has looked at it. That
 * costs immediacy and buys the ability to take down a rating written in a
 * temper — on a board where a bad word can cost somebody a season's work, it is
 * a trade worth making, and the badge here is what stops the queue being
 * forgotten.
 */
class JobRatingResource extends Resource
{
    protected static ?string $model = JobRating::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static string|UnitEnum|null $navigationGroup = 'Farm jobs';

    protected static ?int $navigationSort = 30;

    public static function getNavigationLabel(): string
    {
        return __('Ratings');
    }

    public static function getModelLabel(): string
    {
        return __('rating');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = JobRating::query()->where('status', RatingStatus::Pending)->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Ratings nobody has read yet. Neither side sees them until you do.');
    }

    public static function table(Table $table): Table
    {
        return JobRatingsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJobRatings::route('/'),
        ];
    }
}
