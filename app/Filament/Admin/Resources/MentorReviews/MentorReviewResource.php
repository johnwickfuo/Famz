<?php

namespace App\Filament\Admin\Resources\MentorReviews;

use App\Enums\ReviewStatus;
use App\Filament\Admin\Resources\MentorReviews\Pages\ListMentorReviews;
use App\Filament\Admin\Resources\MentorReviews\Tables\MentorReviewsTable;
use App\Models\MentorReview;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The moderation queue.
 *
 * Nothing a client writes appears on a mentor's profile, or moves their rating,
 * until somebody here has read it.
 */
class MentorReviewResource extends Resource
{
    protected static ?string $model = MentorReview::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftEllipsis;

    protected static string|UnitEnum|null $navigationGroup = 'Mentorship';

    protected static ?int $navigationSort = 30;

    public static function getNavigationLabel(): string
    {
        return __('Reviews');
    }

    public static function getModelLabel(): string
    {
        return __('review');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = MentorReview::query()->where('status', ReviewStatus::Pending)->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return MentorReviewsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMentorReviews::route('/'),
        ];
    }
}
