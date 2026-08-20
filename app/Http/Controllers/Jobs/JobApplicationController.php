<?php

namespace App\Http\Controllers\Jobs;

use App\Enums\JobApplicationStatus;
use App\Enums\RatingParty;
use App\Http\Controllers\Controller;
use App\Models\JobApplication;
use App\Models\JobListing;
use App\Services\Jobs\ApplicationService;
use App\Services\Jobs\WorkerContactGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Applying for a job, and working through the applicants.
 *
 * The applicant list is the one screen where a worker's phone number appears
 * without the directory's daily allowance being spent. That is deliberate and
 * not a hole: a worker who applies to a job has chosen to give that farm their
 * number. The allowance exists to stop somebody browsing for numbers they were
 * never offered, which is a different act.
 */
class JobApplicationController extends Controller
{
    public function __construct(private readonly ApplicationService $applications) {}

    /**
     * Apply.
     */
    public function store(Request $request, JobListing $listing): RedirectResponse
    {
        Gate::authorize('apply', $listing);

        $validated = $request->validate([
            'cover_message' => ['nullable', 'string', 'max:2000'],
        ]);

        $worker = $request->user()->workerProfile;

        try {
            $this->applications->apply($listing, $worker, $validated['cover_message'] ?? null);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Sent. The employer can see it now — they will contact you directly.'));
    }

    /**
     * Withdraw.
     */
    public function withdraw(Request $request, JobApplication $application): RedirectResponse
    {
        try {
            $this->applications->withdraw($application, $request->user());
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Withdrawn.'));
    }

    /**
     * The applicants for one listing, with their contact details.
     */
    public function index(Request $request, JobListing $listing): Response
    {
        Gate::authorize('viewApplicants', $listing);

        $listing->load(['skills', 'employer']);

        $applications = $listing->applications()
            ->with(['worker.skills', 'ratings'])
            ->get();

        $viewer = $request->user();

        return Inertia::render('Jobs/Applicants', [
            'listing' => $listing->publicCard(),
            'applications' => $applications->map(fn (JobApplication $application): array => [
                'id' => $application->id,
                'status' => $application->status->value,
                'status_label' => $application->status->label(),
                'status_tone' => $application->status->badgeTone(),
                'applied_at' => $application->applied_at?->format('j M Y'),
                'cover_message' => $application->cover_message,
                'worker' => $application->worker?->publicCard(),
                /*
                 * Released because this worker put themselves forward for this
                 * job. Not routed through WorkerContactGuard's allowance: they
                 * offered the number to this farm, which is not the same as
                 * somebody browsing a directory for it.
                 */
                'contact' => $application->worker?->contactFor($viewer),
                'can_rate' => $viewer !== null && $viewer->can('create', $application),
                'has_rated' => $application->hasRatingFrom(RatingParty::Employer),
            ])->all(),
            'statuses' => collect(JobApplicationStatus::employerCases())
                ->mapWithKeys(fn (JobApplicationStatus $case): array => [$case->value => $case->label()])
                ->all(),
            'notice' => JobBoardController::notice(),
        ]);
    }

    /**
     * Move an applicant along.
     */
    public function updateStatus(Request $request, JobApplication $application): RedirectResponse
    {
        $listing = $application->listing;

        abort_if($listing === null, 404);

        Gate::authorize('viewApplicants', $listing);

        $validated = $request->validate([
            'status' => [
                'required',
                Rule::in(collect(JobApplicationStatus::employerCases())->map->value->all()),
            ],
        ]);

        try {
            $this->applications->moveTo(
                $application,
                JobApplicationStatus::from($validated['status']),
                $request->user(),
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Updated.'));
    }
}
