<?php

namespace App\Filament\Admin\Resources\WorkerProfiles;

use App\Filament\Admin\Resources\WorkerProfiles\Pages\ListWorkerProfiles;
use App\Filament\Admin\Resources\WorkerProfiles\Tables\WorkerProfilesTable;
use App\Models\WorkerProfile;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The people on the board looking for work.
 *
 * Phone numbers are deliberately absent from this table. An administrator has
 * no routine reason to read one, and a screen that shows every worker's number
 * in a sortable list is precisely the artefact the whole contact rule exists to
 * prevent — it would not matter that only staff can open it.
 */
class WorkerProfileResource extends Resource
{
    protected static ?string $model = WorkerProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Farm jobs';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'full_name';

    public static function getNavigationLabel(): string
    {
        return __('Workers');
    }

    public static function getModelLabel(): string
    {
        return __('worker');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return WorkerProfilesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkerProfiles::route('/'),
        ];
    }
}
