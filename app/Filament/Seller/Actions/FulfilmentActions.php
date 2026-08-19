<?php

namespace App\Filament\Seller\Actions;

use App\Enums\SubOrderStatus;
use App\Models\SubOrder;
use App\Services\Orders\FulfilmentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

/**
 * Accept, reject, ship, deliver. Built once and used from both the orders table
 * and the order page.
 *
 * Each is authorised against SubOrderPolicy::fulfil as well as being scoped
 * out of the seller's query in the first place — the same belt and braces the
 * listings use, because these actions move money.
 */
class FulfilmentActions
{
    public static function accept(): Action
    {
        return Action::make('acceptOrder')
            ->authorize('fulfil')
            ->label(__('Accept'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('Accept this order?'))
            ->modalDescription(__('The buyer is told you are preparing it.'))
            ->visible(fn (SubOrder $record): bool => $record->status === SubOrderStatus::Pending)
            ->action(fn (SubOrder $record) => self::run(
                fn () => app(FulfilmentService::class)->accept($record, auth()->user()),
                __('Order accepted'),
            ));
    }

    public static function reject(): Action
    {
        return Action::make('rejectOrder')
            ->authorize('fulfil')
            ->label(__('Cannot fulfil'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading(__('Tell the buyer what happened'))
            ->modalDescription(__('They are refunded in full, and the goods go back into your stock.'))
            ->modalSubmitActionLabel(__('Reject and refund'))
            ->visible(fn (SubOrder $record): bool => in_array(
                $record->status,
                [SubOrderStatus::Pending, SubOrderStatus::Accepted],
                true,
            ))
            ->schema([
                Textarea::make('reason')
                    ->label(__('Why can you not fulfil this?'))
                    // A refund with no explanation is how a buyer decides not
                    // to come back.
                    ->helperText(__('Sent to the buyer as you write it.'))
                    ->rows(3)
                    ->required()
                    ->minLength(10)
                    ->maxLength(500),
            ])
            ->action(fn (SubOrder $record, array $data) => self::run(
                fn () => app(FulfilmentService::class)->reject($record, $data['reason'], auth()->user()),
                __('Order rejected and the buyer refunded'),
            ));
    }

    public static function markShipped(): Action
    {
        return Action::make('markShipped')
            ->authorize('fulfil')
            ->label(__('Mark as sent'))
            ->icon(Heroicon::OutlinedTruck)
            ->color('info')
            ->requiresConfirmation()
            ->visible(fn (SubOrder $record): bool => $record->status === SubOrderStatus::Accepted)
            ->action(fn (SubOrder $record) => self::run(
                fn () => app(FulfilmentService::class)->markShipped($record, auth()->user()),
                __('Marked as sent'),
            ));
    }

    public static function markDelivered(): Action
    {
        return Action::make('markDelivered')
            ->authorize('fulfil')
            ->label(__('Mark as delivered'))
            ->icon(Heroicon::OutlinedHome)
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(function (SubOrder $record): string {
                $days = (int) settings('escrow_auto_release_days', 7);

                return settings('settlement_driver') === 'escrow'
                    ? trans_choice(
                        'Your money is released when the buyer confirms, or automatically after :count day if they say nothing.|Your money is released when the buyer confirms, or automatically after :count days if they say nothing.',
                        $days,
                        ['count' => $days],
                    )
                    : __('The buyer is told it has arrived.');
            })
            ->visible(fn (SubOrder $record): bool => in_array(
                $record->status,
                [SubOrderStatus::Accepted, SubOrderStatus::Shipped],
                true,
            ))
            ->action(fn (SubOrder $record) => self::run(
                fn () => app(FulfilmentService::class)->markDelivered($record, auth()->user()),
                __('Marked as delivered'),
            ));
    }

    /**
     * @return array<int, Action>
     */
    public static function all(): array
    {
        return [
            self::accept(),
            self::markShipped(),
            self::markDelivered(),
            self::reject(),
        ];
    }

    private static function run(callable $operation, string $title): void
    {
        try {
            $operation();
        } catch (RuntimeException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($title)->success()->send();
    }
}
