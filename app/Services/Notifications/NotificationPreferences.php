<?php

namespace App\Services\Notifications;

use App\Enums\NotificationCategory;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Which channels a given person gets a given kind of message on.
 *
 * Three inputs collapse into one answer here, and keeping that in one place is
 * the whole point: the category's defaults, whatever the user has stored, and
 * the essential-category override that no preference can switch off.
 *
 * Read on every notification send, so it is cached per user and busted on
 * write. A queued notification job that fires a preferences query per recipient
 * would turn a hundred-recipient announcement into two hundred queries.
 */
class NotificationPreferences
{
    private const CACHE_TTL_SECONDS = 3600;

    /**
     * The channels to deliver on, ready to return from `via()`.
     *
     * @return array<int, string>
     */
    public function channelsFor(User $user, NotificationCategory $category): array
    {
        $settings = $this->for($user, $category);

        return array_values(array_filter([
            $settings['database'] ? 'database' : null,
            $settings['email'] ? 'mail' : null,
        ]));
    }

    /**
     * @return array{database: bool, email: bool, essential: bool}
     */
    public function for(User $user, NotificationCategory $category): array
    {
        $stored = $this->all($user)[$category->value] ?? null;

        return [
            'database' => $stored['database'] ?? $category->databaseByDefault(),
            /*
             * The override. An essential category ignores whatever is stored —
             * including a row written before the category became essential, and
             * including one written by somebody who has since changed their
             * mind about wanting to know their money moved.
             */
            'email' => $category->isEssential()
                ? true
                : ($stored['email'] ?? $category->emailByDefault()),
            'essential' => $category->isEssential(),
        ];
    }

    /**
     * Everything this person has stored, keyed by category.
     *
     * @return array<string, array{database: bool, email: bool}>
     */
    public function all(User $user): array
    {
        return Cache::remember(
            $this->cacheKey($user),
            self::CACHE_TTL_SECONDS,
            fn (): array => NotificationPreference::query()
                ->where('user_id', $user->getKey())
                ->get()
                ->mapWithKeys(fn (NotificationPreference $row): array => [
                    $row->category->value => [
                        'database' => (bool) $row->database,
                        'email' => (bool) $row->email,
                    ],
                ])
                ->all(),
        );
    }

    /**
     * Everything the preferences screen needs to render itself.
     *
     * Built from the enum rather than from stored rows, so a category added in
     * code appears immediately for everybody rather than only for people who
     * have saved the form since.
     *
     * @return array<int, array<string, mixed>>
     */
    public function summaryFor(User $user): array
    {
        return array_map(function (NotificationCategory $category) use ($user): array {
            $settings = $this->for($user, $category);

            return [
                'category' => $category->value,
                'label' => $category->label(),
                'description' => $category->description(),
                'database' => $settings['database'],
                'email' => $settings['email'],
                // The screen shows a locked switch and says why, rather than a
                // live one that silently does nothing.
                'essential' => $settings['essential'],
            ];
        }, NotificationCategory::sorted());
    }

    /**
     * Save a set of choices.
     *
     * @param  array<string, array{database?: bool, email?: bool}>  $choices
     */
    public function save(User $user, array $choices): void
    {
        foreach ($choices as $value => $channels) {
            $category = NotificationCategory::tryFrom((string) $value);

            if ($category === null) {
                continue;
            }

            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $user->getKey(), 'category' => $category->value],
                [
                    'database' => (bool) ($channels['database'] ?? $category->databaseByDefault()),
                    /*
                     * An essential category is stored as true whatever arrived
                     * in the form. The switch is disabled in the interface, but
                     * a disabled input is a suggestion to anybody posting the
                     * form directly, and this is the enforcement.
                     */
                    'email' => $category->isEssential()
                        ? true
                        : (bool) ($channels['email'] ?? $category->emailByDefault()),
                ],
            );
        }

        $this->flush($user);
    }

    public function flush(User $user): void
    {
        Cache::forget($this->cacheKey($user));
    }

    private function cacheKey(User $user): string
    {
        return 'notification-preferences:'.$user->getKey();
    }
}
