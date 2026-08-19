<?php

namespace App\Http\Controllers\Mentorship;

use App\Enums\ContactMethod;
use App\Http\Controllers\Controller;
use App\Models\Specialisation;
use App\Services\Mentorship\InvitationService;
use App\Support\Nigeria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Becoming a mentor.
 *
 * The only route to a mentor account. It is reachable exclusively through a
 * signed, single-use link an administrator sends by hand: nothing in the
 * navigation points here, robots.txt disallows it, and the NoIndex middleware
 * on the route puts a noindex header on every response regardless.
 *
 * None of that is the actual protection. The actual protection is that the
 * token must exist, be unspent and be unexpired, and it is checked on the GET
 * and again on the POST — a form somebody left open for a week does not become
 * a way in.
 */
class MentorRegistrationController extends Controller
{
    public function __construct(private readonly InvitationService $invitations) {}

    public function create(Request $request, string $token): Response|RedirectResponse
    {
        $invitation = $this->invitations->find($token);

        // A token that never existed is a 404; one that existed and is finished
        // gets a sentence, because that person really was invited and deserves
        // to be told to ask for a new link rather than shown a dead end.
        abort_if($invitation === null, 404);

        if (! $invitation->isOpen()) {
            return Inertia::render('Mentors/JoinClosed', [
                'reason' => $invitation->refusalReason(),
            ]);
        }

        return Inertia::render('Mentors/Join', [
            'token' => $invitation->token,
            'invitation' => [
                'email' => $invitation->email,
                'name' => $invitation->name,
                'note' => $invitation->note,
                'expires' => $invitation->expiresIn(),
            ],
            'specialisations' => $this->specialisationOptions(),
            'contactMethods' => collect(ContactMethod::cases())
                ->map(fn (ContactMethod $method): array => [
                    'value' => $method->value,
                    'label' => $method->label(),
                    'value_label' => $method->valueLabel(),
                    'placeholder' => $method->placeholder(),
                ])->all(),
            'states' => Nigeria::states(),
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->invitations->find($token);

        abort_if($invitation === null, 404);

        // Checked again on submit. The GET may have happened a week ago, and
        // in between somebody else may have used the same forwarded link.
        if (! $invitation->isOpen()) {
            return back()->with('error', $invitation->refusalReason());
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:32'],
            'state' => ['nullable', 'string', 'max:64'],
            'lga' => ['nullable', 'string', 'max:64'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],

            'headline' => ['required', 'string', 'max:160'],
            'bio' => ['required', 'string', 'max:4000'],
            // The paragraph AND the tags. The paragraph is what a client reads
            // and what the AI layer reasons over; the tags are what a shortlist
            // is actually built from, so neither substitutes for the other.
            'strengths' => ['required', 'string', 'max:2000'],
            'specialisations' => ['required', 'array', 'min:1'],
            'specialisations.*' => ['integer', 'exists:specialisations,id'],

            'years_experience' => ['required', 'integer', 'min:0', 'max:80'],
            'qualifications' => ['nullable', 'string', 'max:2000'],
            'affiliation' => ['nullable', 'string', 'max:255'],

            'preferred_contact_method' => ['required', Rule::in(ContactMethod::values())],
            'contact_value' => ['required', 'string', 'max:255'],

            'states_served' => ['array'],
            'states_served.*' => ['string', 'max:64'],
            'accepts_remote' => ['boolean'],
            'accepts_in_person' => ['boolean'],
        ], [
            'specialisations.required' => __('Tick at least one thing you can help with.'),
        ]);

        try {
            $profile = $this->invitations->register(
                $invitation,
                $validated,
                array_map('intval', $validated['specialisations']),
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        Auth::login($profile->user);

        return redirect()
            ->route('mentors.pending')
            ->with('success', __('You are registered. An administrator will review your profile shortly.'));
    }

    /**
     * The waiting room, for a mentor whose profile has not been approved yet.
     */
    public function pending(Request $request): Response
    {
        $profile = $request->user()?->mentorProfile;

        abort_if($profile === null, 404);

        return Inertia::render('Mentors/Pending', [
            'status' => $profile->status->value,
            'statusLabel' => $profile->status->label(),
            'note' => $profile->status_note,
            'panelUrl' => $profile->isBookable() ? url(config('platform.panels.mentor', 'mentor')) : null,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function specialisationOptions(): array
    {
        return Specialisation::query()
            ->active()
            ->ordered()
            ->get()
            ->groupBy('sector')
            ->map(fn ($tags, string $sector): array => [
                'sector' => $sector,
                'label' => str($sector)->headline()->value(),
                'tags' => $tags->map(fn (Specialisation $tag): array => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'description' => $tag->description,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
