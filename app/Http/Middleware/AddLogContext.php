<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Give every log line enough context to be worth reading.
 *
 * A line saying "Payment failed" tells you a payment failed. The same line
 * carrying a request id, the user it happened to and the route it happened on
 * can be followed: from the web request, through the queued job that finished
 * the work, to the exception that ended it — because they all share the id.
 *
 * That last part is the reason this exists at all. Most of the money paths on
 * this platform end in a queue worker, in a different process minutes later,
 * and without a shared id there is nothing tying the two halves together.
 */
class AddLogContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) ($request->header('X-Request-Id') ?: Str::uuid());

        Log::shareContext([
            'request_id' => $requestId,
            'user_id' => $request->user()?->getKey(),
            'route' => $request->route()?->getName() ?? $request->path(),
            'ip' => $request->ip(),
        ]);

        $response = $next($request);

        // Echoed back so a report of "it broke at 14:32" can be turned into an
        // exact line rather than a search of that minute.
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
