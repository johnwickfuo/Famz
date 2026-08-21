<?php

use Symfony\Component\Finder\Finder;

/**
 * .env.example, kept honest.
 *
 * The failure this prevents is quiet and expensive: somebody adds env('X') in a
 * config file, it works on their machine because their own .env has it, and the
 * next deployment silently takes the default. Nobody finds out until the thing
 * X controlled turns out not to be switched on.
 *
 * So rather than documenting the file once and hoping, this reads every
 * env() call the application makes and requires each one to appear.
 */

/**
 * Framework and package knobs nobody sets by hand.
 *
 * These come from Laravel's own config files and from installed packages —
 * Sentry alone reads forty. Documenting them would bury the twenty variables
 * this platform actually needs among two hundred that only matter to somebody
 * already reading the package's source.
 *
 * @return array<int, string>
 */
function frameworkNoise(): array
{
    return [
        '/^SENTRY_(?!LARAVEL_DSN|TRACES_SAMPLE_RATE)/',
        '/^(BEANSTALKD|DYNAMODB|MEMCACHED|SQS|PAPERTRAIL|SLACK_|INERTIA_)/',
        '/^(AUTH_|BROADCAST_|OCTANE_|SCOUT_|PUSHER_|VITE_)/',
        '/^DB_(CACHE|QUEUE|CHARSET|COLLATION|ENCRYPT|FOREIGN|SOCKET|SSLMODE|TRUST|URL)/',
        '/^REDIS_(BACKOFF|MAX|PERSISTENT|CLUSTER|URL|USERNAME|CACHE_(CONNECTION|LOCK)|QUEUE_(CONNECTION|RETRY))/',
        '/^(CACHE_STORAGE|QUEUE_FAILED|MYSQL_ATTR)/',
        '/^LOG_(PAPERTRAIL|SLACK|STDERR|SYSLOG|DEPRECATIONS_TRACE)/',
        '/^SESSION_(CONNECTION|STORE|TABLE|EXPIRE|PARTITIONED)/',
        '/^MAIL_(SENDMAIL|URL|LOG_CHANNEL|SCHEME|EHLO)/',
        '/^APP_(PREVIOUS|MAINTENANCE_STORE)/',
        '/^AWS_(ENDPOINT|URL)/',
    ];
}

it('documents every environment variable the application reads', function (): void {
    $finder = (new Finder)
        ->files()
        ->in([base_path('app'), base_path('config'), base_path('bootstrap'), base_path('routes')])
        ->name('*.php');

    $used = [];

    foreach ($finder as $file) {
        preg_match_all("/env\(\s*['\"]([A-Z0-9_]+)['\"]/", $file->getContents(), $matches);

        foreach ($matches[1] as $variable) {
            $used[$variable] = true;
        }
    }

    $documented = [];
    preg_match_all('/^([A-Z0-9_]+)=/m', (string) file_get_contents(base_path('.env.example')), $matches);
    foreach ($matches[1] as $variable) {
        $documented[$variable] = true;
    }

    $missing = collect(array_keys($used))
        ->reject(fn (string $variable): bool => isset($documented[$variable]))
        ->reject(fn (string $variable): bool => collect(frameworkNoise())
            ->contains(fn (string $pattern): bool => preg_match($pattern, $variable) === 1))
        ->sort()
        ->values()
        ->all();

    expect($missing)->toBe([], "Read by the application but absent from .env.example:\n".implode("\n", $missing));
});

it('would notice an undocumented variable', function (): void {
    /*
     * The control. The exclusion list above is broad enough that a scanner
     * which had silently stopped matching anything would still pass, so this
     * checks a name of the shape a real one takes is not swallowed by it.
     */
    $swallowed = collect(frameworkNoise())
        ->contains(fn (string $pattern): bool => preg_match($pattern, 'PAYSTACK_SECRET_KEY') === 1);

    expect($swallowed)->toBeFalse();
});

it('ships no real credential in the example', function (): void {
    $example = (string) file_get_contents(base_path('.env.example'));

    // Every secret-shaped line must be empty. A committed key is a key that
    // has to be rotated, and nobody notices for months.
    foreach (['PAYSTACK_SECRET_KEY', 'FLUTTERWAVE_SECRET_KEY', 'GEMINI_API_KEY', 'SUPER_ADMIN_PASSWORD', 'BACKUP_ARCHIVE_PASSWORD', 'MAIL_PASSWORD', 'HEALTH_CHECK_TOKEN'] as $secret) {
        expect($example)->toMatch("/^{$secret}=\s*$/m");
    }
});

it('keeps the session cookie secure by default', function (): void {
    $example = (string) file_get_contents(base_path('.env.example'));

    /*
     * Defaults matter more than documentation here: somebody copying this file
     * to production and changing only the database credentials should still get
     * a cookie that will not travel over plain http.
     */
    expect($example)->toMatch('/^SESSION_SECURE_COOKIE=true$/m')
        ->and($example)->toMatch('/^SESSION_HTTP_ONLY=true$/m');
});

it('documents every settings key in the README', function (): void {
    $this->seed(\Database\Seeders\SettingsSeeder::class);

    $readme = (string) file_get_contents(base_path('README.md'));

    /*
     * A settings key is a lever the client is expected to pull. One that exists
     * and is written down nowhere is a lever nobody knows about — which in
     * practice means it keeps whatever value the seeder gave it forever.
     */
    $undocumented = \App\Models\Setting::query()
        ->pluck('key')
        ->reject(fn (string $key): bool => str_contains($readme, "`{$key}`"))
        ->sort()
        ->values()
        ->all();

    expect($undocumented)->toBe([], "Settings keys missing from the README:\n".implode("\n", $undocumented));
});
