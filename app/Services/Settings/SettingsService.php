<?php

namespace App\Services\Settings;

use App\Events\SettingsChanged;
use App\Models\Setting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

/**
 * Cached key/value store for everything an administrator can tune without a
 * deploy. The whole table is cached as one entry — it is small, and reading it
 * whole means a page render costs at most one query on a cold cache.
 */
class SettingsService
{
    public const CACHE_KEY = 'settings:all';

    /**
     * Per-request memo so repeated reads in one render never touch the cache
     * driver more than once.
     *
     * @var array<string, array{type: string, value: string|null}>|null
     */
    private ?array $memo = null;

    /**
     * @return array<string, array{type: string, value: string|null}>
     */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        return $this->memo = Cache::rememberForever(self::CACHE_KEY, fn (): array => $this->readFromDatabase());
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $row = $this->all()[$key] ?? null;

        if ($row === null) {
            return $default;
        }

        $value = $this->cast($row['value'], $row['type']);

        // A stored-but-empty value falls back to the caller's default so that
        // "not filled in yet" and "absent" behave the same way.
        if ($value === null || $value === '') {
            return $default;
        }

        return $value;
    }

    public function string(string $key, ?string $default = null): ?string
    {
        $value = $this->get($key, $default);

        return $value === null ? null : (string) $value;
    }

    public function integer(string $key, ?int $default = null): ?int
    {
        $value = $this->get($key, $default);

        return $value === null ? null : (int) $value;
    }

    public function float(string $key, ?float $default = null): ?float
    {
        $value = $this->get($key, $default);

        return $value === null ? null : (float) $value;
    }

    public function boolean(string $key, ?bool $default = null): ?bool
    {
        $value = $this->get($key, $default);

        if ($value === null) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }

    /**
     * @param  array<mixed>|null  $default
     * @return array<mixed>|null
     */
    public function array(string $key, ?array $default = null): ?array
    {
        $value = $this->get($key, $default);

        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : $default;
    }

    public function set(string $key, mixed $value, ?string $type = null, ?string $group = null): void
    {
        $this->write($key, $value, $type, $group);

        $this->flush([$key]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values, ?string $group = null): void
    {
        foreach ($values as $key => $value) {
            $this->write($key, $value, null, $group);
        }

        $this->flush(array_keys($values));
    }

    /**
     * Persist one row without touching the cache, so setMany() can batch.
     */
    private function write(string $key, mixed $value, ?string $type, ?string $group): void
    {
        $existing = $this->all()[$key] ?? null;
        $type ??= $existing['type'] ?? $this->inferType($value);

        $attributes = [
            'type' => $type,
            'value' => $this->serialise($value, $type),
        ];

        // Only stamp the group when the caller names one; re-saving a value
        // must never silently re-file it under a different group.
        if ($group !== null) {
            $attributes['group'] = $group;
        }

        Setting::query()->updateOrCreate(['key' => $key], $attributes);
    }

    public function forget(string $key): void
    {
        Setting::query()->where('key', $key)->delete();

        $this->flush([$key]);
    }

    /**
     * Drop the cache and announce which keys moved.
     *
     * @param  array<int, string>  $keys
     */
    public function flush(array $keys = []): void
    {
        $this->memo = null;

        Cache::forget(self::CACHE_KEY);

        SettingsChanged::dispatch($keys);
    }

    /**
     * @return array<string, array{type: string, value: string|null}>
     */
    private function readFromDatabase(): array
    {
        try {
            return Setting::query()
                ->get(['key', 'type', 'value'])
                ->mapWithKeys(fn (Setting $setting): array => [
                    $setting->key => ['type' => $setting->type, 'value' => $setting->value],
                ])
                ->all();
        } catch (QueryException) {
            // The table is missing — the app is mid-install, or an artisan
            // command is running before migrate. Defaults are the right answer.
            return [];
        }
    }

    private function cast(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'int', 'integer' => (int) $value,
            'float', 'decimal' => (float) $value,
            'bool', 'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json', 'array' => json_decode($value, true) ?? [],
            default => $value,
        };
    }

    private function serialise(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'json', 'array' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'bool', 'boolean' => $value ? '1' : '0',
            default => (string) $value,
        };
    }

    private function inferType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_array($value) => 'json',
            default => 'string',
        };
    }
}
