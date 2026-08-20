<?php

namespace App\Filament\Admin\Resources\Consultations\RelationManagers;

use App\Models\Consultation;
use App\Models\ConsultationFollowup;
use App\Services\Consultations\ConsultationService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use RuntimeException;

/**
 * The conversation after the report.
 *
 * One thread, both sides in it, ordered oldest first — the same shape as a
 * dispute thread, for the same reason: two separate threads would let each side
 * tell a different story.
 *
 * The company can always write here, even after the client's window has closed,
 * because chasing somebody who has gone quiet is a legitimate thing to do. An
 * internal note is the company talking to itself and never reaches the client.
 */
class FollowupsRelationManager extends RelationManager
{
    protected static string $relationship = 'followups';

    protected static ?string $title = 'Follow-up';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'asc')
            ->columns([
                TextColumn::make('sender')
                    ->label(__('From'))
                    ->badge()
                    ->formatStateUsing(fn (ConsultationFollowup $record): string => $record->isFromCompany()
                        ? __('Us')
                        : __('Client'))
                    ->color(fn (ConsultationFollowup $record): string => $record->isFromCompany()
                        ? 'success'
                        : 'gray'),

                TextColumn::make('message')
                    ->label(__('Message'))
                    ->wrap()
                    ->limit(400),

                IconColumn::make('is_internal')
                    ->label(__('Private'))
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('heroicon-o-eye')
                    ->visibleFrom('md'),

                TextColumn::make('created_at')
                    ->label(__('When'))
                    ->dateTime('j M, H:i')
                    ->visibleFrom('lg'),
            ])
            ->headerActions([
                Action::make('reply')
                    ->label(__('Write to them'))
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->schema([
                        Textarea::make('message')
                            ->label(__('Message'))
                            ->required()
                            ->rows(5)
                            ->maxLength(4000),

                        FileUpload::make('attachments')
                            ->label(__('Attach anything'))
                            ->image()
                            ->multiple()
                            ->maxFiles(4)
                            ->maxSize(4096)
                            ->disk('public')
                            ->directory('consultations/followups')
                            ->visibility('public'),

                        Toggle::make('is_internal')
                            ->label(__('Keep this private'))
                            ->helperText(__('A note for us. The client never sees it.')),
                    ])
                    ->action(function (array $data): void {
                        /** @var Consultation $consultation */
                        $consultation = $this->getOwnerRecord();

                        try {
                            app(ConsultationService::class)->followUp(
                                $consultation,
                                ConsultationFollowup::FROM_COMPANY,
                                $data['message'],
                                auth()->user(),
                                $data['attachments'] ?? [],
                                (bool) ($data['is_internal'] ?? false),
                            );
                        } catch (RuntimeException $exception) {
                            Notification::make()->title($exception->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('Sent'))->success()->send();
                    }),
            ])
            ->emptyStateHeading(__('Nothing yet'))
            ->emptyStateDescription(__('Anything you or the client write after the report lands here.'));
    }
}
