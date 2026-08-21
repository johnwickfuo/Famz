<?php

namespace App\Http\Controllers\Jobs;

use App\Services\Uploads\ImageIngest;
use App\Enums\JobApplicationStatus;
use App\Enums\PayPeriod;
use App\Enums\RatingParty;
use App\Enums\RoleName;
use App\Enums\WorkerAvailability;
use App\Enums\WorkTypeWanted;
use App\Http\Controllers\Controller;
use App\Models\JobApplication;
use App\Models\WorkerProfile;
use App\Models\WorkerSkill;
use App\Support\Money;
use App\Support\Nigeria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A worker setting themselves up, and seeing where their applications got to.
 *
 * Self-registration with no verification step, which is the honest design: the
 * company does not check anybody and every page says so. What the platform can
 * offer instead is a record — who applied where, who hired whom, and what each
 * side said afterwards.
 */
class WorkerProfileController extends Controller
{
    /**
     * The form, for creating or editing.
     */
    public function edit(Request $request): Response
    {
        $profile = $request->user()->workerProfile;

        return Inertia::render('Jobs/Workers/Edit', [
            'profile' => $profile === null ? null : [
                'full_name' => $profile->full_name,
                // Their own number, shown back to them. contactFor() allows
                // this because a worker owns their own record.
                'phone' => $profile->phone,
                'whatsapp' => $profile->whatsapp,
                'state' => $profile->state,
                'lga' => $profile->lga,
                'willing_to_relocate' => $profile->willing_to_relocate,
                'work_type_wanted' => $profile->work_type_wanted->value,
                'years_experience' => $profile->years_experience,
                'expected_pay_min' => $profile->expected_pay_min_kobo === null ? null : $profile->expected_pay_min_kobo / 100,
                'expected_pay_max' => $profile->expected_pay_max_kobo === null ? null : $profile->expected_pay_max_kobo / 100,
                'pay_period' => $profile->pay_period->value,
                'availability' => $profile->availability->value,
                'about' => $profile->about,
                'is_open_to_work' => $profile->is_open_to_work,
                'photo_url' => $profile->photoUrl(),
                'skill_ids' => $profile->skills->pluck('id')->all(),
            ],
            'skills' => WorkerSkill::grouped(),
            'states' => Nigeria::states(),
            'workTypes' => WorkTypeWanted::options(),
            'payPeriods' => PayPeriod::options(),
            'availabilities' => WorkerAvailability::options(),
            'prefill' => [
                'full_name' => $request->user()->displayName(),
                'phone' => $request->user()->profile?->phone,
                'state' => $request->user()->profile?->state,
                'lga' => $request->user()->profile?->lga,
            ],
            'notice' => JobBoardController::notice(),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'max:32'],
            'whatsapp' => ['nullable', 'string', 'max:32'],
            'state' => ['required', 'string', 'max:64'],
            'lga' => ['nullable', 'string', 'max:64'],
            'willing_to_relocate' => ['boolean'],
            'work_type_wanted' => ['required', Rule::enum(WorkTypeWanted::class)],
            'years_experience' => ['required', 'integer', 'min:0', 'max:70'],
            'expected_pay_min' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'expected_pay_max' => ['nullable', 'numeric', 'min:0', 'max:100000000', 'gte:expected_pay_min'],
            'pay_period' => ['required', Rule::enum(PayPeriod::class)],
            'availability' => ['required', Rule::enum(WorkerAvailability::class)],
            'about' => ['nullable', 'string', 'max:2000'],
            'is_open_to_work' => ['boolean'],
            'photo' => ['nullable', 'image', 'max:4096'],
            'skill_ids' => ['array', 'max:12'],
            'skill_ids.*' => ['integer', 'exists:worker_skills,id'],
        ], [
            'expected_pay_max.gte' => __('The top of the range cannot be below the bottom of it.'),
        ]);

        $user = $request->user();

        DB::transaction(function () use ($request, $user, $validated): void {
            $profile = $user->workerProfile ?? new WorkerProfile;

            $profile->fill([
                'full_name' => $validated['full_name'],
                'phone' => $validated['phone'],
                'whatsapp' => $validated['whatsapp'] ?? null,
                'state' => $validated['state'],
                'lga' => $validated['lga'] ?? null,
                'willing_to_relocate' => (bool) ($validated['willing_to_relocate'] ?? false),
                'work_type_wanted' => $validated['work_type_wanted'],
                'years_experience' => $validated['years_experience'],
                'expected_pay_min_kobo' => isset($validated['expected_pay_min']) ? Money::toKobo($validated['expected_pay_min']) : null,
                'expected_pay_max_kobo' => isset($validated['expected_pay_max']) ? Money::toKobo($validated['expected_pay_max']) : null,
                'pay_period' => $validated['pay_period'],
                'availability' => $validated['availability'],
                'about' => $validated['about'] ?? null,
                'is_open_to_work' => (bool) ($validated['is_open_to_work'] ?? true),
            ]);

            $profile->user_id = $user->getKey();

            if ($request->hasFile('photo')) {
                $profile->photo = app(ImageIngest::class)->store($request->file('photo'), 'jobs/workers');
            }

            $profile->save();

            $profile->skills()->sync($validated['skill_ids'] ?? []);
        });

        // Self-registration: holding the role is what the profile means.
        if (! $user->holdsRole(RoleName::Worker)) {
            $user->assignRole(RoleName::Worker->value);
        }

        return redirect()
            ->route('jobs.worker.dashboard')
            ->with('success', __('Saved. Employers looking for your skills can find you now.'));
    }

    /**
     * Where the worker's applications got to.
     */
    public function dashboard(Request $request): Response
    {
        $profile = $request->user()->workerProfile;

        if ($profile === null) {
            return Inertia::render('Jobs/Workers/Edit', [
                'profile' => null,
                'skills' => WorkerSkill::grouped(),
                'states' => Nigeria::states(),
                'workTypes' => WorkTypeWanted::options(),
                'payPeriods' => PayPeriod::options(),
                'availabilities' => WorkerAvailability::options(),
                'prefill' => ['full_name' => $request->user()->displayName()],
                'notice' => JobBoardController::notice(),
            ]);
        }

        $applications = $profile->applications()
            ->with(['listing.employer', 'ratings'])
            ->get();

        $user = $request->user();

        return Inertia::render('Jobs/Workers/Dashboard', [
            'profile' => $profile->publicCard(),
            'isOpenToWork' => $profile->is_open_to_work,
            'applications' => $applications->map(fn (JobApplication $application): array => [
                'id' => $application->id,
                // The worker's own wording, which is not the employer's.
                'status' => $application->status->value,
                'status_label' => $application->status->workerLabel(),
                'status_tone' => $application->status->badgeTone(),
                'applied_at' => $application->applied_at?->format('j M Y'),
                'listing' => $application->listing?->publicCard(),
                'employer' => $application->listing?->employer?->publicCard(),
                'can_withdraw' => ! $application->status->isFinished(),
                'can_rate' => $user->can('create', $application),
                'has_rated' => $application->hasRatingFrom(RatingParty::Worker),
                'is_hired' => $application->status === JobApplicationStatus::Hired,
            ])->all(),
            'notice' => JobBoardController::notice(),
        ]);
    }
}
