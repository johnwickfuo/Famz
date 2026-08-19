<?php

namespace App\Filament\Admin\Actions;

use App\Enums\DisputeStatus;
use App\Models\Dispute;
use App\Services\Disputes\DisputeService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

/**
 * Arbitration. Built once and used from both the disputes table and the
 * dispute page.
 *
 * Each of these moves real money between three parties, so each one states
 * plainly what it is about to do before it does it.
 */
class DisputeActions
{
    public static function takeUp(): Action
    {
        return Action::make('takeUpDispute')
            ->label(__('I am looking at this'))
            ->icon(Heroicon::OutlinedEye)
            ->requiresConfirmation()
            ->modalDescription(__('The buyer and seller see that somebody has picked it up.'))
            ->visible(fn (Dispute $record): bool => $record->status === DisputeStatus::Open)
            ->action(fn (Dispute $record) => self::run(
                fn () => app(DisputeService::class)->markUnderReview($record, auth()->user()),
                __('Marked as being looked at'),
            ));
    }

    public static function resolveForSeller(): Action
    {
        return Action::make('resolveForSeller')
            ->label(__('Decide for the seller'))
            ->icon(Heroicon::OutlinedHandThumbUp)
            ->color('success')
            ->modalHeading(__('Release the money to the seller'))
            ->visible(fn (Dispute $record): bool => $record->isLive())
            ->schema([
                Placeholder::make('effect')
                    ->label(__('What this does'))
                    ->content(fn (Dispute $record): string => __(
                        'The full :amount is released to the seller and the buyer gets nothing back.',
                        ['amount' => Money::fromKobo($record->amountAtStakeKobo())],
                    )),

                self::noteField(__('Both sides read this. Say why.')),
            ])
            ->action(fn (Dispute $record, array $data) => self::run(
                fn () => app(DisputeService::class)->resolveForSeller($record, auth()->user(), $data['note']),
                __('Released to the seller'),
            ));
    }

    public static function resolveForBuyer(): Action
    {
        return Action::make('resolveForBuyer')
            ->label(__('Refund the buyer in full'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->modalHeading(__('Refund the buyer in full'))
            ->visible(fn (Dispute $record): bool => $record->isLive())
            ->schema([
                Placeholder::make('effect')
                    ->label(__('What this does'))
                    ->content(fn (Dispute $record): string => __(
                        ':amount goes back to the buyer. The seller is paid nothing and the platform gives up its commission.',
                        ['amount' => Money::fromKobo($record->amountAtStakeKobo())],
                    )),

                self::noteField(__('Both sides read this. Say why.')),
            ])
            ->action(fn (Dispute $record, array $data) => self::run(
                fn () => app(DisputeService::class)->resolveForBuyer($record, auth()->user(), $data['note']),
                __('Refunded in full'),
            ));
    }

    public static function resolvePartially(): Action
    {
        return Action::make('resolvePartially')
            ->label(__('Split it'))
            ->icon(Heroicon::OutlinedScale)
            ->color('warning')
            ->modalHeading(__('Refund part of it'))
            ->visible(fn (Dispute $record): bool => $record->isLive())
            ->schema([
                Placeholder::make('at_stake')
                    ->label(__('The buyer paid'))
                    ->content(fn (Dispute $record): string => Money::fromKobo($record->amountAtStakeKobo())),

                TextInput::make('amount')
                    ->label(__('Give back'))
                    ->prefix('₦')
                    ->numeric()
                    ->required()
                    ->minValue(0.01)
                    ->maxValue(fn (Dispute $record): float => ($record->amountAtStakeKobo() - 1) / 100)
                    // The rule, stated where the decision is made.
                    ->helperText(__('Counted against the goods first, so the platform gives back its commission on exactly what is returned. The delivery fee comes out of the seller.')),

                self::noteField(__('Both sides read this. Say what you decided and why.')),
            ])
            ->action(fn (Dispute $record, array $data) => self::run(
                fn () => app(DisputeService::class)->resolvePartially(
                    $record,
                    auth()->user(),
                    Money::toKobo($data['amount']),
                    $data['note'],
                ),
                __('Split and settled'),
            ));
    }

    public static function closeWithoutDecision(): Action
    {
        return Action::make('closeDispute')
            ->label(__('Close, nothing to decide'))
            ->icon(Heroicon::OutlinedXMark)
            ->color('gray')
            ->modalHeading(__('Close without moving any money'))
            ->modalDescription(__('For a complaint that was withdrawn, duplicated, or sorted out directly. The escrow clock starts again.'))
            ->visible(fn (Dispute $record): bool => $record->isLive())
            ->schema([self::noteField(__('Both sides read this.'))])
            ->action(fn (Dispute $record, array $data) => self::run(
                fn () => app(DisputeService::class)->closeWithoutDecision($record, auth()->user(), $data['note']),
                __('Closed'),
            ));
    }

    /**
     * Say something in the thread without deciding anything.
     */
    public static function reply(): Action
    {
        return Action::make('replyToDispute')
            ->label(__('Ask a question'))
            ->icon(Heroicon::OutlinedChatBubbleLeftRight)
            ->visible(fn (Dispute $record): bool => $record->isLive())
            ->schema([
                Textarea::make('body')
                    ->label(__('Message'))
                    ->rows(4)
                    ->required()
                    ->maxLength(2000)
                    ->helperText(__('The buyer and the seller both see this.')),
            ])
            ->action(fn (Dispute $record, array $data) => self::run(
                fn () => app(DisputeService::class)->comment($record, auth()->user(), $data['body']),
                __('Sent'),
            ));
    }

    /**
     * @return array<int, Action>
     */
    public static function all(): array
    {
        return [
            self::takeUp(),
            self::reply(),
            self::resolveForSeller(),
            self::resolvePartially(),
            self::resolveForBuyer(),
            self::closeWithoutDecision(),
        ];
    }

    private static function noteField(string $helper): Textarea
    {
        return Textarea::make('note')
            ->label(__('Your decision'))
            ->rows(3)
            ->required()
            ->minLength(10)
            ->maxLength(1000)
            ->helperText($helper);
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
