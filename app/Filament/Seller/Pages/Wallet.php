<?php

namespace App\Filament\Seller\Pages;

use App\Enums\LedgerState;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use App\Support\Money;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * What a seller has earned, and what is still in escrow.
 *
 * Every figure here is summed from the ledger when the page loads. Nothing is
 * cached and nothing is stored, so what a seller sees is always what their
 * entries actually add up to.
 */
class Wallet extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 40;

    protected string $view = 'filament.seller.pages.wallet';

    public static function getNavigationLabel(): string
    {
        return __('Earnings');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Earnings');
    }

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        $wallet = app(WalletService::class);
        $user = auth()->user();

        return [
            'available' => Money::fromKobo($wallet->availableBalance($user)),
            'held' => Money::fromKobo($wallet->heldBalance($user)),
            'lifetime' => Money::fromKobo($wallet->lifetimeEarnings($user)),
            'entries' => $wallet->statement($user, 50)->map(fn (WalletTransaction $entry): array => [
                'date' => $entry->created_at->format('j M Y'),
                'description' => $entry->description,
                'type' => $entry->type->label(),
                'state' => $entry->state->label(),
                'amount' => Money::fromKobo($entry->amount_kobo),
                'positive' => $entry->amount_kobo > 0,
                'held' => $entry->state === LedgerState::Held,
                // The buyer's order, not the seller's own part — the part's
                // reference is already in the description.
                'reference' => $entry->subOrder?->order?->reference,
            ])->all(),
        ];
    }
}
