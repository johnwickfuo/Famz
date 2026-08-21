<?php

namespace App\Filament\Admin\Resources\EmailSuppressions;

use App\Filament\Admin\Resources\EmailSuppressions\Pages\ListEmailSuppressions;
use App\Filament\Admin\Resources\EmailSuppressions\Tables\EmailSuppressionsTable;
use App\Models\EmailSuppression;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Addresses the platform has stopped writing to.
 *
 * Worth an administrator's attention rather than being buried in a log: a
 * suppressed seller is a seller who will not hear about their next order, and
 * the first sign is usually them asking why the platform has gone quiet.
 */
class EmailSuppressionResource extends Resource
{
    protected static ?string $model = EmailSuppression::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'email';

    public static function getNavigationLabel(): string
    {
        return __('Undeliverable addresses');
    }

    public static function getModelLabel(): string
    {
        return __('suppressed address');
    }

    /**
     * The count of live suppressions, on the navigation item.
     *
     * A number that climbs is a deliverability problem in progress, and this is
     * the only place anybody would notice it before the provider does.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = EmailSuppression::query()->active()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return EmailSuppression::query()->active()->count() > 0 ? 'warning' : null;
    }

    public static function table(Table $table): Table
    {
        return EmailSuppressionsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        // These arrive from the provider. Typing one in by hand would be a way
        // to silence somebody, not a feature.
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmailSuppressions::route('/'),
        ];
    }
}
