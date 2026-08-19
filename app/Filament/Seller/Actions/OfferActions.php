<?php

namespace App\Filament\Seller\Actions;

use App\Models\Offer;
use App\Services\Offers\OfferService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

/**
 * Answering an offer: yes, no, or how about this.
 *
 * Only shown on offers waiting on the person looking. An offer this seller
 * made and is waiting on somebody else for is theirs to withdraw, not to
 * accept.
 */
class OfferActions
{
    public static function accept(): Action
    {
        return Action::make('acceptOffer')
            ->label(__('Accept'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Offer $record): bool => self::isMineToAnswer($record))
            ->modalHeading(__('Accept this offer?'))
            ->schema([
                Placeholder::make('deal')
                    ->label(__('What you are agreeing to'))
                    ->content(fn (Offer $record): string => __(':quantity at :price each — :total in total.', [
                        'quantity' => $record->quantity,
                        'price' => $record->unitPrice(),
                        'total' => $record->totalPrice(),
                    ])),

                Placeholder::make('next')
                    ->label(__('What happens next'))
                    ->content(fn (): string => __(
                        'The buyer gets a private link to pay at this price, good for :hours hours, and that much stock is held for them until they use it.',
                        ['hours' => app(OfferService::class)->checkoutWindowHours()],
                    )),
            ])
            ->action(fn (Offer $record) => self::run(
                fn () => app(OfferService::class)->accept($record, auth()->user()),
                __('Agreed. The buyer has been sent a link to pay.'),
            ));
    }

    public static function counter(): Action
    {
        return Action::make('counterOffer')
            ->label(__('Come back with a price'))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('info')
            ->visible(fn (Offer $record): bool => self::isMineToAnswer($record))
            ->modalHeading(__('Your price'))
            ->modalSubmitActionLabel(__('Send it'))
            ->schema([
                Placeholder::make('theirs')
                    ->label(__('They offered'))
                    ->content(fn (Offer $record): string => __(':quantity at :price each', [
                        'quantity' => $record->quantity,
                        'price' => $record->unitPrice(),
                    ])),

                TextInput::make('quantity')
                    ->label(__('How many'))
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->default(fn (Offer $record): int => $record->quantity),

                TextInput::make('unit_price')
                    ->label(__('Your price each'))
                    ->numeric()
                    ->prefix('₦')
                    ->required()
                    ->default(fn (Offer $record): float => $record->unit_price_kobo / 100),

                Textarea::make('message')
                    ->label(__('Anything to say'))
                    ->rows(3)
                    ->maxLength(1000)
                    // A bare number reads as a refusal; a sentence keeps the
                    // conversation going.
                    ->helperText(__('A line about why tends to close the gap faster than the number alone.')),
            ])
            ->action(fn (Offer $record, array $data) => self::run(
                fn () => app(OfferService::class)->counter(
                    $record,
                    auth()->user(),
                    (int) $data['quantity'],
                    Money::toKobo($data['unit_price']),
                    $data['message'] ?? null,
                ),
                __('Your price is with them.'),
            ));
    }

    public static function reject(): Action
    {
        return Action::make('rejectOffer')
            ->label(__('Turn it down'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Offer $record): bool => self::isMineToAnswer($record))
            ->schema([
                Textarea::make('message')
                    ->label(__('Why, if you want to say'))
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->action(fn (Offer $record, array $data) => self::run(
                fn () => app(OfferService::class)->reject($record, auth()->user(), $data['message'] ?? null),
                __('Turned down.'),
            ));
    }

    /**
     * An offer this seller made, on somebody's request, that nobody has
     * answered yet.
     */
    public static function withdraw(): Action
    {
        return Action::make('withdrawOffer')
            ->label(__('Take it back'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn (Offer $record): bool => $record->isOpen()
                && $record->initiator_id === auth()->id())
            ->action(fn (Offer $record) => self::run(
                fn () => app(OfferService::class)->withdraw($record, auth()->user()),
                __('Taken back.'),
            ));
    }

    /**
     * @return array<int, Action>
     */
    public static function all(): array
    {
        return [self::accept(), self::counter(), self::reject(), self::withdraw()];
    }

    private static function isMineToAnswer(Offer $offer): bool
    {
        return $offer->isOpen() && $offer->responder_id === auth()->id();
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
