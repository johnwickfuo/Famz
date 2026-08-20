<?php

namespace App\Filament\Admin\Resources\Mentors;

use App\Enums\MentorStatus;
use App\Filament\Admin\Resources\Mentors\Pages\ListMentors;
use App\Filament\Admin\Resources\Mentors\Tables\MentorsTable;
use App\Models\MentorProfile;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Mentors, and the queue of ones waiting to be approved.
 *
 * An invitation gets somebody in; approval gets them listed. They are separate
 * on purpose — the company invites people it wants to talk to, and approves the
 * ones whose profile it is prepared to put its name behind.
 */
class MentorResource extends Resource
{
    protected static ?string $model = MentorProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Mentorship';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'headline';

    public static function getNavigationLabel(): string
    {
        return __('Mentors');
    }

    public static function getModelLabel(): string
    {
        return __('mentor');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = MentorProfile::query()->where('status', MentorStatus::Pending)->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return MentorsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMentors::route('/'),
        ];
    }
}
