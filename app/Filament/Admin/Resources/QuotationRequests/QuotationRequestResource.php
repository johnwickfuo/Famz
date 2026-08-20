<?php

namespace App\Filament\Admin\Resources\QuotationRequests;

use App\Enums\QuotationRequestStatus;
use App\Filament\Admin\Resources\QuotationRequests\Pages\ListQuotationRequests;
use App\Filament\Admin\Resources\QuotationRequests\Pages\ViewQuotationRequest;
use App\Filament\Admin\Resources\QuotationRequests\RelationManagers\QuotationsRelationManager;
use App\Filament\Admin\Resources\QuotationRequests\Tables\QuotationRequestsTable;
use App\Models\QuotationRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Farm setup requests.
 *
 * The work queue is not "every request" — it is every request whose study fee
 * has cleared. That distinction is the whole economics of this module: writing
 * a proposal is days of costing work, and doing it for everybody who fills in a
 * form is how the service stops being offered. The badge counts paid work
 * waiting, because that is money already taken for work not yet done.
 */
class QuotationRequestResource extends Resource
{
    protected static ?string $model = QuotationRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Farm setup';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getNavigationLabel(): string
    {
        return __('Requests');
    }

    public static function getModelLabel(): string
    {
        return __('quotation request');
    }

    /**
     * Requests come from the public form, never from in here.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * The badge counts paid work nobody has finished.
     *
     * Not every open request: one waiting on a study fee is not work, and a
     * badge that counts enquiries is a number people stop reading. This one
     * counts money already taken against a proposal not yet sent, which is the
     * only number on this screen worth interrupting somebody for.
     */
    public static function getNavigationBadge(): ?string
    {
        $waiting = QuotationRequest::query()->workable()->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Requests whose study fee has cleared and which still need a proposal.');
    }

    public static function table(Table $table): Table
    {
        return QuotationRequestsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            QuotationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuotationRequests::route('/'),
            'view' => ViewQuotationRequest::route('/{record}'),
        ];
    }

    /**
     * @return array<int, QuotationRequestStatus>
     */
    public static function openStatuses(): array
    {
        return [
            QuotationRequestStatus::Submitted,
            QuotationRequestStatus::StudyFeePending,
            QuotationRequestStatus::StudyFeePaid,
            QuotationRequestStatus::InPreparation,
            QuotationRequestStatus::QuoteSent,
        ];
    }
}
