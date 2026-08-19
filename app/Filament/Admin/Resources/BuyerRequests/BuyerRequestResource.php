<?php

namespace App\Filament\Admin\Resources\BuyerRequests;

use App\Enums\BuyerRequestStatus;
use App\Filament\Admin\Resources\BuyerRequests\Pages\ListBuyerRequests;
use App\Filament\Admin\Resources\BuyerRequests\Pages\ViewBuyerRequest;
use App\Filament\Admin\Resources\BuyerRequests\Tables\BuyerRequestsTable;
use App\Models\BuyerRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The moderation queue for wanted ads.
 *
 * An unmoderated board fills with phone numbers and outright scams faster than
 * anything else on a marketplace, so nothing reaches the public until somebody
 * has read it.
 */
class BuyerRequestResource extends Resource
{
    protected static ?string $model = BuyerRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?int $navigationSort = 25;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationLabel(): string
    {
        return __('Buyer requests');
    }

    public static function getModelLabel(): string
    {
        return __('buyer request');
    }

    public static function getPluralModelLabel(): string
    {
        return __('buyer requests');
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = BuyerRequest::query()->where('status', BuyerRequestStatus::PendingApproval)->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return BuyerRequestsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListBuyerRequests::route('/'),
            'view' => ViewBuyerRequest::route('/{record}'),
        ];
    }
}
