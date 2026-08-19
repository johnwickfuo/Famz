<?php

namespace App\Filament\Seller\Resources\PayoutAccounts\Tables;

use App\Models\PayoutAccount;
use App\Services\Payouts\PayoutAccountService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use RuntimeException;

class PayoutAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('is_default', 'desc')
            ->columns([
                TextColumn::make('bank_name')
                    ->label(__('Bank'))
                    ->weight('bold')
                    ->description(fn (PayoutAccount $record): string => $record->maskedNumber()),

                TextColumn::make('account_name')
                    ->label(__('Name on the account'))
                    // The bank's answer, not the seller's typing.
                    ->description(__('As the bank gave it'))
                    ->wrap(),

                IconColumn::make('is_default')
                    ->label(__('Paid into'))
                    ->boolean()
                    ->alignCenter(),

                IconColumn::make('is_verified')
                    ->label(__('Confirmed'))
                    ->boolean()
                    ->alignCenter()
                    ->visibleFrom('sm'),
            ])
            ->recordActions([
                Action::make('makeDefault')
                    ->label(__('Pay into this one'))
                    ->icon('heroicon-o-check-circle')
                    ->requiresConfirmation()
                    ->visible(fn (PayoutAccount $record): bool => ! $record->is_default && $record->isPayable())
                    ->action(function (PayoutAccount $record): void {
                        $record->makeDefault();

                        Notification::make()
                            ->title(__('Payouts will go to :bank now.', ['bank' => $record->bank_name]))
                            ->success()
                            ->send();
                    }),

                DeleteAction::make()
                    ->label(__('Remove'))
                    ->using(function (PayoutAccount $record): void {
                        try {
                            app(PayoutAccountService::class)->remove($record);
                        } catch (RuntimeException $exception) {
                            Notification::make()->title($exception->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->emptyStateHeading(__('No bank account yet'))
            ->emptyStateDescription(__('Add one and we will check the name with your bank before saving it.'));
    }
}
