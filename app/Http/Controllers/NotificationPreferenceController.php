<?php

namespace App\Http\Controllers;

use App\Enums\NotificationCategory;
use App\Services\Notifications\NotificationPreferences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Choosing what to be told about.
 *
 * The screen this serves shows a locked switch for the essential categories
 * rather than hiding them, because "why am I still getting these" is a question
 * somebody will ask, and the honest answer — money, disputes and account
 * security always arrive — is better given on the settings screen than in a
 * support reply.
 */
class NotificationPreferenceController extends Controller
{
    public function __construct(private readonly NotificationPreferences $preferences) {}

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*.database' => ['required', 'boolean'],
            'preferences.*.email' => ['required', 'boolean'],
        ]);

        /*
         * Keys are filtered against the enum here as well as inside the
         * service. A posted key that is not a category would otherwise sit in
         * the validated array looking legitimate, and the reader of this method
         * should not have to go and check whether something downstream throws
         * it away.
         */
        $choices = collect($validated['preferences'])
            ->filter(fn ($_, $key): bool => NotificationCategory::tryFrom((string) $key) !== null)
            ->all();

        $this->preferences->save($request->user(), $choices);

        return back()->with('success', __('Saved. This takes effect on the next message.'));
    }
}
