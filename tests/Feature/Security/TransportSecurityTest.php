<?php

use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;

use function Pest\Laravel\get;

/**
 * The headers, the cookies, and the absence of secrets in the repository.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);
});

it('sends the security headers on every page', function (string $header): void {
    get('/')->assertHeader($header);
})->with([
    'X-Content-Type-Options',
    'X-Frame-Options',
    'Referrer-Policy',
    'Permissions-Policy',
]);

it('sends a content security policy with a per-request nonce', function (): void {
    $first = get('/')->headers->get('Content-Security-Policy')
        ?? get('/')->headers->get('Content-Security-Policy-Report-Only');

    expect($first)->toContain("script-src")
        ->and($first)->toContain('nonce-')
        // The directives that do the real work.
        ->and($first)->toContain("object-src 'none'")
        ->and($first)->toContain("base-uri 'self'")
        ->and($first)->toContain("form-action 'self'")
        ->and($first)->toContain("frame-ancestors 'none'");
});

it('uses a different nonce on every request', function (): void {
    $nonceOf = function (): string {
        $header = get('/')->headers->get('Content-Security-Policy')
            ?? get('/')->headers->get('Content-Security-Policy-Report-Only');

        preg_match("/'nonce-([^']+)'/", (string) $header, $matches);

        return $matches[1] ?? '';
    };

    // A nonce reused across requests is a nonce an attacker reads from one
    // page and replays on the next, which is no protection at all.
    expect($nonceOf())->not->toBe($nonceOf());
});

it('never permits unsafe-inline script', function (): void {
    $policy = get('/')->headers->get('Content-Security-Policy')
        ?? get('/')->headers->get('Content-Security-Policy-Report-Only');

    /*
     * The single line that decides whether this header is a defence or
     * decoration. With 'unsafe-inline' in script-src, any injected <script>
     * runs and the policy stops nothing.
     */
    preg_match("/script-src[^;]*/", (string) $policy, $matches);

    expect($matches[0] ?? '')->not->toContain("'unsafe-inline'");
});

it('puts the nonce on the inline script the shell emits', function (): void {
    $html = get('/')->getContent();

    // Ziggy's route table is inline. Without the nonce it does not run, and
    // without Ziggy the whole front end fails on its first route() call.
    expect($html)->toMatch('/<script[^>]+nonce="[^"]+"/');
});

it('keeps cookies http-only', function (): void {
    // Configured rather than asserted on a response, because the session
    // cookie is only set once a session is started.
    expect(config('session.http_only'))->toBeTrue()
        ->and(config('session.same_site'))->toBe('lax');
});

it('commits no environment file', function (): void {
    expect(file_exists(base_path('.env')) && ! str_contains(
        (string) file_get_contents(base_path('.gitignore')),
        '.env'
    ))->toBeFalse();
});

it('ships an example environment with no real values', function (): void {
    $example = (string) file_get_contents(base_path('.env.example'));

    // A committed APP_KEY is a committed encryption key: every session cookie
    // and every encrypted column on every deployment using it is readable.
    expect($example)->toMatch('/APP_KEY=\s*$/m');

    foreach (['sk_live', 'pk_live', 'FLWSECK-', 'AIza'] as $shape) {
        expect($example)->not->toContain($shape);
    }
});

it('refuses to execute anything under the uploads directory', function (): void {
    /*
     * storage/app/public is symlinked into public/, so the web server serves
     * it. Every image is re-encoded on the way in, but a single missed upload
     * path would otherwise turn a photograph into remote code execution.
     */
    $htaccess = storage_path('app/public/.htaccess');

    expect(file_exists($htaccess))->toBeTrue();

    $contents = (string) file_get_contents($htaccess);

    expect($contents)->toContain('SetHandler none')
        ->and($contents)->toContain('php');
});
