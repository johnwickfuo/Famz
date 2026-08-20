<?php

namespace App\Filament\Admin\Resources\ActivityLog\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Spatie\Activitylog\Models\Activity;

class ActivitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(25)
            // Eager-loaded: without this the causer column is one query per row.
            ->modifyQueryUsing(fn ($query) => $query->with(['causer', 'subject']))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('When'))
                    ->dateTime('j M Y, H:i')
                    ->sortable(),

                TextColumn::make('causer')
                    ->label(__('Who'))
                    // "System" rather than a blank: a change made by a queued
                    // job or a scheduled command has no signed-in person behind
                    // it, and an empty cell reads like missing data.
                    ->state(fn (Activity $record): string => $record->causer?->name ?? __('System'))
                    ->description(fn (Activity $record): ?string => $record->causer?->email),

                TextColumn::make('log_name')
                    ->label(__('What'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('description')
                    ->label(__('Did'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'created' => 'success',
                        'deleted' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('subject_id')
                    ->label(__('Record'))
                    ->state(fn (Activity $record): string => $record->subject_id === null
                        ? '—'
                        : '#'.$record->subject_id)
                    ->visibleFrom('md'),

                TextColumn::make('changes')
                    ->label(__('Changed'))
                    // Rendered as "status: pending → approved" rather than raw
                    // JSON. The question this table answers is what changed,
                    // and a JSON blob makes somebody parse it in their head.
                    ->state(fn (Activity $record): string => self::describeChanges($record))
                    ->wrap()
                    ->visibleFrom('lg'),
            ])
            ->filters([
                SelectFilter::make('log_name')
                    ->label(__('What'))
                    ->options(fn (): array => Activity::query()
                        ->distinct()
                        ->orderBy('log_name')
                        ->pluck('log_name', 'log_name')
                        ->all()),

                SelectFilter::make('description')
                    ->label(__('Action'))
                    ->options([
                        'created' => __('Created'),
                        'updated' => __('Updated'),
                        'deleted' => __('Deleted'),
                    ]),
            ])
            // Read-only: an audit trail somebody can edit is a table of claims.
            ->recordActions([])
            ->toolbarActions([]);
    }

    private static function describeChanges(Activity $record): string
    {
        // `attribute_changes` is a cast collection in v5 — there is no
        // changes() method, and calling one gets forwarded to the query builder
        // where it fails with a baffling "undefined method" at render time.
        $changes = (array) ($record->attribute_changes?->toArray() ?? []);
        $after = $changes['attributes'] ?? [];
        $before = $changes['old'] ?? [];

        if ($after === []) {
            return '—';
        }

        return collect($after)
            ->map(function (mixed $value, string $key) use ($before): string {
                $from = $before[$key] ?? null;

                return $from === null
                    ? sprintf('%s: %s', $key, self::readable($value))
                    : sprintf('%s: %s → %s', $key, self::readable($from), self::readable($value));
            })
            ->implode(' · ');
    }

    private static function readable(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => json_encode($value) ?: '—',
            $value === null || $value === '' => '—',
            default => (string) $value,
        };
    }
}
