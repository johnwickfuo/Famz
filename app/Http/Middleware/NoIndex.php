<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keep a route out of search results.
 *
 * robots.txt asks politely and is honoured by the crawlers that read it; this
 * header is attached to the response itself, so it also covers a page reached
 * through a link somebody pasted into a public group. Neither is a lock — the
 * lock on an invitation page is the token — but a private link that turns up
 * in search results has stopped being private.
 */
class NoIndex
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
