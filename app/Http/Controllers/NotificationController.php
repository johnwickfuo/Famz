<?php

namespace App\Http\Controllers;

use App\Enums\NotificationCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What has happened since somebody last looked.
 *
 * The database half of every notification the platform sends. The email half
 * reaches people who are not looking at the app, which on a market stall is
 * most of the time; this is for when they are.
 */
class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $filter = NotificationCategory::tryFrom((string) $request->query('category', ''));

        $notifications = $request->user()
            ->notifications()
            // Filtered on the stored category, which the base notification
            // stamps on every row. A row written before categories existed has
            // none and simply falls outside a filtered view — which is correct:
            // it belongs to no category rather than to all of them.
            ->when($filter, fn ($query) => $query->where('data->category', $filter->value))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Notifications/Index', [
            'notifications' => collect($notifications->items())
                ->map(fn (DatabaseNotification $notification): array => [
                    'id' => $notification->id,
                    'title' => $notification->data['title'] ?? __('Something happened'),
                    'body' => $notification->data['body'] ?? null,
                    'url' => $notification->data['url'] ?? null,
                    'kind' => $notification->data['kind'] ?? null,
                    'category' => $notification->data['category'] ?? null,
                    'categoryLabel' => NotificationCategory::tryFrom(
                        (string) ($notification->data['category'] ?? '')
                    )?->label(),
                    'at' => $notification->created_at->diffForHumans(),
                    'read' => $notification->read_at !== null,
                ])->all(),
            'pagination' => [
                'links' => $notifications->linkCollection()->toArray(),
                'from' => $notifications->firstItem(),
                'to' => $notifications->lastItem(),
                'total' => $notifications->total(),
            ],
            'unread' => $request->user()->unreadNotifications()->count(),
            'filter' => $filter?->value,
            /*
             * Only the categories this person has actually received something
             * in. A filter bar offering eleven choices where nine return an
             * empty list is a worse experience than no filter bar at all.
             */
            'categories' => $this->availableCategories($request),
        ]);
    }

    /**
     * Mark one read and go where it points.
     */
    public function read(Request $request, string $notification): RedirectResponse
    {
        $record = $request->user()->notifications()->whereKey($notification)->firstOrFail();

        $record->markAsRead();

        return redirect()->to($record->data['url'] ?? route('notifications.index'));
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back()->with('success', __('All caught up.'));
    }

    /**
     * The categories this person has anything in, in the enum's own order.
     *
     * @return array<int, array{value: string, label: string, unread: int}>
     */
    private function availableCategories(Request $request): array
    {
        /*
         * Grouped in PHP rather than in SQL, deliberately.
         *
         * The obvious version of this is a GROUP BY on the category inside the
         * JSON payload, and the obvious way to write that
         * — JSON_UNQUOTE(JSON_EXTRACT(...)) — is MySQL-only. It works in
         * production and throws "no such function" on SQLite, which is what the
         * test suite runs on: a page that passes review and 500s in CI.
         *
         * Laravel's `data->category` where-clause IS portable (it compiles to
         * json_extract on both), but there is no portable equivalent for
         * grouping, and eleven COUNT queries to fill in a filter bar is a poor
         * trade. So: one bounded query, grouped here.
         *
         * The bound is honest rather than arbitrary. This drives a filter bar,
         * not a total, and nobody scrolls past five hundred notifications to
         * discover a category they have not seen in a year.
         */
        $counts = $request->user()
            ->notifications()
            ->select(['data', 'read_at'])
            ->latest()
            ->limit(500)
            ->get()
            ->groupBy(fn ($notification): string => (string) ($notification->data['category'] ?? ''))
            ->map(fn ($group): int => $group->whereNull('read_at')->count());

        return collect(NotificationCategory::sorted())
            ->filter(fn (NotificationCategory $category): bool => $counts->has($category->value))
            ->map(fn (NotificationCategory $category): array => [
                'value' => $category->value,
                'label' => $category->label(),
                'unread' => (int) $counts->get($category->value, 0),
            ])
            ->values()
            ->all();
    }
}
