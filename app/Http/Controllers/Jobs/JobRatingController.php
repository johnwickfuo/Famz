<?php

namespace App\Http\Controllers\Jobs;

use App\Http\Controllers\Controller;
use App\Models\JobApplication;
use App\Services\Jobs\RatingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Rating the other side of a hire.
 *
 * The authorisation is the policy's, called through the gate rather than
 * re-checked here. There is one place in this application that decides whether
 * somebody has earned the right to rate, and a controller quietly agreeing with
 * it would be a second place to keep in step.
 */
class JobRatingController extends Controller
{
    public function __construct(private readonly RatingService $ratings) {}

    public function store(Request $request, JobApplication $application): RedirectResponse
    {
        // Refuses on anything short of a hire the user was actually party to.
        Gate::authorize('create', $application);

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->ratings->leave(
                $application,
                $request->user(),
                (int) $validated['rating'],
                $validated['comment'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with(
            'success',
            __('Thank you. We read every rating before it goes up, so it will not appear straight away.'),
        );
    }
}
