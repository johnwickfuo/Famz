<?php

namespace Database\Seeders;

use App\Enums\BillingInterval;
use App\Enums\BillingType;
use App\Enums\ContactMethod;
use App\Enums\MentorStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\MentorProfile;
use App\Models\MentorshipPackage;
use App\Models\Specialisation;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Mentors with the shape of real ones, for looking at the platform rather than
 * an empty shortlist.
 *
 * Deliberately not run by DatabaseSeeder: `php artisan db:seed --class=DemoMentorSeeder`.
 * Nothing here is the client — the company still has no name — and every person
 * below is invented. The invitation flow is bypassed on purpose: this is a
 * fixture, and going through the real door would leave six spent invitations
 * cluttering the admin screen.
 */
class DemoMentorSeeder extends Seeder
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private const MENTORS = [
        [
            'name' => 'Dr Adaeze Obi',
            'headline' => 'Poultry veterinarian, 18 years with commercial flocks',
            'bio' => 'I qualified at Nsukka and have spent eighteen years on poultry farms across the south east, mostly on layer units between two and twenty thousand birds. Most of the farms that call me are losing birds and do not yet know why.',
            'strengths' => 'Working out what is killing birds, and the vaccination programme that stops it happening again. I am blunt about biosecurity because it is the cheapest thing on any farm and the first thing everybody skips.',
            'years' => 18,
            'qualifications' => 'DVM, University of Nigeria Nsukka. Registered with the Veterinary Council of Nigeria.',
            'affiliation' => 'Private practice, Enugu',
            'tags' => ['poultry-health-and-vaccination', 'brooding', 'layer-management'],
            'phone' => '08031112222',
            'contact' => ['whatsapp', '08031112222'],
            'states' => ['Enugu', 'Anambra', 'Ebonyi'],
            'in_person' => true,
            'packages' => [
                ['One-hour disease call', 950_000, 'An hour on the phone or WhatsApp video, going through symptoms, mortality figures and what you are feeding.', '1 hour', 1, ['A written summary and a vaccination schedule']],
                ['Farm visit and health audit', 4_500_000, 'A full day on the farm: housing, biosecurity, water, feed store and a walk through every pen.', '1 day', 1, ['A written report within three days', 'A vaccination programme for the next cycle']],
                ['Monthly flock supervision', 2_500_000, 'Ongoing supervision for a farm in production. Weekly check-ins and I am on the phone when something goes wrong.', 'Ongoing', 4, ['Weekly check-in calls', 'Priority when something goes wrong'], 'monthly'],
            ],
        ],
        [
            'name' => 'Musa Ibrahim',
            'headline' => 'Feed miller and nutritionist, Kaduna',
            'bio' => 'I have run a feed mill in Kaduna for eleven years and formulate for farms from a hundred birds to fifty thousand. I buy the same maize and soya you do, at the same prices, so I know what a ration really costs this month.',
            'strengths' => 'Least-cost rations from whatever is actually in the market, and telling you honestly when mixing your own is not worth it. Also storage — most of the loss I see is in the store, not the mixer.',
            'years' => 11,
            'qualifications' => 'B.Agric (Animal Science), Ahmadu Bello University.',
            'affiliation' => 'Sabon Gari Feeds',
            'tags' => ['feed-formulation', 'feed-quality-and-storage', 'broiler-production'],
            'phone' => '08034445555',
            'contact' => ['phone', '08034445555'],
            'states' => ['Kaduna', 'Kano', 'Katsina'],
            'in_person' => true,
            'packages' => [
                ['Ration costing session', 600_000, 'We cost your current feed against mixing it yourself, using this week\'s prices in your market.', '90 minutes', 1, ['A costed ration sheet you can take to the market']],
                ['Full formulation package', 3_000_000, 'Starter, grower and finisher rations worked for your birds and your market, with a mixing schedule.', 'Two sessions', 2, ['Three costed rations', 'A mixing and storage plan']],
            ],
        ],
        [
            'name' => 'Folake Adeyemi',
            'headline' => 'Farm business adviser — records, costing and pricing',
            'bio' => 'I spent nine years as an accountant before taking over my family\'s farm in Ogun, which is when I discovered that almost nobody knows whether their farm makes money. I now help other farms find out.',
            'strengths' => 'Setting up books somebody will actually keep — three exercise books, five minutes a day. Then using them to work out the real cost of a crate of eggs, and what to charge.',
            'years' => 9,
            'qualifications' => 'ICAN. B.Sc Accounting, University of Lagos.',
            'affiliation' => 'Adeyemi Farms, Abeokuta',
            'tags' => ['farm-business-and-record-keeping', 'marketing-and-sales', 'farm-start-up-and-planning'],
            'phone' => '08037778888',
            'contact' => ['google_meet', 'https://meet.google.com/abc-defg-hij'],
            'states' => ['Ogun', 'Lagos'],
            'in_person' => true,
            'packages' => [
                ['Records set-up session', 750_000, 'We set up your three books together and I show you exactly what to write in each one.', '2 hours', 1, ['Printed record sheets', 'A follow-up call two weeks later']],
                ['Monthly business review', 1_800_000, 'Every month we go through your figures together and work out where the money went.', 'Ongoing', 1, ['A monthly one-page summary'], 'monthly'],
            ],
        ],
        [
            'name' => 'Emeka Nwachukwu',
            'headline' => 'Catfish farmer and hatchery operator, 14 years',
            'bio' => 'I run a catfish farm and hatchery outside Onitsha with about forty thousand fish in the water at any time. I started with two concrete ponds and made every mistake there is to make.',
            'strengths' => 'Water quality and feeding — that is where the money is won or lost in catfish. Also hatchery work if you want to produce your own fingerlings instead of buying them.',
            'years' => 14,
            'qualifications' => 'Twelve years commercial production; trained at the National Institute for Freshwater Fisheries Research.',
            'affiliation' => 'Nwachukwu Fisheries',
            'tags' => ['fish-farming', 'farm-construction-and-housing', 'waste-management'],
            'phone' => '08036667777',
            'contact' => ['whatsapp', '08036667777'],
            'states' => ['Anambra', 'Delta', 'Imo'],
            'in_person' => true,
            'packages' => [
                ['Pond review call', 500_000, 'An hour going through your water, your feeding and your growth figures.', '1 hour', 1, ['A written summary']],
                ['Hatchery start-up support', 6_000_000, 'Everything from siting to your first hatch, over six weeks.', '6 weeks', 6, ['A build specification', 'Six supervised sessions', 'Your first hatch supervised']],
            ],
        ],
        [
            'name' => 'Hauwa Bello',
            'headline' => 'Crop agronomist — maize, rice and dry-season vegetables',
            'bio' => 'Fifteen years in extension work across the middle belt, and a farm of my own in Kwara. I spend most of my time on soil and spacing, which is where most yields are lost before anything is even planted.',
            'strengths' => 'Getting a yield out of land that is already tired: soil testing, fertiliser rates that are worth the money, and spacing. Also dry-season vegetables under irrigation.',
            'years' => 15,
            'qualifications' => 'M.Sc Agronomy, University of Ilorin.',
            'affiliation' => 'Independent',
            'tags' => ['crop-agronomy', 'soil-health-and-fertiliser', 'irrigation-and-water', 'pest-and-disease-control'],
            'phone' => '08038889999',
            'contact' => ['whatsapp', '08038889999'],
            'states' => ['Kwara', 'Niger', 'Plateau'],
            'in_person' => true,
            'packages' => [
                ['Field planning call', 550_000, 'We plan the season: what to plant where, spacing, and what fertiliser is actually worth buying.', '1 hour', 1, ['A planting plan for the season']],
                ['Season-long supervision', 2_000_000, 'I follow the crop through the season, from land preparation to harvest.', 'One season', 6, ['Monthly farm calls', 'A harvest review'], 'monthly'],
            ],
        ],
        [
            'name' => 'Suleiman Yakubu',
            'headline' => 'Small ruminants and fattening for the season',
            'bio' => 'I have been buying, fattening and selling rams and goats in Kano for over twenty years. I know what a thin ram costs in March and what a fat one fetches at Sallah.',
            'strengths' => 'Fattening economics — which animals to buy, what to feed them and exactly when to sell. It is arithmetic more than animal science, and most people get the arithmetic wrong.',
            'years' => 21,
            'qualifications' => 'Twenty-one years in the trade.',
            'affiliation' => 'Yakubu Livestock, Kano',
            'tags' => ['small-ruminants', 'livestock-health-and-veterinary-care', 'marketing-and-sales'],
            'phone' => '08030001111',
            'contact' => ['phone', '08030001111'],
            'states' => ['Kano', 'Jigawa', 'Kaduna'],
            'in_person' => true,
            'packages' => [
                ['Fattening plan', 450_000, 'What to buy, what to feed, and when to sell, worked for your budget.', '1 hour', 1, ['A costed fattening plan']],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::MENTORS as $definition) {
            $this->createMentor($definition);
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function createMentor(array $definition): void
    {
        $slug = Str::slug($definition['name']);

        if (MentorProfile::query()->where('slug', $slug)->exists()) {
            return;
        }

        $user = User::query()->firstOrCreate(
            ['email' => $slug.'@example.test'],
            [
                'name' => $definition['name'],
                'password' => Hash::make('password'),
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ],
        );

        $user->assignRole(RoleName::Mentor->value);
        $user->profile()->firstOrCreate([], [
            'display_name' => $definition['name'],
            // Their own number, which is what the mentor's client is given.
            // Not the same as the mentoring contact value: somebody who
            // prefers Google Meet still has a phone.
            'phone' => $definition['phone'],
            'state' => $definition['states'][0],
        ]);

        $profile = new MentorProfile;

        $profile->forceFill([
            'user_id' => $user->getKey(),
            'slug' => $slug,
            'headline' => $definition['headline'],
            'bio' => $definition['bio'],
            'strengths' => $definition['strengths'],
            'years_experience' => $definition['years'],
            'qualifications' => $definition['qualifications'],
            'affiliation' => $definition['affiliation'],
            'preferred_contact_method' => ContactMethod::from($definition['contact'][0]),
            'contact_value' => $definition['contact'][1],
            'states_served' => $definition['states'],
            'accepts_remote' => true,
            'accepts_in_person' => $definition['in_person'],
            'status' => MentorStatus::Approved,
            'approved_at' => now(),
        ])->save();

        $tags = Specialisation::query()->whereIn('slug', $definition['tags'])->pluck('id');

        $profile->specialisations()->sync($tags);

        foreach ($definition['packages'] as $index => $package) {
            [$title, $price, $description, $duration, $sessions, $deliverables] = $package;
            $interval = $package[6] ?? null;

            MentorshipPackage::query()->create([
                'mentor_profile_id' => $profile->getKey(),
                'title' => $title,
                'description' => $description,
                'billing_type' => $interval === null ? BillingType::OneTime : BillingType::Periodic,
                'billing_interval' => $interval === null ? null : BillingInterval::from($interval),
                'price_kobo' => $price,
                'currency' => 'NGN',
                'duration_description' => $duration,
                'sessions_included' => $sessions,
                'deliverables' => $deliverables,
                'is_active' => true,
                'sort_order' => $index,
            ]);
        }
    }
}
