<?php

namespace App\Filament\Mentor\Resources\Reviews;

use App\Filament\Mentor\Resources\Reviews\Pages\ListReviews;
use App\Filament\Mentor\Resources\Reviews\Tables\ReviewsTable;
use App\Models\MentorReview;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * What clients said about this mentor.
 *
 * Includes the ones still waiting on moderation. A mentor seeing a two-star
 * review before the public does is the point: nobody should learn their rating
 * dropped by noticing the number on their own profile.
 */
class ReviewResource extends Resource
{
    protected static ?string $model = MentorReview::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static ?int $navigationSort = 30;

    public static function getNavigationLabel(): string
    {
        return __('My reviews');
    }

    public static function getModelLabel(): string
    {
        return __('review');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('mentor_profile_id', auth()->user()?->mentorProfile?->getKey() ?? 0);
    }

    public static function table(Table $table): Table
    {
        return ReviewsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReviews::route('/'),
        ];
    }
}
