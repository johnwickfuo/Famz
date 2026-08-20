<?php

namespace App\Filament\Admin\Resources\MentorInvitations;

use App\Filament\Admin\Resources\MentorInvitations\Pages\ListMentorInvitations;
use App\Filament\Admin\Resources\MentorInvitations\Tables\MentorInvitationsTable;
use App\Models\MentorInvitation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The invitations that let people become mentors.
 *
 * This screen is the entire recruitment process. There is no public signup
 * link, so a mentor exists because somebody here decided they should.
 */
class MentorInvitationResource extends Resource
{
    protected static ?string $model = MentorInvitation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelopeOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Mentorship';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'email';

    public static function getNavigationLabel(): string
    {
        return __('Invitations');
    }

    public static function getModelLabel(): string
    {
        return __('invitation');
    }

    public static function table(Table $table): Table
    {
        return MentorInvitationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMentorInvitations::route('/'),
        ];
    }
}
