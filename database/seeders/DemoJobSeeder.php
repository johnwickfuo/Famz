<?php

namespace Database\Seeders;

use App\Enums\JobApplicationStatus;
use App\Enums\JobListingStatus;
use App\Enums\JobType;
use App\Enums\PayPeriod;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Enums\WorkerAvailability;
use App\Enums\WorkTypeWanted;
use App\Models\EmployerProfile;
use App\Models\JobListing;
use App\Models\User;
use App\Models\WorkerProfile;
use App\Models\WorkerSkill;
use App\Services\Jobs\ApplicationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * The jobs board: farms looking for workers, workers looking for farms.
 *
 * No money passes through the platform here, so there is no ledger to keep
 * honest — but there is something else that matters more, and the demonstration
 * has to show it. A worker's phone number is never in a response body until an
 * employer with an open listing asks for it, and the seeded data exists partly
 * so that rule can be shown holding: open a worker's page as a visitor and the
 * number is not there to be found.
 *
 * Run after DemoTradeSeeder, which creates the accounts these hang from.
 */
class DemoJobSeeder extends Seeder
{
    /**
     * @var array<int, array{business: string, type: string, state: string, lga: string}>
     */
    private const EMPLOYERS = [
        ['business' => 'Odeda Layer Farms', 'type' => 'Poultry farm', 'state' => 'Ogun', 'lga' => 'Odeda'],
        ['business' => 'Ughelli Fish Ponds', 'type' => 'Fish farm', 'state' => 'Delta', 'lga' => 'Ughelli North'],
        ['business' => 'Giwa Integrated Farms', 'type' => 'Mixed farm', 'state' => 'Kaduna', 'lga' => 'Giwa'],
    ];

    /**
     * @var array<int, array{title: string, type: string, positions: int, min: int, max: int, period: string, description: string, accommodation: bool, food: bool}>
     */
    private const LISTINGS = [
        [
            'title' => 'Poultry attendant, layer house',
            'type' => 'permanent',
            'positions' => 2,
            'min' => 60_000_00,
            'max' => 80_000_00,
            'period' => 'monthly',
            'accommodation' => true,
            'food' => true,
            'description' => "Feeding, watering, egg collection twice a day, and keeping the house clean. Six days a week, early start.\n\nWe will train you on the routine, but you must have handled birds before — this is not a first job.",
        ],
        [
            'title' => 'Pond hand, catfish',
            'type' => 'permanent',
            'positions' => 3,
            'min' => 55_000_00,
            'max' => 75_000_00,
            'period' => 'monthly',
            'accommodation' => true,
            'food' => false,
            'description' => "Feeding, sorting, grading and helping at harvest. You must be able to swim and be comfortable in the water.\n\nAccommodation on site. Food is your own.",
        ],
        [
            'title' => 'Farm supervisor',
            'type' => 'permanent',
            'positions' => 1,
            'min' => 150_000_00,
            'max' => 220_000_00,
            'period' => 'monthly',
            'accommodation' => true,
            'food' => true,
            'description' => "Running the day-to-day: six workers, the feed store, the records, and reporting to the owner weekly.\n\nYou need to read and write, keep records that add up, and have run a farm or a section of one before.",
        ],
        [
            'title' => 'Casual hands for harvest, two weeks',
            'type' => 'temporary',
            'positions' => 8,
            'min' => 5_000_00,
            'max' => 7_000_00,
            'period' => 'daily',
            'accommodation' => false,
            'food' => true,
            'description' => "Two weeks of maize harvest, starting the first week of next month. Paid daily, at the end of each day.\n\nNo experience needed. Bring your own gloves if you have them.",
        ],
        [
            'title' => 'Vaccinator, contract',
            'type' => 'contract',
            'positions' => 1,
            'min' => 250_000_00,
            'max' => 350_000_00,
            'period' => 'monthly',
            'accommodation' => false,
            'food' => false,
            'description' => "Vaccination rounds across three sites, on a schedule we agree in advance. You bring your own kit and your own transport; we supply the vaccines and the cold chain.\n\nYou must have done this professionally before and be able to show it.",
        ],
    ];

    /**
     * @var array<int, array{name: string, state: string, lga: string, years: int, about: string}>
     */
    private const WORKERS = [
        [
            'name' => 'Segun Adeyemi',
            'state' => 'Ogun',
            'lga' => 'Abeokuta North',
            'years' => 6,
            'about' => 'Six years on layer farms, the last four at one place in Abeokuta running two houses of 4,000. I keep proper records and I can vaccinate.',
        ],
        [
            'name' => 'Blessing Okoro',
            'state' => 'Delta',
            'lga' => 'Ughelli North',
            'years' => 3,
            'about' => 'Three years on catfish. Sorting, grading, feeding by hand and by machine. I have been through two full harvest cycles.',
        ],
        [
            'name' => 'Ibrahim Yakubu',
            'state' => 'Kaduna',
            'lga' => 'Giwa',
            'years' => 11,
            'about' => 'Eleven years, mostly mixed farms. I have supervised up to nine people. I read and write English and Hausa.',
        ],
        [
            'name' => 'Chidinma Eze',
            'state' => 'Enugu',
            'lga' => 'Nsukka',
            'years' => 1,
            'about' => 'One year helping on my uncle\'s broiler farm. I am willing to relocate and I learn fast.',
        ],
        [
            'name' => 'Yusuf Bello',
            'state' => 'Kano',
            'lga' => 'Nassarawa',
            'years' => 8,
            'about' => 'Eight years, poultry and small ruminants. Certified vaccinator. I have my own transport.',
        ],
    ];

    public function run(): void
    {
        Mail::fake();
        Notification::fake();

        $employers = collect(self::EMPLOYERS)->map(fn (array $row): EmployerProfile => $this->employer($row));
        $workers = collect(self::WORKERS)->map(fn (array $row): WorkerProfile => $this->worker($row));

        $listings = $this->postListings($employers);
        $this->apply($listings, $workers);

        $this->command?->info('Demo jobs seeded: '.$listings->count().' listings, '.$workers->count().' workers.');
    }

    private function employer(array $row): EmployerProfile
    {
        $slug = Str::slug($row['business']);

        $user = User::query()->firstOrCreate(
            ['email' => $slug.'@example.test'],
            [
                'name' => $row['business'],
                'password' => Hash::make('password'),
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ],
        );

        $user->assignRole(RoleName::Employer->value);

        return EmployerProfile::query()->firstOrCreate(
            ['user_id' => $user->getKey()],
            [
                'slug' => $slug,
                'business_name' => $row['business'],
                'business_type' => $row['type'],
                'state' => $row['state'],
                'lga' => $row['lga'],
                'contact_person' => 'The farm manager',
                'phone' => self::phone(),
                'email' => $slug.'@example.test',
                'about' => 'A working farm in '.$row['lga'].', '.$row['state'].'.',
                'is_active' => true,
            ],
        );
    }

    private function worker(array $row): WorkerProfile
    {
        $slug = Str::slug($row['name']);

        $user = User::query()->firstOrCreate(
            ['email' => $slug.'@example.test'],
            [
                'name' => $row['name'],
                'password' => Hash::make('password'),
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ],
        );

        $user->assignRole(RoleName::Worker->value);

        $profile = WorkerProfile::query()->firstOrCreate(
            ['user_id' => $user->getKey()],
            [
                'slug' => $slug,
                'full_name' => $row['name'],
                /*
                 * A real-shaped number that is never shown to anybody who has
                 * not earned it. The whole point of the seeded worker is to be
                 * able to open their page as a visitor and find no number.
                 */
                'phone' => self::phone(),
                'whatsapp' => self::phone(),
                'state' => $row['state'],
                'lga' => $row['lga'],
                'willing_to_relocate' => $row['years'] < 5,
                'work_type_wanted' => WorkTypeWanted::Both,
                'years_experience' => $row['years'],
                'expected_pay_min_kobo' => 50_000_00 + $row['years'] * 10_000_00,
                'expected_pay_max_kobo' => 80_000_00 + $row['years'] * 15_000_00,
                'pay_period' => PayPeriod::Monthly,
                'availability' => WorkerAvailability::Immediately,
                'about' => $row['about'],
                'is_open_to_work' => true,
                'is_active' => true,
            ],
        );

        // Skills, so the matching has something to match on. Matching here is
        // deterministic — skills, state, availability — not AI.
        $skills = WorkerSkill::query()->inRandomOrder()->take(random_int(2, 4))->pluck('id');

        if ($skills->isNotEmpty()) {
            $profile->skills()->syncWithoutDetaching($skills);
        }

        return $profile;
    }

    /**
     * @param  Collection<int, EmployerProfile>  $employers
     * @return Collection<int, JobListing>
     */
    private function postListings($employers)
    {
        return collect(self::LISTINGS)->map(function (array $row, int $index) use ($employers): JobListing {
            $employer = $employers[$index % $employers->count()];

            $listing = new JobListing;
            $listing->forceFill([
                'employer_profile_id' => $employer->getKey(),
                'slug' => Str::slug($row['title']).'-'.Str::lower(Str::random(4)),
                'title' => $row['title'],
                'description' => $row['description'],
                'job_type' => JobType::from($row['type']),
                'positions_available' => $row['positions'],
                'state' => $employer->state,
                'lga' => $employer->lga,
                'is_accommodation_provided' => $row['accommodation'],
                'is_food_provided' => $row['food'],
                'pay_min_kobo' => $row['min'],
                'pay_max_kobo' => $row['max'],
                'pay_period' => PayPeriod::from($row['period']),
                'start_date' => now()->addDays(random_int(7, 30)),
                'application_deadline' => now()->addDays(random_int(14, 45)),
                // One closed, so the board is not uniformly green and the
                // "filled" state has somewhere to be seen.
                'status' => $index === 4 ? JobListingStatus::Filled : JobListingStatus::Open,
                'views_count' => random_int(12, 180),
                'published_at' => now()->subDays(random_int(1, 20)),
            ])->save();

            return $listing;
        });
    }

    /**
     * @param  Collection<int, JobListing>  $listings
     * @param  Collection<int, WorkerProfile>  $workers
     */
    private function apply($listings, $workers): void
    {
        $applications = app(ApplicationService::class);

        foreach ($listings as $index => $listing) {
            if ($listing->status !== JobListingStatus::Open) {
                continue;
            }

            foreach ($workers->random(min(3, $workers->count())) as $position => $worker) {
                try {
                    $application = $applications->apply(
                        $listing,
                        $worker,
                        'I have done this work before and I can start when you need me.',
                    );

                    $employer = $listing->employer?->user;

                    if ($employer === null) {
                        continue;
                    }

                    /*
                     * Moved along, so the employer's applicant list shows a
                     * pipeline rather than five identical rows. Only `hired`
                     * carries weight beyond reporting — it is what unlocks the
                     * rating — so one of them is hired.
                     */
                    match (($index + $position) % 4) {
                        0 => null,
                        1 => $applications->moveTo($application, JobApplicationStatus::Viewed, $employer),
                        2 => $applications->moveTo($application, JobApplicationStatus::Shortlisted, $employer),
                        default => $applications->moveTo($application, JobApplicationStatus::Hired, $employer),
                    };
                } catch (Throwable $exception) {
                    $this->command?->warn('Application skipped: '.$exception->getMessage());
                }
            }
        }
    }

    /**
     * 0700 is reserved and unassigned in the Nigerian numbering plan, so no
     * demonstration screenshot can show a stranger's phone number.
     */
    private static function phone(): string
    {
        return '0700'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
    }
}
