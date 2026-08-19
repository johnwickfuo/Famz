<?php

namespace App\Filament\Seller\Resources\Withdrawals\Pages;

use App\Filament\Seller\Resources\Withdrawals\WithdrawalResource;
use App\Models\PayoutAccount;
use App\Services\Payouts\PayoutAccountService;
use App\Services\Payouts\WithdrawalService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use RuntimeException;

class ListWithdrawals extends ListRecords
{
    protected static string $resource = WithdrawalResource::class;

    protected function getHeaderActions(): array
    {
        return [$this->requestAction()];
    }

    /**
     * Ask for a payout.
     *
     * The amount offered is `requestableBalance`, not the raw ledger balance:
     * money already spoken for by a request in flight is not available to ask
     * for again, and showing it would only produce a rejection.
     */
    private function requestAction(): Action
    {
        $withdrawals = app(WithdrawalService::class);
        $accounts = app(PayoutAccountService::class);

        return Action::make('requestPayout')
            ->label(__('Ask for a payout'))
            ->icon('heroicon-o-banknotes')
            ->visible(fn (): bool => WithdrawalResource::sellersMayRequest())
            ->modalHeading(__('Ask for a payout'))
            ->modalSubmitActionLabel(__('Send the request'))
            ->schema(function () use ($withdrawals, $accounts): array {
                $available = $withdrawals->requestableBalance(auth()->user());
                $options = $accounts->accountsFor(auth()->user())
                    ->filter(fn (PayoutAccount $account): bool => $account->isPayable())
                    ->mapWithKeys(fn (PayoutAccount $account): array => [
                        $account->getKey() => $account->label().' — '.$account->account_name,
                    ]);

                return [
                    Placeholder::make('available')
                        ->label(__('Ready to withdraw'))
                        ->content(Money::fromKobo($available)),

                    Select::make('payout_account_id')
                        ->label(__('Into which account'))
                        ->options($options)
                        ->default($accounts->defaultFor(auth()->user())?->getKey())
                        ->required()
                        ->helperText($options->isEmpty()
                            ? __('Add a bank account first — see Bank accounts in the menu.')
                            : null),

                    TextInput::make('amount')
                        ->label(__('How much'))
                        ->numeric()
                        ->prefix('₦')
                        ->required()
                        ->default($available > 0 ? $available / 100 : null)
                        ->minValue($withdrawals->minimumKobo() / 100)
                        ->maxValue($available / 100)
                        ->helperText(__('The smallest payout is :amount.', [
                            'amount' => Money::fromKobo($withdrawals->minimumKobo()),
                        ])),
                ];
            })
            ->action(function (array $data) use ($withdrawals): void {
                $account = PayoutAccount::query()
                    ->ownedBy(auth()->user())
                    ->find($data['payout_account_id']);

                try {
                    $withdrawal = $withdrawals->request(
                        auth()->user(),
                        Money::toKobo($data['amount']),
                        $account,
                    );
                } catch (RuntimeException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title(__('Payout requested'))
                    ->body(__(':amount will be sent to your bank once it is approved.', [
                        'amount' => Money::fromKobo($withdrawal->amount_kobo),
                    ]))
                    ->success()
                    ->send();
            });
    }
}
