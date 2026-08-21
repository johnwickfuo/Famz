<?php

use App\Http\Middleware\AddLogContext;
use App\Http\Middleware\EnsurePanelRole;
use App\Http\Middleware\EnsureUserIsNotSuspended;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\NoIndex;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Gateway webhooks: no session, no CSRF. The caller is a payment
            // provider and the only authentication is the signature check.
            Route::middleware('api')->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Prepended to both groups, so a request that fails inside another
         * middleware is still logged with its id. Webhooks run on the api
         * group, and they are the requests most worth being able to trace.
         */
        $middleware->prepend(AddLogContext::class);

        $middleware->web(append: [
            SecurityHeaders::class,
            EnsureUserIsNotSuspended::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'panel.role' => EnsurePanelRole::class,
            'noindex' => NoIndex::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Error tracking.
         *
         * Registered unconditionally; with no SENTRY_LARAVEL_DSN set the
         * integration is inert, so a developer's laptop and a CI run report
         * nothing while production reports everything. That is better than
         * wrapping this in an environment check, which is the kind of thing
         * somebody inverts by accident and then nobody hears about an outage.
         */
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
