<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * The named rate limits, in one place.
 *
 * Every limiter here is keyed on the signed-in user where there is one and on
 * the address otherwise. That order matters: an address is shared — a whole
 * office, a whole campus, a mobile carrier's NAT — so keying on it first would
 * mean one busy seller locking out everybody on the same network. Keying on the
 * account first means the limit follows the person who is actually doing it.
 *
 * The windows are deliberately generous. A rate limit is there to stop a script
 * and to make a mistake cheap, not to make the platform annoying to use — and
 * the failure mode of a tight limit is a farmer who cannot post the listing
 * they are trying to post, which costs more than the abuse it prevented.
 *
 * Webhooks are deliberately NOT limited. Paystack and Flutterwave retry on
 * failure, and a 429 to a payment provider is a payment that appears not to
 * have been confirmed — the one response this platform must never give them.
 */
class RateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /*
         * Signing in, registering, resetting a password.
         *
         * Tight, and by address as well as by account, because the attack here
         * is somebody trying many passwords against one account OR one password
         * against many accounts, and only the address is common to the second.
         */
        RateLimiter::for('auth', fn (Request $request): array => [
            Limit::perMinute(10)->by($request->ip()),
            Limit::perMinute(5)->by($request->input('email') ?? $request->ip()),
        ]);

        /*
         * Making and answering offers.
         *
         * An offer costs the recipient attention and an email, so a script
         * making hundreds is spam with a price tag on somebody else.
         */
        RateLimiter::for('offers', fn (Request $request): Limit => Limit::perMinute(10)
            ->by($this->identity($request)));

        /*
         * The free assistant.
         *
         * ChatGuard already applies the real limits — three of them, plus a
         * platform-wide token budget — because they need windows and a
         * degradation path a throttle string cannot express. This is a coarse
         * outer bound so a flood never reaches the application at all.
         */
        RateLimiter::for('assistant', fn (Request $request): Limit => Limit::perMinute(20)
            ->by($this->identity($request)));

        /*
         * Public forms: seller applications, consultations, quotation requests,
         * job applications, buyer requests.
         *
         * Each of these creates a record a person then has to read, so the cost
         * of abuse lands on an administrator's morning.
         */
        RateLimiter::for('forms', fn (Request $request): Limit => Limit::perMinute(6)
            ->by($this->identity($request)));

        /*
         * Anything that moves money.
         *
         * Not really an abuse control — the double-spend guards and the ledger
         * do that work. This is a brake on a stuck retry loop in a browser,
         * which is how somebody ends up with four identical orders.
         */
        RateLimiter::for('checkout', fn (Request $request): Limit => Limit::perMinute(12)
            ->by($this->identity($request)));

        /*
         * The API-ish read endpoints Vue polls: progress pings, payment status.
         * Generous, because they are cheap and legitimately frequent.
         */
        RateLimiter::for('polling', fn (Request $request): Limit => Limit::perMinute(60)
            ->by($this->identity($request)));
    }

    /**
     * The account if there is one, the address if there is not.
     *
     * Prefixed so a user id and an address can never collide — id 5 and the
     * address "5" are different things, and a limiter that confused them would
     * be a limiter nobody could reason about.
     */
    private function identity(Request $request): string
    {
        return $request->user()
            ? 'user:'.$request->user()->getKey()
            : 'ip:'.$request->ip();
    }
}
