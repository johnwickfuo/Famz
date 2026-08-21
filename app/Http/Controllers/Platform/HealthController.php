<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * What an uptime monitor should actually watch.
 *
 * Laravel's own `/up` answers "did PHP boot", which is worth having and is not
 * enough: the process can boot happily while the database is refusing
 * connections, Redis is down, or the queue workers died three hours ago and
 * nobody has been emailed since. Every one of those is an outage to a person
 * using the site, and none of them shows up as a failed request to `/`.
 *
 * So this checks the four dependencies the platform cannot work without and
 * answers 503 when one is broken, because a monitor that only understands
 * status codes is the kind most people actually run.
 *
 * The detail is behind a token. Which component is failing, and how, is useful
 * to whoever is on call and useful in a different way to somebody probing the
 * host — so an unauthenticated caller gets "ok" or "degraded" and nothing else.
 */
class HealthController extends Controller
{
    /**
     * How long the queue may go unprocessed before that counts as an outage.
     *
     * Long enough not to fire on an ordinary backlog, short enough that a
     * payment confirmation sitting unsent gets noticed within the hour.
     */
    private const QUEUE_STALE_MINUTES = 15;

    public function __invoke(Request $request): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::connection()->getPdo() !== null),
            'cache' => $this->check(function (): bool {
                $probe = 'health:'.uniqid();
                Cache::put($probe, 'ok', 10);

                // Written and read back: a cache that accepts writes and
                // returns nothing is a cache that silently disables itself.
                return Cache::pull($probe) === 'ok';
            }),
            'storage' => $this->check(fn (): bool => Storage::disk('public')->exists('.htaccess')),
            'queue' => $this->check(fn (): bool => $this->queueIsMoving()),
        ];

        $healthy = collect($checks)->every(fn (array $check): bool => $check['ok']);

        $body = ['status' => $healthy ? 'ok' : 'degraded'];

        if ($this->authorised($request)) {
            $body['checks'] = $checks;
            $body['version'] = trim((string) @file_get_contents(base_path('VERSION'))) ?: 'unknown';
        }

        // 503 rather than 200-with-a-body, so a monitor that only reads status
        // codes still notices.
        return response()->json($body, $healthy ? 200 : 503);
    }

    /**
     * @return array{ok: bool, error: string|null}
     */
    private function check(callable $probe): array
    {
        try {
            return ['ok' => (bool) $probe(), 'error' => null];
        } catch (Throwable $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        }
    }

    /**
     * Is anything actually draining the queue?
     *
     * The failure this catches is the quiet one: Supervisor stopped, or every
     * worker died on a bad job, and jobs pile up while the site looks perfectly
     * healthy from the outside. Judged by the age of the oldest waiting job
     * rather than the depth of the queue, because a deep queue that is moving
     * is fine and a single job stuck for an hour is not.
     */
    private function queueIsMoving(): bool
    {
        if (config('queue.default') !== 'database') {
            /*
             * On Redis the jobs live in a list with no timestamps to read, so
             * the honest answer is that this check cannot see it. Failed jobs
             * are still visible, and a sudden pile of them means the same thing.
             */
            return DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subMinutes(self::QUEUE_STALE_MINUTES))
                ->count() < 25;
        }

        $oldest = DB::table('jobs')->min('available_at');

        return $oldest === null
            || now()->timestamp - (int) $oldest < self::QUEUE_STALE_MINUTES * 60;
    }

    private function authorised(Request $request): bool
    {
        $token = (string) config('platform.health_token');

        return $token !== '' && hash_equals($token, (string) $request->query('token', ''));
    }
}
