<?php

namespace App\Filament\Admin\Actions;

use App\Models\SellerProfile;
use App\Services\Sellers\SellerApplicationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Approve, reject and request-more-information, built once and used from both
 * the sellers table and the seller edit page so the two can never drift.
 *
 * Every one of them goes through SellerApplicationService, which is what
 * actually grants or removes the seller role and sends the email.
 */
class SellerReviewActions
{
    public static function approve(): Action
    {
        return Action::make('approve')
            ->label(__('Approve'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(fn (SellerProfile $record): string => __('Approve :business?', ['business' => $record->business_name]))
            ->modalDescription(__('This grants the seller role, opens the seller panel and emails the applicant.'))
            ->modalSubmitActionLabel(__('Approve'))
            ->visible(fn (SellerProfile $record): bool => ! $record->isApproved())
            ->authorize(fn (SellerProfile $record): bool => auth()->user()->can('review', $record))
            ->action(function (SellerProfile $record): void {
                app(SellerApplicationService::class)->approve($record, auth()->user());

                Notification::make()
                    ->title(__('Seller approved'))
                    ->body(__(':business can now list products.', ['business' => $record->business_name]))
                    ->success()
                    ->send();
            });
    }

    public static function requestMoreInformation(): Action
    {
        return Action::make('requestMoreInformation')
            ->label(__('Request more info'))
            ->icon(Heroicon::OutlinedQuestionMarkCircle)
            ->color('warning')
            ->modalHeading(__('What else do you need?'))
            ->modalSubmitActionLabel(__('Send request'))
            ->visible(fn (SellerProfile $record): bool => $record->status->isOpenToApplicant())
            ->authorize(fn (SellerProfile $record): bool => auth()->user()->can('review', $record))
            ->schema([
                Textarea::make('notes')
                    ->label(__('What the applicant needs to do'))
                    ->helperText(__('Sent to them verbatim, so write it as you would say it.'))
                    ->rows(4)
                    ->required()
                    ->minLength(10)
                    ->maxLength(1000),
            ])
            ->action(function (SellerProfile $record, array $data): void {
                app(SellerApplicationService::class)
                    ->requestMoreInformation($record, $data['notes'], auth()->user());

                Notification::make()
                    ->title(__('Request sent'))
                    ->body(__('The application stays open so they can update it.'))
                    ->success()
                    ->send();
            });
    }

    public static function reject(): Action
    {
        return Action::make('reject')
            ->label(__('Reject'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading(fn (SellerProfile $record): string => __('Reject :business?', ['business' => $record->business_name]))
            ->modalSubmitActionLabel(__('Reject application'))
            ->visible(fn (SellerProfile $record): bool => $record->status->isOpenToApplicant() || $record->isApproved())
            ->authorize(fn (SellerProfile $record): bool => auth()->user()->can('review', $record))
            ->schema([
                Textarea::make('reason')
                    ->label(__('Why?'))
                    // A "no" without a "why" is not something a trader can act
                    // on, so the reason is required rather than optional.
                    ->helperText(__('Required, and sent to the applicant. Say what they could put right.'))
                    ->rows(4)
                    ->required()
                    ->minLength(10)
                    ->maxLength(1000),
            ])
            ->action(function (SellerProfile $record, array $data): void {
                app(SellerApplicationService::class)->reject($record, $data['reason'], auth()->user());

                Notification::make()
                    ->title(__('Application rejected'))
                    ->body(__('Their live listings have been withdrawn; drafts were left alone.'))
                    ->warning()
                    ->send();
            });
    }

    /**
     * @return array<int, Action>
     */
    public static function all(): array
    {
        return [
            self::approve(),
            self::requestMoreInformation(),
            self::reject(),
        ];
    }
}
