<?php

namespace App\Filament\Mentor\Resources\Engagements\Tables;

use App\Enums\EngagementStatus;
use App\Models\MentorshipEngagement;
use App\Services\Mentorship\EngagementService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class EngagementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['client.profile', 'invoices']))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('Reference'))
                    ->searchable()
                    ->copyable(),

                TextColumn::make('client.name')
                    ->label(__('Client'))
                    ->description(fn (MentorshipEngagement $record): ?string => $record->package_title)
                    ->searchable()
                    ->wrap(),

                /*
                 * The reveal, from the mentor's side. Asks the engagement the
                 * same question the client's page asks — before payment there
                 * is nothing to show, and a dash is the honest answer.
                 */
                TextColumn::make('client_phone')
                    ->label(__('Their number'))
                    ->state(fn (MentorshipEngagement $record): string => $record->clientContact()['phone'] ?? '—')
                    ->copyable()
                    ->visibleFrom('lg'),

                TextColumn::make('price_kobo')
                    ->label(__('Your share'))
                    ->formatStateUsing(fn (MentorshipEngagement $record): string => Money::fromKobo($record->mentor_amount_kobo))
                    ->alignRight()
                    ->visibleFrom('md'),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (EngagementStatus $state): string => $state->mentorLabel())
                    ->color(fn (EngagementStatus $state): string => match ($state) {
                        EngagementStatus::Active, EngagementStatus::Completed => 'success',
                        EngagementStatus::AwaitingConfirmation, EngagementStatus::PendingPayment => 'warning',
                        EngagementStatus::Disputed, EngagementStatus::Refunded => 'danger',
                        EngagementStatus::Cancelled => 'gray',
                    }),

                TextColumn::make('started_at')
                    ->label(__('Started'))
                    ->dateTime('j M Y')
                    ->placeholder(__('Not paid yet'))
                    ->sortable()
                    ->visibleFrom('xl'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(EngagementStatus::options()),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()->schema(fn (Schema $schema): Schema => self::details($schema)),

                    Action::make('markComplete')
                        ->label(__('Mark finished'))
                        ->icon('heroicon-o-check-circle')
                        ->requiresConfirmation()
                        ->modalDescription(fn (): string => __(
                            'Your client gets :days days to confirm. If they say nothing, it confirms itself and you are paid.',
                            ['days' => app(EngagementService::class)->confirmationDays()],
                        ))
                        ->visible(fn (MentorshipEngagement $record): bool => $record->status === EngagementStatus::Active)
                        ->action(function (MentorshipEngagement $record): void {
                            try {
                                app(EngagementService::class)->markComplete($record, auth()->user());
                            } catch (RuntimeException $exception) {
                                Notification::make()->title($exception->getMessage())->danger()->send();

                                return;
                            }

                            Notification::make()
                                ->title(__('Marked finished'))
                                ->body(__('Your client has been asked to confirm.'))
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }

    private static function details(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('The work'))
                ->columns(2)
                ->schema([
                    TextEntry::make('package_title')->label(__('Package')),
                    TextEntry::make('price_kobo')
                        ->label(__('Your share'))
                        ->state(fn (MentorshipEngagement $record): string => Money::fromKobo($record->mentor_amount_kobo)),
                    TextEntry::make('brief')
                        ->label(__('What they asked for'))
                        ->placeholder(__('Nothing written'))
                        ->columnSpanFull(),
                ]),

            Section::make(__('Your client'))
                ->columns(2)
                ->schema([
                    TextEntry::make('client_name')
                        ->label(__('Name'))
                        ->state(fn (MentorshipEngagement $record): string => $record->clientContact()['name'] ?? '—'),
                    TextEntry::make('client_phone')
                        ->label(__('Phone'))
                        ->state(fn (MentorshipEngagement $record): string => $record->clientContact()['phone'] ?? '—')
                        ->copyable(),
                    TextEntry::make('client_email')
                        ->label(__('Email'))
                        ->state(fn (MentorshipEngagement $record): string => $record->clientContact()['email'] ?? '—')
                        ->copyable(),
                    TextEntry::make('client_place')
                        ->label(__('Where'))
                        ->state(fn (MentorshipEngagement $record): string => collect([
                            $record->clientContact()['lga'] ?? null,
                            $record->clientContact()['state'] ?? null,
                        ])->filter()->implode(', ') ?: '—'),
                ])
                ->description(__('Shown once the engagement has been paid for, and not before.')),
        ]);
    }
}
