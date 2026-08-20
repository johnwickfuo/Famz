<?php

namespace App\Filament\Mentor\Resources\LedgerEntries;

use App\Filament\Mentor\Resources\LedgerEntries\Pages\ListLedgerEntries;
use App\Filament\Seller\Resources\LedgerEntries\LedgerEntryResource as SellerLedgerEntryResource;

/**
 * Every naira in and out, mentorship earnings included. Scoped to the signed-in
 * user, so the platform's own rows — which live under a null user_id — stay out
 * of it exactly as they do on the seller side.
 */
class LedgerEntryResource extends SellerLedgerEntryResource
{
    public static function getNavigationLabel(): string
    {
        return __('Earnings');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLedgerEntries::route('/'),
        ];
    }
}
