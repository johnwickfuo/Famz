<?php

namespace App\Filament\Admin\Resources\Consultations\RelationManagers;

use App\Models\Consultation;
use App\Models\ConsultationReport;
use App\Services\Consultations\ConsultationNotifier;
use App\Services\Consultations\ConsultationService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use RuntimeException;

/**
 * Writing up the work.
 *
 * A report is a draft until somebody publishes it, and that is the whole reason
 * publishing is a separate action rather than a checkbox on the form. An
 * administrator working through findings over two days must not have half of it
 * appear in the client's dashboard — a client who reads "what we found:" with
 * nothing after it has been told something untrue about the work.
 */
class ReportsRelationManager extends RelationManager
{
    protected static string $relationship = 'reports';

    protected static ?string $title = 'Report';

    /**
     * Filament makes relation managers read-only on resource View pages by
     * default. A consultation has no Edit page — the record is the client's own
     * words and nobody should be retyping those — so this manager lives on the
     * View page, and without this it would be a report screen you cannot write
     * a report on.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->label(__('Title'))
                ->required()
                ->maxLength(200)
                ->placeholder(__('Brooder losses: findings and what to change'))
                ->columnSpanFull(),

            Textarea::make('findings')
                ->label(__('What we found'))
                ->required()
                ->rows(8)
                ->helperText(__('What is actually going on, in the words you would use on the phone.'))
                ->columnSpanFull(),

            Textarea::make('recommendations')
                ->label(__('What we recommend'))
                ->required()
                ->rows(8)
                ->helperText(__('What they should do about it. Be specific — quantities, temperatures, timings.'))
                ->columnSpanFull(),

            Textarea::make('follow_up_actions')
                ->label(__('What to do next'))
                ->rows(5)
                ->helperText(__('Optional. Anything to check or come back to in a week or a month.'))
                ->columnSpanFull(),

            FileUpload::make('attachments')
                ->label(__('Anything to attach'))
                ->image()
                ->multiple()
                ->reorderable()
                ->maxFiles(8)
                ->maxSize(4096)
                ->disk('public')
                ->directory('consultations/reports')
                ->visibility('public')
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->label(__('Report'))
                    ->wrap(),

                IconColumn::make('published_at')
                    ->label(__('Sent to them'))
                    ->boolean()
                    ->getStateUsing(fn (ConsultationReport $record): bool => $record->isPublished()),

                TextColumn::make('published_at')
                    ->label(__('Published'))
                    ->dateTime('j M Y, H:i')
                    ->placeholder(__('Draft — they cannot see it'))
                    ->visibleFrom('md'),

                TextColumn::make('author.name')
                    ->label(__('Written by'))
                    ->placeholder('—')
                    ->visibleFrom('lg'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('Write the report'))
                    // Authorship is read off the session, never accepted from the
                    // submitted form, which is why `created_by` is not fillable
                    // and is assigned after the insert rather than through it.
                    ->using(function (array $data): ConsultationReport {
                        /** @var Consultation $consultation */
                        $consultation = $this->getOwnerRecord();

                        $report = $consultation->reports()->create($data);

                        $report->created_by = auth()->id();
                        $report->save();

                        return $report;
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),

                    Action::make('publish')
                        ->label(__('Publish to the client'))
                        ->icon('heroicon-o-paper-airplane')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalDescription(__('It appears on their dashboard and they are emailed a link. They can download it as a PDF.'))
                        ->visible(fn (ConsultationReport $record): bool => ! $record->isPublished())
                        ->action(function (ConsultationReport $record): void {
                            try {
                                $published = app(ConsultationService::class)->publish($record);
                            } catch (RuntimeException $exception) {
                                Notification::make()->title($exception->getMessage())->danger()->send();

                                return;
                            }

                            /** @var Consultation $consultation */
                            $consultation = $this->getOwnerRecord();

                            app(ConsultationNotifier::class)->reportPublished($consultation, $published);

                            Notification::make()
                                ->title(__('Sent'))
                                ->body(__('They have been emailed and it is on their dashboard.'))
                                ->success()
                                ->send();
                        }),

                    Action::make('unpublish')
                        ->label(__('Take it back down'))
                        ->icon('heroicon-o-eye-slash')
                        ->color('danger')
                        ->requiresConfirmation()
                        // Honest about what this can and cannot undo.
                        ->modalDescription(__('It disappears from their dashboard. They may already have read it or saved the PDF.'))
                        ->visible(fn (ConsultationReport $record): bool => $record->isPublished())
                        ->action(function (ConsultationReport $record): void {
                            app(ConsultationService::class)->unpublish($record);

                            Notification::make()->title(__('Taken down'))->success()->send();
                        }),

                    DeleteAction::make()
                        ->visible(fn (ConsultationReport $record): bool => ! $record->isPublished()),
                ]),
            ])
            ->emptyStateHeading(__('No report yet'))
            ->emptyStateDescription(__('This is what the client paid for. Write it here and publish it when it is finished.'));
    }
}
