<?php

namespace App\Filament\Admin\Resources\Mentors\Tables;

use App\Enums\MentorStatus;
use App\Models\MentorProfile;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MentorsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Pending first: this table is a queue before it is a directory.
            ->defaultSort('status')
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['user', 'specialisations'])
                ->withCount(['packages', 'engagements']))
            ->columns([
                ImageColumn::make('avatar')
                    ->label(__('Photo'))
                    ->disk('public')
                    ->circular()
                    ->visibleFrom('lg'),

                TextColumn::make('user.name')
                    ->label(__('Mentor'))
                    ->description(fn (MentorProfile $record): ?string => $record->headline)
                    ->searchable()
                    ->wrap(),

                TextColumn::make('specialisations_list')
                    ->label(__('Helps with'))
                    ->state(fn (MentorProfile $record): string => $record->specialisations
                        ->take(3)
                        ->pluck('name')
                        ->implode(', ') ?: '—')
                    ->wrap()
                    ->visibleFrom('xl'),

                TextColumn::make('packages_count')
                    ->label(__('Packages'))
                    ->alignRight()
                    ->visibleFrom('md'),

                TextColumn::make('engagements_completed')
                    ->label(__('Finished'))
                    ->alignRight()
                    ->sortable()
                    ->visibleFrom('lg'),

                TextColumn::make('average_rating')
                    ->label(__('Rating'))
                    ->formatStateUsing(fn (?float $state): string => $state === null ? '—' : $state.'/5')
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (MentorStatus $state): string => $state->label())
                    ->color(fn (MentorStatus $state): string => match ($state) {
                        MentorStatus::Approved => 'success',
                        MentorStatus::Pending => 'warning',
                        MentorStatus::Suspended => 'danger',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(MentorStatus::options()),

                SelectFilter::make('specialisations')
                    ->label(__('Specialisation'))
                    ->relationship('specialisations', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()->schema(fn (Schema $schema): Schema => self::details($schema)),

                    Action::make('approve')
                        ->label(__('Approve'))
                        ->icon('heroicon-o-check-badge')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalDescription(__('They become findable straight away, and clients can hire them.'))
                        ->visible(fn (MentorProfile $record): bool => $record->status !== MentorStatus::Approved)
                        ->action(function (MentorProfile $record): void {
                            // A mentor with nothing to sell cannot be hired, so
                            // approving them would put a dead end in front of
                            // every client who found them.
                            if ($record->packages()->where('is_active', true)->count() < 1) {
                                Notification::make()
                                    ->title(__('This mentor has no packages yet'))
                                    ->body(__('Ask them to add at least one before you approve them, or nobody can hire them.'))
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $record->forceFill([
                                'status' => MentorStatus::Approved,
                                'approved_by' => auth()->id(),
                                'approved_at' => now(),
                                'status_note' => null,
                            ])->save();

                            Notification::make()->title(__('Approved'))->success()->send();
                        }),

                    Action::make('suspend')
                        ->label(__('Suspend'))
                        ->icon('heroicon-o-pause-circle')
                        ->color('danger')
                        ->visible(fn (MentorProfile $record): bool => $record->status === MentorStatus::Approved)
                        ->schema([
                            Textarea::make('note')
                                ->label(__('Why'))
                                ->required()
                                ->rows(3)
                                ->maxLength(500)
                                ->helperText(__('The mentor reads this on their profile page.')),
                        ])
                        ->requiresConfirmation()
                        ->modalDescription(__('They stop appearing in shortlists. Work already running is unaffected.'))
                        ->action(function (MentorProfile $record, array $data): void {
                            $record->forceFill([
                                'status' => MentorStatus::Suspended,
                                'status_note' => $data['note'],
                            ])->save();

                            Notification::make()->title(__('Suspended'))->success()->send();
                        }),
                ]),
            ]);
    }

    private static function details(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Their profile'))
                ->columns(2)
                ->schema([
                    TextEntry::make('headline')->label(__('Headline'))->columnSpanFull(),
                    TextEntry::make('bio')->label(__('About'))->columnSpanFull(),
                    TextEntry::make('strengths')->label(__('What they say they are good at'))->columnSpanFull(),
                    TextEntry::make('years_experience')->label(__('Years')),
                    TextEntry::make('affiliation')->label(__('Farm or business'))->placeholder('—'),
                    TextEntry::make('qualifications')->label(__('Qualifications'))->placeholder('—')->columnSpanFull(),
                ]),

            Section::make(__('What they are matched on'))
                ->schema([
                    TextEntry::make('specialisation_names')
                        ->label(__('Specialisations'))
                        ->state(fn (MentorProfile $record): string => $record->specialisations
                            ->pluck('name')
                            ->implode(', ') ?: __('None — they can never be shortlisted'))
                        ->columnSpanFull(),
                ]),

            Section::make(__('How they work'))
                ->columns(2)
                ->schema([
                    TextEntry::make('preferred_contact_method')
                        ->label(__('Preferred contact'))
                        ->state(fn (MentorProfile $record): string => $record->preferred_contact_method->label()),
                    TextEntry::make('states_served')
                        ->label(__('Travels to'))
                        ->state(fn (MentorProfile $record): string => collect($record->states_served ?? [])
                            ->implode(', ') ?: __('Remote only')),
                ])
                // The value itself is deliberately absent even here. An
                // administrator has no reason to read somebody's private
                // number out of a moderation screen.
                ->description(__('The number or link itself is not shown here — it is only ever released to a client who has paid.')),
        ]);
    }
}
