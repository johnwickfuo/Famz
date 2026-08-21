<?php

use App\Mail\PlatformAnnouncementMail;
use App\Services\Branding\BrandingService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Monolog\Formatter\JsonFormatter;

/**
 * The operational guarantees: which queue mail runs on, and what a person sees
 * when the site is down.
 */
it('sends mail on its own queue, away from payments', function (): void {
    Queue::fake();

    Mail::to('somebody@example.test')->send(new PlatformAnnouncementMail('Hello', 'Body'));

    Queue::assertPushedOn('mail', SendQueuedMailable::class);
});

it('keeps the payment queue free of mail', function (): void {
    Queue::fake();

    Mail::to('somebody@example.test')->send(new PlatformAnnouncementMail('Hello', 'Body'));

    /*
     * The point of the separation. A provider that hangs for thirty seconds
     * holds a worker for thirty seconds — on a shared queue that puts a payment
     * confirmation behind however much mail is in front of it.
     */
    Queue::assertPushed(
        SendQueuedMailable::class,
        fn ($job): bool => $job->queue !== 'default' && $job->queue !== null,
    );
});

it('renders a maintenance page in the site design', function (): void {
    $html = view('errors.503')->render();

    expect($html)
        // Inline styles: the page renders when the asset pipeline may not be
        // available, which is exactly when it is needed.
        ->toContain('<style>')
        ->toContain('back shortly')
        // The company name comes from branding like everywhere else.
        ->toContain(app(BrandingService::class)->name());
});

it('survives rendering the maintenance page with settings unreachable', function (): void {
    /*
     * The case that matters. `artisan down --render` pre-renders this, and a
     * database outage is the most likely reason the site is down at all — a
     * maintenance page that throws is a white screen on the worst day.
     */
    breakTheSettingsStore();

    $html = view('errors.503')->render();

    // Degraded, but still branded and still readable.
    expect($html)->toContain(config('app.name'))
        ->and($html)->toContain('back shortly');
});

it('still names the company when branding cannot be read', function (): void {
    breakTheSettingsStore();

    /*
     * The fallback lives in BrandingService and nowhere else. Every template on
     * the platform is forbidden from naming the company, so if this threw, each
     * of them would need a rescue clause of its own and the rule would stop
     * meaning anything.
     */
    $branding = app(BrandingService::class);

    expect($branding->name())->not->toBeEmpty()
        ->and($branding->logoUrl())->toBeNull()
        ->and($branding->initials())->not->toBeEmpty();
});

it('schedules the operational jobs', function (): void {
    $scheduled = collect(app(Schedule::class)->events())
        ->map(fn ($event): string => $event->command ?? '')
        ->implode(' ');

    expect($scheduled)
        ->toContain('ledger:reconcile')
        ->toContain('backup:run')
        ->toContain('backup:clean')
        // The monitor is what notices a backup job that stopped silently.
        ->toContain('backup:monitor')
        ->toContain('backup:verify-restore');
});

it('answers the health check', function (): void {
    $this->get('/health')
        ->assertOk()
        ->assertJson(['status' => 'ok']);
});

it('tells an anonymous caller nothing but the verdict', function (): void {
    $body = $this->get('/health')->json();

    // Which component is failing, and its error text, is operational detail —
    // useful to whoever is on call and useful in a different way to somebody
    // probing the host.
    expect($body)->toBe(['status' => 'ok']);
});

it('gives the detail to a caller holding the token', function (): void {
    config(['platform.health_token' => 'a-secret-token']);

    $body = $this->get('/health?token=a-secret-token')->json();

    expect($body['checks'])->toHaveKeys(['database', 'cache', 'storage', 'queue']);
});

it('answers 503 when a dependency is down', function (): void {
    /*
     * The property that matters: a monitor that only reads status codes has to
     * be able to notice. A 200 with "degraded" in the body is an outage nobody
     * gets paged for.
     */
    Cache::shouldReceive('put')->andThrow(new RuntimeException('redis gone'));
    Cache::shouldReceive('pull')->andReturn(null);

    $this->get('/health')
        ->assertStatus(503)
        ->assertJson(['status' => 'degraded']);
});

it('exempts the health check from rate limiting', function (): void {
    // A monitor checking every minute from one address is exactly the traffic a
    // limiter stops. An alert that fires because the monitor got 429ed is worse
    // than no alert at all.
    for ($i = 0; $i < 70; $i++) {
        $status = $this->get('/health')->getStatusCode();

        expect($status)->not->toBe(429);
    }
});

it('stamps every request with a traceable id', function (): void {
    $response = $this->get('/');

    expect($response->headers->get('X-Request-Id'))->not->toBeEmpty();
});

it('honours a request id supplied upstream', function (): void {
    // A load balancer or CDN in front of the app usually sets one already;
    // minting a second breaks the trail at the edge.
    $this->get('/', ['X-Request-Id' => 'trace-from-the-edge'])
        ->assertHeader('X-Request-Id', 'trace-from-the-edge');
});

it('writes structured logs as one json object per line, carrying the context', function (): void {
    $path = storage_path('logs/context-proof.log');
    @unlink($path);

    config([
        'logging.channels.proof' => [
            'driver' => 'single',
            'path' => $path,
            'formatter' => JsonFormatter::class,
        ],
        'logging.default' => 'proof',
    ]);

    Route::get('/__log-context-proof', function () {
        Log::warning('a thing went wrong', ['order' => 'OR-1']);

        return 'ok';
    })->middleware('web');

    $this->get('/__log-context-proof', ['X-Request-Id' => 'proof-12345']);

    $line = json_decode(trim((string) file_get_contents($path)), true);

    @unlink($path);

    /*
     * Asserted on a written line rather than on the configuration, because the
     * configuration being right and the context never reaching Monolog is a
     * perfectly ordinary way for this to be broken.
     */
    expect($line['context'])->toMatchArray([
        'request_id' => 'proof-12345',
        'route' => '__log-context-proof',
        // The per-call context survives alongside the shared context, which is
        // the half that says which order it was.
        'order' => 'OR-1',
    ]);

    expect(config('logging.channels.structured.formatter'))
        ->toBe(JsonFormatter::class);
});

/**
 * Make settings unreadable the way a real outage would.
 *
 * Both layers, because either one alone leaves the other answering: the cache
 * would serve a warm payload, and an empty cache would reach a working database.
 */
function breakTheSettingsStore(): void
{
    app()->forgetInstance(BrandingService::class);

    Cache::shouldReceive('rememberForever')
        ->andThrow(new RuntimeException('the cache store is unreachable'));
}
