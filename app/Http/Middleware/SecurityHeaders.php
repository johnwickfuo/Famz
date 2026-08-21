<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * The headers that decide what a browser will let a page do.
 *
 * The Content-Security-Policy is the substantial one, and the reason it is
 * written with a per-request nonce rather than 'unsafe-inline' is worth stating
 * plainly: 'unsafe-inline' turns CSP into a header that looks like a defence
 * and stops nothing. Any injected <script> runs. The whole value of the policy
 * is that a script has to prove it came from us, and a nonce the attacker
 * cannot guess is that proof.
 *
 * Two things on these pages genuinely need inlining: Ziggy's route table and
 * Inertia's page payload, both emitted by the Blade shell. They carry the
 * nonce. Everything else loads from a file.
 *
 * The policy is reported rather than enforced in local development, because a
 * blocked script during development is a blank page with an error in a console
 * nobody has open — and a developer who cannot work will disable the header
 * rather than fix it.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /*
         * Vite mints it, and everything else reads it back from there.
         *
         * One source rather than two. The obvious alternative — generate a
         * nonce here and hand it to Vite in a service provider — does not work:
         * providers boot before middleware runs, so Vite would be handed
         * nothing and would emit tags the policy then blocks. That failure
         * looks like a blank page with a working server behind it.
         */
        Vite::useCspNonce();
        $nonce = (string) Vite::cspNonce();

        // Bound for the Blade shell, which stamps it onto Ziggy's inline script.
        app()->instance('csp-nonce', $nonce);

        $response = $next($request);

        foreach ($this->headers($nonce, $request) as $header => $value) {
            // Never overwritten: a controller that has deliberately set its own
            // header — a file download declaring its disposition — knows
            // something this middleware does not.
            if (! $response->headers->has($header)) {
                $response->headers->set($header, $value);
            }
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $nonce, Request $request): array
    {
        $headers = [
            // Stops a browser guessing that a .jpg is really a script.
            'X-Content-Type-Options' => 'nosniff',

            // Nothing on this platform belongs in somebody else's frame.
            'X-Frame-Options' => 'DENY',

            /*
             * Send the full URL to ourselves, only the origin to anybody else.
             * A referrer carrying /consult/CON-260820-ABC123 to a third party
             * hands over a reference that identifies a person's booking.
             */
            'Referrer-Policy' => 'strict-origin-when-cross-origin',

            // None of these are used, so none of them are permitted.
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',

            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        $policy = $this->contentSecurityPolicy($nonce);

        /*
         * Report-only where the developer is working, enforced everywhere else.
         * A blocked script in production is a bug worth having; in development
         * it is a blank page and a header somebody disables.
         */
        $headers[app()->environment('local') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy'] = $policy;

        /*
         * HSTS only over a real HTTPS connection. Sending it over plain HTTP
         * does nothing, and sending it from a development machine can pin a
         * browser to HTTPS for a hostname that has no certificate — which is a
         * developer locked out of their own laptop for a year.
         */
        if ($request->secure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $headers;
    }

    private function contentSecurityPolicy(string $nonce): string
    {
        $directives = [
            "default-src 'self'",

            /*
             * 'strict-dynamic' lets the bundle load its own chunks without
             * every hashed filename being listed, while still refusing
             * anything the bundle did not itself request. The http: and https:
             * fallbacks are ignored by browsers that understand
             * strict-dynamic and are there for older ones.
             */
            "script-src 'self' 'nonce-{$nonce}' 'strict-dynamic' https: http:",

            /*
             * Styles need 'unsafe-inline' and there is no honest way around it:
             * Vue sets inline styles for transitions and bound style
             * attributes, and a nonce cannot cover an attribute. Inline CSS is
             * a far smaller problem than inline script — it can leak layout,
             * it cannot execute.
             */
            "style-src 'self' 'unsafe-inline'",

            // Uploaded photographs, and data: for the small inlined icons.
            "img-src 'self' data: blob:",

            "font-src 'self' data:",

            // XHR to ourselves only. No analytics, no third-party beacons.
            "connect-src 'self'",

            // Payment providers are reached by redirect, not in a frame.
            "frame-src 'none'",
            "object-src 'none'",

            // Stops an injected <base> rewriting every relative URL on the page.
            "base-uri 'self'",

            // A form on this site posts to this site. This is what turns an
            // injected form into a broken form rather than a credential harvest.
            "form-action 'self'",

            "frame-ancestors 'none'",
        ];

        if (! app()->environment('local')) {
            // Anything that slipped through as http:// gets fetched over https.
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives);
    }
}
