<?php

namespace App\Http\Controllers;

use App\Models\PayoutAccount;
use App\Models\User;
use App\Services\Notifications\NotificationPreferences;
use App\Services\Payouts\PayoutAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One place for everything about somebody's own account.
 *
 * Before this, a seller who wanted to change their bank details went to the
 * seller panel, their password to /profile, and their notification settings
 * nowhere, because there was nowhere. Three different places for "things about
 * me" is how people end up unable to find the one that matters.
 *
 * Sections render only when they apply. Payout accounts appear for somebody who
 * can be paid; showing an empty bank-details form to a buyer who has never sold
 * anything is clutter that makes the useful sections harder to find.
 */
class AccountController extends Controller
{
    public function __construct(
        private readonly NotificationPreferences $preferences,
        private readonly PayoutAccountService $payouts,
    ) {}

    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Account/Edit', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->profile?->phone,
                'state' => $user->profile?->state,
                'city' => $user->profile?->city,
                'emailVerified' => $user->hasVerifiedEmail(),
            ],
            'roles' => $user->getRoleNames()->all(),
            'notificationPreferences' => $this->preferences->summaryFor($user),
            'canBePaid' => $this->canBePaid($user),
            'payoutAccounts' => $this->canBePaid($user) ? $this->payoutAccounts($user) : [],
            'bankOptions' => $this->canBePaid($user) ? $this->payouts->bankOptions() : [],
            'sessions' => $this->sessions($request),
        ]);
    }

    /**
     * Sign out everywhere else.
     *
     * Deliberately all-or-nothing rather than a per-row revoke button. Somebody
     * on this screen is there because they think a stranger has their password,
     * and picking through a list of user agents deciding which line is the
     * stranger is exactly the wrong thing to ask of them at that moment. One
     * button, everything else gone, password re-entered.
     */
    public function destroyOtherSessions(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);

        Auth::logoutOtherDevices($request->string('password')->toString());

        /*
         * logoutOtherDevices rotates the password hash the sessions are
         * validated against, which stops them being usable — but the rows sit
         * in the table until they expire, and a "sessions" list that still
         * shows them reads as if the button did nothing.
         */
        DB::table('sessions')
            ->where('user_id', $request->user()->getKey())
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        return back()->with('success', __('Signed out everywhere else.'));
    }

    /**
     * Whether this person has any way of being owed money.
     *
     * Sellers and mentors. Checked on roles rather than on whether they happen
     * to have a payout account already, so somebody newly approved as a seller
     * can add their first one.
     */
    private function canBePaid(User $user): bool
    {
        return $user->hasAnyRole(['seller', 'mentor']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function payoutAccounts(User $user): array
    {
        return $this->payouts->accountsFor($user)
            ->map(fn (PayoutAccount $account): array => [
                'id' => $account->getKey(),
                'bank' => $account->bank_name,
                // Never the full number. It is not needed to tell two accounts
                // apart, and a full account number on a screen is a full
                // account number in a screenshot.
                'masked' => $account->maskedNumber(),
                'name' => $account->account_name,
                'default' => (bool) $account->is_default,
            ])
            ->all();
    }

    /**
     * Where this account is signed in.
     *
     * Requires the database session driver — Redis sessions cannot be listed
     * per user, so this screen would have to lie or be absent. That is why the
     * driver is what it is.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sessions(Request $request): array
    {
        if (config('session.driver') !== 'database') {
            return [];
        }

        return DB::table('sessions')
            ->where('user_id', $request->user()->getKey())
            ->orderByDesc('last_activity')
            ->limit(20)
            ->get()
            ->map(fn (object $session): array => [
                'id' => $session->id,
                'ip' => $session->ip_address,
                'agent' => $this->describeAgent((string) ($session->user_agent ?? '')),
                'last' => \Illuminate\Support\Carbon::createFromTimestamp($session->last_activity)->diffForHumans(),
                'current' => $session->id === $request->session()->getId(),
            ])
            ->all();
    }

    /**
     * A user agent string, as something a person can recognise.
     *
     * Rough on purpose. The question this answers is "is one of these not me",
     * and "Chrome on Android" answers it. The full string answers nothing and
     * takes four lines to do it on a phone.
     */
    private function describeAgent(string $agent): string
    {
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/'), str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'Chrome') => 'Chrome',
            str_contains($agent, 'Firefox') => 'Firefox',
            str_contains($agent, 'Safari') => 'Safari',
            default => __('Unknown browser'),
        };

        $platform = match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => __('unknown device'),
        };

        return $browser.' · '.$platform;
    }
}
