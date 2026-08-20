<?php

namespace App\Services\Ai\Chat;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Who is allowed to ask, and what happens when the day's money runs out.
 *
 * The assistant is free and open to anyone who lands on the site, which is a
 * product decision with a bill attached. Somebody will point a script at it —
 * not out of malice necessarily, sometimes just a badly written scraper — and
 * without this class that costs real money until somebody notices.
 *
 * Three limits, because one is always the wrong one:
 *
 *  - Per account, generously. A farmer working through a problem asks a lot of
 *    questions in an afternoon and should not be cut off for it.
 *  - Per guest session, tighter. An anonymous browser is worth less trust than
 *    an account, and a token costs nothing to discard and remake.
 *  - Per IP, tightest and hourly. This is the one that catches the script,
 *    because rotating session tokens is trivial and rotating addresses is not.
 *
 * And then the budget, which is the important part. When the day's tokens are
 * spent the assistant does NOT start erroring: it drops to answering only from
 * the cache, and says honestly that it is busy when there is no cached answer.
 * A farmer asking a common question at four in the afternoon still gets a real
 * answer, because the common questions are exactly the ones in the cache. An
 * assistant that returned a red error box for the rest of the day would teach
 * people it is unreliable, and they would stop coming back.
 */
class ChatGuard
{
    public const DEFAULT_USER_DAILY = 60;

    public const DEFAULT_GUEST_DAILY = 20;

    public const DEFAULT_IP_HOURLY = 40;

    /**
     * A day's tokens across the whole platform, not per person.
     *
     * Deliberately platform-wide: per-person limits stop one abuser, and this
     * stops a thousand of them, or a genuinely popular day, from producing an
     * invoice nobody approved.
     */
    public const DEFAULT_DAILY_TOKEN_BUDGET = 500_000;

    public function userDailyLimit(): int
    {
        return max(1, (int) settings('ai_user_daily_messages', self::DEFAULT_USER_DAILY));
    }

    public function guestDailyLimit(): int
    {
        return max(1, (int) settings('ai_guest_daily_messages', self::DEFAULT_GUEST_DAILY));
    }

    public function ipHourlyLimit(): int
    {
        return max(1, (int) settings('ai_ip_hourly_messages', self::DEFAULT_IP_HOURLY));
    }

    public function dailyTokenBudget(): int
    {
        return max(0, (int) settings('ai_daily_token_budget', self::DEFAULT_DAILY_TOKEN_BUDGET));
    }

    /**
     * May this person ask, and if so, may we spend money on it?
     */
    public function check(?User $user, ?string $sessionToken, string $ip): ChatDecision
    {
        foreach ($this->limitsFor($user, $sessionToken, $ip) as [$key, $max, $window, $message]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return ChatDecision::blocked($message, RateLimiter::availableIn($key));
            }
        }

        // Over budget is not a refusal. It is a narrower kind of yes.
        if ($this->overBudget()) {
            return ChatDecision::cacheOnly(__('The assistant has answered a great many questions today.'));
        }

        return ChatDecision::allowed();
    }

    /**
     * Spend one message off each allowance.
     *
     * Called only once an answer has actually been produced. Charging for a
     * message that was refused would mean somebody who hit the limit at ten in
     * the morning stayed locked out for the rest of the day by their own
     * retries.
     */
    public function recordMessage(?User $user, ?string $sessionToken, string $ip): void
    {
        foreach ($this->limitsFor($user, $sessionToken, $ip) as [$key, , $window]) {
            RateLimiter::hit($key, $window);
        }
    }

    /**
     * Add to today's spend.
     *
     * A cached answer costs nothing and is recorded as nothing, which is what
     * makes the cache worth having when the budget is tight.
     */
    public function recordSpend(int $tokens): void
    {
        if ($tokens < 1) {
            return;
        }

        $key = $this->budgetKey();

        // `add` first so the counter carries an expiry. An `increment` on a
        // missing key creates one that never expires on some drivers, and a
        // budget counter that never resets locks the assistant into cache-only
        // mode permanently.
        Cache::add($key, 0, now()->endOfDay());
        Cache::increment($key, $tokens);
    }

    public function spentToday(): int
    {
        return (int) Cache::get($this->budgetKey(), 0);
    }

    public function budgetRemaining(): int
    {
        return max(0, $this->dailyTokenBudget() - $this->spentToday());
    }

    public function overBudget(): bool
    {
        $budget = $this->dailyTokenBudget();

        // A budget of zero means no ceiling rather than no service. An
        // administrator who clears the field should get an assistant that
        // works, not one that has silently switched itself off.
        return $budget > 0 && $this->spentToday() >= $budget;
    }

    /**
     * How much of their allowance somebody has left, for the interface.
     *
     * @return array{limit: int, remaining: int, scope: string}
     */
    public function remainingFor(?User $user, ?string $sessionToken, string $ip): array
    {
        $limits = $this->limitsFor($user, $sessionToken, $ip);

        // The one they will actually hit first.
        $tightest = null;

        foreach ($limits as [$key, $max, , , $scope]) {
            $remaining = RateLimiter::remaining($key, $max);

            if ($tightest === null || $remaining < $tightest['remaining']) {
                $tightest = ['limit' => $max, 'remaining' => max(0, $remaining), 'scope' => $scope];
            }
        }

        return $tightest ?? ['limit' => 0, 'remaining' => 0, 'scope' => 'none'];
    }

    /**
     * @return array<int, array{0: string, 1: int, 2: int, 3: string, 4: string}>
     */
    private function limitsFor(?User $user, ?string $sessionToken, string $ip): array
    {
        $limits = [];

        if ($user !== null) {
            $limits[] = [
                'ai:chat:user:'.$user->getKey(),
                $this->userDailyLimit(),
                86400,
                __('You have reached today\'s limit of :count questions. It resets tomorrow — or book a consultation if it is urgent.', [
                    'count' => $this->userDailyLimit(),
                ]),
                'account',
            ];
        } elseif (filled($sessionToken)) {
            $limits[] = [
                'ai:chat:guest:'.sha1((string) $sessionToken),
                $this->guestDailyLimit(),
                86400,
                __('You have used today\'s :count free questions. Create an account for more, or book a consultation.', [
                    'count' => $this->guestDailyLimit(),
                ]),
                'guest',
            ];
        }

        /*
         * The address limit applies to everybody, signed in or not. It is the
         * only one a script cannot walk around by clearing its cookies.
         */
        $limits[] = [
            'ai:chat:ip:'.sha1($ip),
            $this->ipHourlyLimit(),
            3600,
            __('Too many questions from this connection in the last hour. Please wait a little and try again.'),
            'connection',
        ];

        return $limits;
    }

    private function budgetKey(): string
    {
        return 'ai:tokens:'.now()->toDateString();
    }
}
