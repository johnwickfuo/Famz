<?php

namespace App\Filament\Admin\Resources\ActivityLog;

use App\Filament\Admin\Resources\ActivityLog\Pages\ListActivities;
use App\Filament\Admin\Resources\ActivityLog\Tables\ActivitiesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Spatie\Activitylog\Models\Activity;
use UnitEnum;

/**
 * Who changed what, and when.
 *
 * Read-only, and that is the whole design. An audit trail somebody can edit is
 * not an audit trail — it is a table of claims. There is no create page, no
 * edit page and no delete action, so the only way a row changes is a database
 * console, which leaves its own trace.
 *
 * Covers the sensitive models only: money, moderation decisions, payout
 * destinations, account status. A log of everything would bury the twelve rows
 * that matter under a million that do not.
 */
class ActivityResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Oversight';

    protected static ?int $navigationSort = 30;

    public static function getNavigationLabel(): string
    {
        return __('Activity log');
    }

    public static function getModelLabel(): string
    {
        return __('activity');
    }

    public static function getPluralModelLabel(): string
    {
        return __('activity');
    }

    /**
     * Nothing is created, edited or deleted from the interface.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return ActivitiesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivities::route('/'),
        ];
    }
}
