<?php

namespace App\Filament\Admin\Resources\Consultations;

use App\Enums\ConsultationStatus;
use App\Filament\Admin\Resources\Consultations\Pages\ListConsultations;
use App\Filament\Admin\Resources\Consultations\Pages\ViewConsultation;
use App\Filament\Admin\Resources\Consultations\RelationManagers\FollowupsRelationManager;
use App\Filament\Admin\Resources\Consultations\RelationManagers\ReportsRelationManager;
use App\Filament\Admin\Resources\Consultations\Tables\ConsultationsTable;
use App\Models\Consultation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The consultation queue.
 *
 * This is a queue before it is a list: the promise made on the booking form is
 * a response time, and the only way that promise is kept is if the person
 * opening this screen can see at a glance who is late and who is next.
 * Everything about the default sort, the badge and the row colours serves that.
 */
class ConsultationResource extends Resource
{
    protected static ?string $model = Consultation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static string|UnitEnum|null $navigationGroup = 'Consultations';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getNavigationLabel(): string
    {
        return __('Requests');
    }

    public static function getModelLabel(): string
    {
        return __('consultation');
    }

    /**
     * Consultations are booked by the public, never created in here.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * The badge counts what is LATE, not what is waiting.
     *
     * A badge showing every open consultation is a number nobody reads after
     * the first week. A badge that is usually zero and occasionally red is a
     * number people act on.
     */
    public static function getNavigationBadge(): ?string
    {
        $overdue = Consultation::query()->overdue()->count();

        return $overdue > 0 ? (string) $overdue : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Consultations past the response time we promised.');
    }

    public static function table(Table $table): Table
    {
        return ConsultationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ReportsRelationManager::class,
            FollowupsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConsultations::route('/'),
            'view' => ViewConsultation::route('/{record}'),
        ];
    }

    /**
     * @return array<int, ConsultationStatus>
     */
    public static function openStatuses(): array
    {
        return [
            ConsultationStatus::Submitted,
            ConsultationStatus::Contacted,
            ConsultationStatus::Quoted,
            ConsultationStatus::AwaitingPayment,
            ConsultationStatus::Paid,
            ConsultationStatus::InProgress,
        ];
    }
}
