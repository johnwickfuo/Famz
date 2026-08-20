<?php

namespace App\Filament\Admin\Resources\MentorInvitations\Tables;

use App\Models\MentorInvitation;
use App\Services\Mentorship\InvitationService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class MentorInvitationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['creator', 'redeemer']))
            ->columns([
                TextColumn::make('email')
                    ->label(__('Sent to'))
                    ->placeholder(__('Anybody with the link'))
                    ->description(fn (MentorInvitation $record): ?string => $record->name)
                    ->searchable(),

                TextColumn::make('creator.name')
                    ->label(__('Invited by'))
                    ->visibleFrom('lg'),

                TextColumn::make('expires_at')
                    ->label(__('Expires'))
                    ->dateTime('j M Y')
                    ->description(fn (MentorInvitation $record): ?string => $record->hasExpired()
                        ? __('expired')
                        : $record->expiresIn())
                    ->sortable(),

                TextColumn::make('used_at')
                    ->label(__('Used'))
                    ->dateTime('j M Y')
                    ->placeholder(__('Not yet'))
                    ->description(fn (MentorInvitation $record): ?string => $record->redeemer?->name)
                    ->visibleFrom('md'),

                IconColumn::make('is_open')
                    ->label(__('Still good'))
                    ->boolean()
                    ->getStateUsing(fn (MentorInvitation $record): bool => $record->isOpen()),
            ])
            ->filters([
                Filter::make('open')
                    ->label(__('Still usable'))
                    ->query(fn (Builder $query) => $query->open()),

                Filter::make('used')
                    ->label(__('Already used'))
                    ->query(fn (Builder $query) => $query->spent()),
            ])
            ->headerActions([
                Action::make('invite')
                    ->label(__('Invite a mentor'))
                    ->icon('heroicon-o-plus')
                    ->modalHeading(__('Invite a mentor'))
                    ->modalSubmitActionLabel(__('Create the invitation'))
                    ->schema([
                        TextInput::make('email')
                            ->label(__('Their email'))
                            ->email()
                            ->maxLength(255)
                            // Optional on purpose: an administrator handing out
                            // a link at a trade fair does not know who will use
                            // it, and an addressed invitation can only be
                            // redeemed by that address.
                            ->helperText(__('Optional. If you set it, only that address can use the link.')),

                        TextInput::make('name')
                            ->label(__('Their name'))
                            ->maxLength(255),

                        TextInput::make('days')
                            ->label(__('Good for how many days'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(90)
                            ->default(MentorInvitation::DEFAULT_DAYS),

                        Textarea::make('note')
                            ->label(__('A note for them'))
                            ->rows(3)
                            ->maxLength(500)
                            ->helperText(__('Shown at the top of the registration form.')),
                    ])
                    ->action(function (array $data): void {
                        $invitation = app(InvitationService::class)->create(
                            auth()->user(),
                            $data['email'] ?? null,
                            $data['name'] ?? null,
                            $data['note'] ?? null,
                            isset($data['days']) ? (int) $data['days'] : null,
                        );

                        Notification::make()
                            ->title(__('Invitation created'))
                            ->body(__('Use "Copy link" on the row to send it.'))
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    /*
                     * The link, shown once in a modal with a copy button. It is
                     * not a column: a table full of live invitation URLs is a
                     * screenshot away from being a public signup page.
                     */
                    Action::make('link')
                        ->label(__('Copy link'))
                        ->icon('heroicon-o-link')
                        ->visible(fn (MentorInvitation $record): bool => $record->isOpen())
                        ->modalHeading(__('Send this to them'))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel(__('Done'))
                        ->schema(fn (MentorInvitation $record): array => [
                            Placeholder::make('url')
                                ->label(__('Registration link'))
                                ->content(new HtmlString(
                                    '<code class="block break-all text-xs">'
                                    .e(app(InvitationService::class)->urlFor($record))
                                    .'</code>'
                                )),

                            Placeholder::make('warning')
                                ->label('')
                                ->content(__('It works once, and stops working :when. Send it by WhatsApp or email — it is not listed anywhere on the site.', [
                                    'when' => $record->expiresIn() ?? __('never'),
                                ])),
                        ]),

                    DeleteAction::make()
                        ->label(__('Revoke'))
                        ->visible(fn (MentorInvitation $record): bool => ! $record->isUsed())
                        ->modalDescription(__('The link stops working immediately.')),
                ]),
            ]);
    }
}
