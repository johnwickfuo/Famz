<?php

namespace App\Filament\Admin\Resources\Certificates;

use App\Filament\Admin\Resources\Certificates\Pages\ListCertificates;
use App\Filament\Admin\Resources\Certificates\Tables\CertificatesTable;
use App\Models\Certificate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Issued certificates.
 *
 * Nothing here edits one. A certificate is a record of what was awarded on a
 * given day and every field on it was frozen then; the only thing that can
 * legitimately happen afterwards is withdrawing it, which is an event with a
 * reason rather than an edit.
 */
class CertificateResource extends Resource
{
    protected static ?string $model = Certificate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static string|UnitEnum|null $navigationGroup = 'Academy';

    protected static ?string $recordTitleAttribute = 'verification_code';

    protected static ?int $navigationSort = 40;

    public static function getNavigationLabel(): string
    {
        return __('Certificates');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return CertificatesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCertificates::route('/'),
        ];
    }
}
