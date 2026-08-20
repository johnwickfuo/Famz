<?php

namespace App\Http\Controllers\Mentorship;

use App\Http\Controllers\Controller;
use App\Models\MentorProfile;
use App\Models\MentorReview;
use App\Models\MentorshipMatch;
use App\Models\MentorshipPackage;
use App\Models\Specialisation;
use App\Services\Mentorship\MatchRequest;
use App\Services\Mentorship\MentorMatcher;
use App\Support\Money;
use App\Support\Nigeria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finding a mentor.
 *
 * Every page here is built from `MentorProfile::publicCard()`, which has no
 * contact field in it. Somebody can read a mentor's whole profile, compare
 * their packages and see what other clients said, and still have no way to
 * reach them — that is the product, not an oversight.
 */
class MentorDirectoryController extends Controller
{
    public function __construct(private readonly MentorMatcher $matcher) {}

    /**
     * The needs form.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Mentors/Find', [
            'sectors' => Specialisation::sectorOptions(),
            'specialisations' => Specialisation::query()->active()->ordered()->get()
                ->map(fn (Specialisation $tag): array => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'sector' => $tag->sector,
                ])->all(),
            'states' => Nigeria::states(),
            'mentorCount' => MentorProfile::query()->bookable()->count(),
        ]);
    }

    /**
     * Run the match and send them to the shortlist.
     */
    public function match(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'need' => ['required', 'string', 'min:10', 'max:2000'],
            'sector' => ['nullable', 'string', 'max:64'],
            'state' => ['nullable', 'string', 'max:64'],
            'wants_remote' => ['boolean'],
            'wants_in_person' => ['boolean'],
            'budget_min' => ['nullable', 'numeric', 'min:0'],
            'budget_max' => ['nullable', 'numeric', 'min:0'],
            'specialisations' => ['array'],
            'specialisations.*' => ['integer', 'exists:specialisations,id'],
        ], [
            'need.min' => __('Tell us a bit more about what you need — a sentence or two.'),
        ]);

        $match = $this->matcher->run(MatchRequest::fromArray([
            ...$validated,
            'budget_min_kobo' => isset($validated['budget_min']) ? Money::toKobo($validated['budget_min']) : null,
            'budget_max_kobo' => isset($validated['budget_max']) ? Money::toKobo($validated['budget_max']) : null,
        ], $request->user(), $request->session()->getId()));

        return redirect()->route('mentors.shortlist', $match);
    }

    /**
     * The ranked shortlist.
     */
    public function shortlist(Request $request, MentorshipMatch $match): Response
    {
        // A stored run belongs to whoever asked for it. A signed-in client's
        // brief is their business, and a guest's is tied to their session.
        abort_unless($this->maySee($request, $match), 403);

        return Inertia::render('Mentors/Shortlist', [
            'match' => [
                'id' => $match->id,
                'need' => $match->need_description,
                'state' => $match->preferred_state,
                'wants_in_person' => $match->wants_in_person,
                'budget_min' => $match->budget_min_kobo === null ? null : Money::fromKobo($match->budget_min_kobo),
                'budget_max' => $match->budget_max_kobo === null ? null : Money::fromKobo($match->budget_max_kobo),
                'tags' => Specialisation::query()
                    ->whereIn('id', $match->matched_specialisation_ids ?? [])
                    ->pluck('name')->all(),
                // Said out loud rather than hidden: a shortlist built from
                // keywords because the AI was down is still a shortlist, and
                // the client is entitled to know which they are looking at.
                'resolver' => $match->resolver,
                'resolver_label' => $match->resolverLabel(),
            ],
            'mentors' => $this->matcher->present($match),
        ]);
    }

    /**
     * A mentor's public profile.
     */
    public function show(Request $request, MentorProfile $mentor): Response
    {
        abort_unless($mentor->isBookable(), 404);

        $mentor->load(['user', 'specialisations', 'packages' => fn ($q) => $q->where('is_active', true)]);

        return Inertia::render('Mentors/Show', [
            'mentor' => [
                ...$mentor->publicCard(),
                'bio' => $mentor->bio,
                'strengths' => $mentor->strengths,
                'qualifications' => $mentor->qualifications,
            ],
            'packages' => $mentor->packages->map(fn (MentorshipPackage $p): array => $p->card())->all(),
            'reviews' => $mentor->reviews()
                ->published()
                ->with('client')
                ->latest()
                ->limit(20)
                ->get()
                ->map(fn (MentorReview $review): array => $review->card())
                ->all(),
        ]);
    }

    private function maySee(Request $request, MentorshipMatch $match): bool
    {
        if ($match->user_id !== null) {
            return $request->user()?->getKey() === $match->user_id;
        }

        return $match->session_token === null
            || $match->session_token === $request->session()->getId();
    }
}
