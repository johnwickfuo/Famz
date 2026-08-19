<?php

namespace App\Http\Middleware;

use App\Services\Branding\BrandingService;
use App\Services\Cart\CartService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Shared with every Inertia page. `branding` is how the front end learns
     * the company name and logo — no Vue component may hardcode either.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $branding = app(BrandingService::class);

        return [
            ...parent::share($request),

            'branding' => fn (): array => $branding->payload(),

            'auth' => [
                'user' => fn () => $request->user() === null ? null : [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'display_name' => $request->user()->displayName(),
                    'email' => $request->user()->email,
                    'avatar_url' => $request->user()->profile?->avatarUrl(),
                    'status' => $request->user()->status->value,
                    'roles' => $request->user()->getRoleNames()->all(),
                    'email_verified' => $request->user()->hasVerifiedEmail(),
                ],
            ],

            // The header bell needs this on every page. A count, not a list:
            // fetching the messages themselves on every request would be a
            // query nobody reads on ninety-nine pages out of a hundred.
            'notifications' => [
                'unread' => fn (): int => $request->user()?->unreadNotifications()->count() ?? 0,
            ],

            // The header cart badge needs this on every page.
            'cart' => [
                'count' => fn (): int => app(CartService::class)->count(),
            ],

            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'info' => fn () => $request->session()->get('info'),
            ],

            'ziggy' => fn (): array => [
                'location' => $request->url(),
            ],
        ];
    }
}
