<?php

namespace App\Http\Controllers;

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
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->paginate(20);

        return Inertia::render('Notifications/Index', [
            'notifications' => collect($notifications->items())
                ->map(fn (DatabaseNotification $notification): array => [
                    'id' => $notification->id,
                    'title' => $notification->data['title'] ?? __('Something happened'),
                    'body' => $notification->data['body'] ?? null,
                    'url' => $notification->data['url'] ?? null,
                    'kind' => $notification->data['kind'] ?? null,
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
}
