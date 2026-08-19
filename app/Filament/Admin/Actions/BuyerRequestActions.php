<?php

namespace App\Filament\Admin\Actions;

use App\Enums\BuyerRequestStatus;
use App\Models\BuyerRequest;
use App\Services\Offers\BuyerRequestService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

/**
 * Publishing a wanted ad, or refusing to.
 */
class BuyerRequestActions
{
    public static function approve(): Action
    {
        return Action::make('approveRequest')
            ->label(__('Publish it'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('Put this on the board?'))
            ->modalDescription(fn (): string => __(
                'Sellers in its category can see it and start offering. It stays up for :days days from now.',
                ['days' => app(BuyerRequestService::class)->lifetimeDays()],
            ))
            ->visible(fn (BuyerRequest $record): bool => $record->status === BuyerRequestStatus::PendingApproval)
            ->action(fn (BuyerRequest $record) => self::run(
                fn () => app(BuyerRequestService::class)->approve($record, auth()->user()),
                __('Published'),
            ));
    }

    public static function reject(): Action
    {
        return Action::make('rejectRequest')
            ->label(__('Do not publish'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading(__('Tell the buyer what is wrong'))
            ->visible(fn (BuyerRequest $record): bool => $record->status === BuyerRequestStatus::PendingApproval)
            ->schema([
                Placeholder::make('what')
                    ->label(__('They asked for'))
                    ->content(fn (BuyerRequest $record): string => $record->quantity.' '.$record->unit),

                Textarea::make('reason')
                    ->label(__('Why not?'))
                    // Written by a person and read by a person; it is the only
                    // thing standing between a rejection and a bad review.
                    ->helperText(__('The buyer reads this exactly as you write it, so say what to fix.'))
                    ->rows(3)
                    ->required()
                    ->minLength(10)
                    ->maxLength(500),
            ])
            ->action(fn (BuyerRequest $record, array $data) => self::run(
                fn () => app(BuyerRequestService::class)->reject($record, auth()->user(), $data['reason']),
                __('The buyer has been told'),
            ));
    }

    /**
     * @return array<int, Action>
     */
    public static function all(): array
    {
        return [self::approve(), self::reject()];
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
