<?php

namespace App\Filament\Mentor\Resources\Engagements;

use App\Enums\EngagementStatus;
use App\Filament\Mentor\Resources\Engagements\Pages\ListEngagements;
use App\Filament\Mentor\Resources\Engagements\Tables\EngagementsTable;
use App\Models\MentorProfile;
use App\Models\MentorshipEngagement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The mentor's own work.
 *
 * The client's phone number is a column here, and it is only ever populated for
 * an engagement whose status reveals contact — the same single question the
 * client's side asks. Before payment the cell is a dash, not a hidden value.
 */
class EngagementResource extends Resource
{
    protected static ?string $model = MentorshipEngagement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getNavigationLabel(): string
    {
        return __('My clients');
    }

    public static function getModelLabel(): string
    {
        return __('engagement');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function currentMentor(): ?MentorProfile
    {
        return auth()->user()?->mentorProfile;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('mentor_profile_id', static::currentMentor()?->getKey() ?? 0);
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = static::getEloquentQuery()
            ->where('status', EngagementStatus::Active)
            ->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function table(Table $table): Table
    {
        return EngagementsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEngagements::route('/'),
        ];
    }
}
