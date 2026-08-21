<?php

use App\Enums\RoleName;
use App\Models\BreedStandard;
use App\Models\BuyerRequest;
use App\Models\Certificate;
use App\Models\Consultation;
use App\Models\Course;
use App\Models\Dispute;
use App\Models\Enrolment;
use App\Models\JobApplication;
use App\Models\JobListing;
use App\Models\MentorProfile;
use App\Models\MentorshipEngagement;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationRequest;
use App\Models\SubOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\WorkerProfile;
use App\Services\Reporting\ReconciliationReport;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;

/**
 * The demonstration environment, checked the way it will actually be used.
 *
 * A seeder that throws halfway leaves a client walkthrough with three modules
 * populated and five empty, and nobody finds out until the meeting. Worse, a
 * seeder that writes rows directly rather than through the services produces
 * data that looks right on every screen and is wrong in the ledger — and the
 * reconciliation report is one of the things being demonstrated.
 *
 * Slow, and worth it: this runs the whole thing.
 */
beforeEach(function (): void {
    // The base seed first, exactly as a real setup does it: `migrate --seed`
    // and then `db:seed --class=DemoSeeder`. Skipping it left the breed tables
    // empty and the assistant with nothing to cite.
    $this->seed(DatabaseSeeder::class);
});

it('seeds every module', function (): void {
    $this->seed(DemoSeeder::class);

    /*
     * Every module named in the specification. A count of zero anywhere is a
     * screen that will read "nothing here yet" in front of the client.
     */
    $populated = [
        'marketplace listings' => Product::query()->count(),
        'paid orders' => Order::query()->whereNotNull('paid_at')->count(),
        'ledger entries' => WalletTransaction::query()->count(),
        'disputes' => Dispute::query()->count(),
        'wanted ads' => BuyerRequest::query()->count(),
        'offers' => Offer::query()->count(),
        'courses' => Course::query()->count(),
        'enrolments' => Enrolment::query()->count(),
        'certificates' => Certificate::query()->count(),
        'mentors' => MentorProfile::query()->count(),
        'engagements' => MentorshipEngagement::query()->count(),
        'consultations' => Consultation::query()->count(),
        'quotation requests' => QuotationRequest::query()->count(),
        'proposals' => Quotation::query()->count(),
        'job listings' => JobListing::query()->count(),
        'worker profiles' => WorkerProfile::query()->count(),
        'job applications' => JobApplication::query()->count(),
        'breed standards' => BreedStandard::query()->count(),
    ];

    $empty = collect($populated)->filter(fn (int $count): bool => $count === 0)->keys()->all();

    expect($empty)->toBe([], 'Nothing seeded for: '.implode(', ', $empty));
});

it('seeds money that reconciles', function (): void {
    $this->seed(DemoSeeder::class);

    $result = app(ReconciliationReport::class)->run();

    /*
     * The property that separates seeded data from forged data. Everything with
     * money in it goes in through the cart, the order builder, the payment
     * processor and the settlement driver — so the demonstration ledger is
     * correct for the same reason the real one will be.
     */
    expect($result['clean'])->toBeTrue(
        'The demo books do not agree: '.collect($result['findings'])->pluck('message')->implode(' · '),
    );
});

it('leaves each module in more than one state', function (): void {
    $this->seed(DemoSeeder::class);

    /*
     * A demonstration where every row reads "new" shows the intake form and
     * nothing about the work. Each of these is a screen that only says
     * something when the data behind it varies.
     */
    expect(SubOrder::query()->distinct()->count('status'))->toBeGreaterThan(2)
        ->and(Consultation::query()->distinct()->count('status'))->toBeGreaterThan(2)
        ->and(JobApplication::query()->distinct()->count('status'))->toBeGreaterThan(2);
});

it('refuses to run in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    (new DemoSeeder)->run();

    /*
     * Eight invented sellers and a buyer whose password is "password" is not
     * something a production deployment should be one command away from. Called
     * directly rather than through $this->seed(), which wires up a console
     * command and would prompt.
     */
    expect(Product::query()->count())->toBe(0);
});

it('creates an administrator when none exists', function (): void {
    $this->seed(DemoSeeder::class);

    /*
     * SuperAdminSeeder creates nothing without SUPER_ADMIN_EMAIL, which is
     * right for a deployment and wrong here: half the seeders need somebody to
     * approve, quote and send things, and without an administrator they skip
     * every one of those steps silently. That produced a demonstration with no
     * proposals in it and no error to explain why.
     */
    expect(User::query()->role(RoleName::Admin->value)->exists())->toBeTrue()
        ->and(Quotation::query()->count())->toBeGreaterThan(0);
});
