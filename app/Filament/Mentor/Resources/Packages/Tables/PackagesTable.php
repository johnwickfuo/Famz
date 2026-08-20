<?php

namespace App\Filament\Mentor\Resources\Packages\Tables;

use App\Models\MentorshipPackage;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PackagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('title')
                    ->label(__('Package'))
                    ->description(fn (MentorshipPackage $record): ?string => $record->duration_description)
                    ->wrap(),

                TextColumn::make('price_kobo')
                    ->label(__('Price'))
                    ->formatStateUsing(fn (MentorshipPackage $record): string => $record->priceLabel())
                    ->alignRight()
                    ->sortable(),

                TextColumn::make('sessions_included')
                    ->label(__('Sessions'))
                    ->alignRight()
                    ->visibleFrom('md'),

                IconColumn::make('is_active')
                    ->label(__('Offered'))
                    ->boolean(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make()
                        // An engagement snapshots its terms, so deleting the
                        // package cannot change what anybody agreed — but a
                        // package with live work on it is nearly always being
                        // deleted by mistake.
                        ->before(function (MentorshipPackage $record, DeleteAction $action): void {
                            $live = $record->engagements()->live()->count();

                            if ($live > 0) {
                                Notification::make()
                                    ->title(__('This package has work running on it'))
                                    ->body(__('Turn it off instead — that stops new clients choosing it without touching what is already agreed.'))
                                    ->danger()
                                    ->send();

                                $action->cancel();
                            }
                        }),
                ]),
            ]);
    }
}
