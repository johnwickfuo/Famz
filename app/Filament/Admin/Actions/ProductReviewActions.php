<?php

namespace App\Filament\Admin\Actions;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Services\Catalogue\ProductPublisher;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Approving and rejecting listings. Built once, used from both the listings
 * table and the listing edit page.
 */
class ProductReviewActions
{
    public static function approve(): Action
    {
        return Action::make('approveListing')
            ->label(__('Approve'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(fn (Product $record): string => __('Put ":name" in the catalogue?', ['name' => $record->name]))
            ->modalDescription(function (Product $record): string {
                $remaining = app(ProductPublisher::class)->listingsUntilAutoApproval($record->seller);

                if ($remaining === null || $remaining > 1) {
                    return __('Buyers will be able to find and buy it.');
                }

                return __('Buyers will be able to find and buy it. This is the seller\'s third approved listing, so their future listings will go live without review.');
            })
            ->visible(fn (Product $record): bool => ! $record->status->countsAsApproved())
            ->authorize(fn (Product $record): bool => auth()->user()->can('review', $record))
            ->action(function (Product $record): void {
                app(ProductPublisher::class)->approve($record, auth()->user());

                Notification::make()
                    ->title(__('Listing approved'))
                    ->body($record->fresh()->status->label())
                    ->success()
                    ->send();
            });
    }

    public static function reject(): Action
    {
        return Action::make('rejectListing')
            ->label(__('Reject'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading(fn (Product $record): string => __('Reject ":name"?', ['name' => $record->name]))
            ->modalSubmitActionLabel(__('Reject listing'))
            ->visible(fn (Product $record): bool => $record->status !== ProductStatus::Rejected)
            ->authorize(fn (Product $record): bool => auth()->user()->can('review', $record))
            ->schema([
                Textarea::make('reason')
                    ->label(__('Why?'))
                    ->helperText(__('Shown to the seller on their listing so they can put it right.'))
                    ->rows(4)
                    ->required()
                    ->minLength(10)
                    ->maxLength(1000),
            ])
            ->action(function (Product $record, array $data): void {
                app(ProductPublisher::class)->reject($record, $data['reason'], auth()->user());

                Notification::make()->title(__('Listing rejected'))->warning()->send();
            });
    }

    /**
     * @return array<int, Action>
     */
    public static function all(): array
    {
        return [self::approve(), self::reject()];
    }
}
