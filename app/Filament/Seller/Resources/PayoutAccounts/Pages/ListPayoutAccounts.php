<?php

namespace App\Filament\Seller\Resources\PayoutAccounts\Pages;

use App\Filament\Seller\Resources\PayoutAccounts\PayoutAccountResource;
use App\Services\Payouts\PayoutAccountService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use RuntimeException;

class ListPayoutAccounts extends ListRecords
{
    protected static string $resource = PayoutAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [$this->addAction()];
    }

    /**
     * Add an account, having asked the bank whose it is.
     *
     * There is no "account name" field: it is not the seller's to state. The
     * name comes back from the bank, which is what makes a mistyped digit
     * something they find out about now rather than after the money has gone.
     */
    private function addAction(): Action
    {
        $accounts = app(PayoutAccountService::class);

        return Action::make('addAccount')
            ->label(__('Add a bank account'))
            ->icon('heroicon-o-plus')
            ->modalHeading(__('Add a bank account'))
            ->modalDescription(__('We will check the name with your bank before saving it.'))
            ->modalSubmitActionLabel(__('Check and save'))
            ->schema(fn (): array => [
                Select::make('bank_code')
                    ->label(__('Bank'))
                    ->options($accounts->bankOptions())
                    ->searchable()
                    ->required(),

                TextInput::make('account_number')
                    ->label(__('Account number'))
                    ->required()
                    ->numeric()
                    ->minLength(10)
                    ->maxLength(10)
                    ->helperText(__('Ten digits, as it appears on your statement.')),
            ])
            ->action(function (array $data) use ($accounts): void {
                try {
                    $account = $accounts->add(auth()->user(), $data['bank_code'], $data['account_number']);
                } catch (RuntimeException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title(__('Account confirmed'))
                    ->body(__('Your bank says this account belongs to :name.', ['name' => $account->account_name]))
                    ->success()
                    ->send();
            });
    }
}
