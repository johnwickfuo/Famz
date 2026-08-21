<?php

namespace App\Http\Controllers\Jobs;

use App\Services\Uploads\ImageIngest;
use App\Enums\JobListingStatus;
use App\Enums\JobType;
use App\Enums\PayPeriod;
use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Models\EmployerProfile;
use App\Models\JobListing;
use App\Models\WorkerSkill;
use App\Support\Money;
use App\Support\Nigeria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * An employer setting themselves up, posting work, and seeing who applied.
 *
 * Registering as an employer is what unlocks workers' phone numbers, so the
 * form says what that means. Nobody is verified — but somebody who has put
 * their farm's name and number against an account has at least made themselves
 * findable, which is the only leverage this board has.
 */
class EmployerController extends Controller
{
    public function edit(Request $request): Response
    {
        $profile = $request->user()->employerProfile;

        return Inertia::render('Jobs/Employers/Edit', [
            'profile' => $profile === null ? null : [
                'business_name' => $profile->business_name,
                'business_type' => $profile->business_type,
                'state' => $profile->state,
                'lga' => $profile->lga,
                'address' => $profile->address,
                'about' => $profile->about,
                'contact_person' => $profile->contact_person,
                'phone' => $profile->phone,
                'email' => $profile->email,
                'logo_url' => $profile->logoUrl(),
            ],
            'states' => Nigeria::states(),
            'prefill' => [
                'business_name' => $request->user()->displayName(),
                'phone' => $request->user()->profile?->phone,
                'email' => $request->user()->email,
                'state' => $request->user()->profile?->state,
            ],
            'notice' => JobBoardController::notice(),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'business_name' => ['required', 'string', 'max:160'],
            'business_type' => ['nullable', 'string', 'max:120'],
            'state' => ['required', 'string', 'max:64'],
            'lga' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:500'],
            'about' => ['nullable', 'string', 'max:2000'],
            'contact_person' => ['nullable', 'string', 'max:160'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'logo' => ['nullable', 'image', 'max:4096'],
        ]);

        $user = $request->user();

        DB::transaction(function () use ($request, $user, $validated): void {
            $profile = $user->employerProfile ?? new EmployerProfile;

            $profile->fill($validated);
            $profile->user_id = $user->getKey();

            if ($request->hasFile('logo')) {
                $profile->logo = app(ImageIngest::class)->store($request->file('logo'), 'jobs/employers');
            }

            $profile->save();
        });

        if (! $user->holdsRole(RoleName::Employer)) {
            $user->assignRole(RoleName::Employer->value);
        }

        return redirect()
            ->route('jobs.employer.dashboard')
            ->with('success', __('Saved. You can post work and see workers\' contact details now.'));
    }

    /**
     * The employer's listings, and how each is doing.
     */
    public function dashboard(Request $request): Response
    {
        $profile = $request->user()->employerProfile;

        if ($profile === null) {
            return redirect()->route('jobs.employer.edit');
        }

        $listings = $profile->listings()
            ->withCount([
                'applications',
                'applications as new_applications_count' => fn ($q) => $q->where('status', 'applied'),
            ])
            ->with('skills')
            ->get();

        return Inertia::render('Jobs/Employers/Dashboard', [
            'employer' => $profile->publicCard(),
            'listings' => $listings->map(fn (JobListing $listing): array => [
                ...$listing->publicCard(),
                'applications_count' => $listing->applications_count,
                'new_applications_count' => $listing->new_applications_count,
                'views' => $listing->views_count,
                'applicants_url' => route('jobs.applicants', $listing->slug),
                'edit_url' => route('jobs.listings.edit', $listing->slug),
            ])->all(),
            'notice' => JobBoardController::notice(),
        ]);
    }

    /**
     * The listing form.
     */
    public function editListing(Request $request, ?JobListing $listing = null): Response
    {
        if ($listing !== null) {
            Gate::authorize('update', $listing);
            $listing->load('skills');
        }

        abort_if($request->user()->employerProfile === null, 403);

        return Inertia::render('Jobs/Employers/ListingForm', [
            'listing' => $listing === null ? null : [
                'slug' => $listing->slug,
                'title' => $listing->title,
                'description' => $listing->description,
                'job_type' => $listing->job_type->value,
                'positions_available' => $listing->positions_available,
                'state' => $listing->state,
                'lga' => $listing->lga,
                'is_accommodation_provided' => $listing->is_accommodation_provided,
                'is_food_provided' => $listing->is_food_provided,
                'pay_min' => $listing->pay_min_kobo === null ? null : $listing->pay_min_kobo / 100,
                'pay_max' => $listing->pay_max_kobo === null ? null : $listing->pay_max_kobo / 100,
                'pay_period' => $listing->pay_period->value,
                'start_date' => $listing->start_date?->toDateString(),
                'application_deadline' => $listing->application_deadline?->toDateString(),
                'status' => $listing->status->value,
                'skill_ids' => $listing->skills->pluck('id')->all(),
            ],
            'skills' => WorkerSkill::grouped(),
            'states' => Nigeria::states(),
            'jobTypes' => collect(JobType::cases())
                ->map(fn (JobType $case): array => [
                    'value' => $case->value,
                    'label' => $case->label(),
                    'hint' => $case->hint(),
                ])->all(),
            'payPeriods' => PayPeriod::options(),
            'notice' => JobBoardController::notice(),
        ]);
    }

    public function saveListing(Request $request, ?JobListing $listing = null): RedirectResponse
    {
        $employer = $request->user()->employerProfile;

        abort_if($employer === null, 403);

        if ($listing !== null) {
            Gate::authorize('update', $listing);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:8000'],
            'job_type' => ['required', Rule::enum(JobType::class)],
            'positions_available' => ['required', 'integer', 'min:1', 'max:500'],
            'state' => ['required', 'string', 'max:64'],
            'lga' => ['nullable', 'string', 'max:64'],
            'is_accommodation_provided' => ['boolean'],
            'is_food_provided' => ['boolean'],
            'pay_min' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'pay_max' => ['nullable', 'numeric', 'min:0', 'max:100000000', 'gte:pay_min'],
            'pay_period' => ['required', Rule::enum(PayPeriod::class)],
            'start_date' => ['nullable', 'date'],
            'application_deadline' => ['nullable', 'date', 'after_or_equal:today'],
            'skill_ids' => ['array', 'max:12'],
            'skill_ids.*' => ['integer', 'exists:worker_skills,id'],
            'publish' => ['boolean'],
        ], [
            'pay_max.gte' => __('The top of the range cannot be below the bottom of it.'),
        ]);

        $saved = DB::transaction(function () use ($employer, $listing, $validated): JobListing {
            $record = $listing ?? new JobListing;

            $record->fill([
                'title' => $validated['title'],
                'description' => $validated['description'],
                'job_type' => $validated['job_type'],
                'positions_available' => $validated['positions_available'],
                'state' => $validated['state'],
                'lga' => $validated['lga'] ?? null,
                'is_accommodation_provided' => (bool) ($validated['is_accommodation_provided'] ?? false),
                'is_food_provided' => (bool) ($validated['is_food_provided'] ?? false),
                'pay_min_kobo' => isset($validated['pay_min']) ? Money::toKobo($validated['pay_min']) : null,
                'pay_max_kobo' => isset($validated['pay_max']) ? Money::toKobo($validated['pay_max']) : null,
                'pay_period' => $validated['pay_period'],
                'start_date' => $validated['start_date'] ?? null,
                'application_deadline' => $validated['application_deadline'] ?? null,
            ]);

            $record->employer_profile_id = $employer->getKey();

            // Publishing is deliberate and stamps a date. A draft nobody
            // published is not on the board, and must not quietly appear
            // because somebody pressed Save.
            if (($validated['publish'] ?? false) && ! $record->status->isPublic()) {
                $record->status = JobListingStatus::Open;
                $record->published_at = now();
            }

            $record->save();
            $record->skills()->sync($validated['skill_ids'] ?? []);

            return $record;
        });

        return redirect()
            ->route('jobs.employer.dashboard')
            ->with('success', $saved->status->isPublic()
                ? __('Posted. Workers can see it now.')
                : __('Saved as a draft. It is not on the board until you post it.'));
    }

    /**
     * Close a listing, either because it is filled or because it is not.
     */
    public function closeListing(Request $request, JobListing $listing): RedirectResponse
    {
        Gate::authorize('update', $listing);

        $validated = $request->validate([
            'outcome' => ['required', Rule::in([JobListingStatus::Filled->value, JobListingStatus::Closed->value])],
        ]);

        $listing->forceFill([
            'status' => JobListingStatus::from($validated['outcome']),
            'closed_at' => now(),
        ])->save();

        return back()->with('success', __('Closed.'));
    }
}
