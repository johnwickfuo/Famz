<?php

use App\Enums\BuyerRequestStatus;
use App\Enums\LedgerState;
use App\Enums\LedgerType;
use App\Enums\ProductStatus;
use App\Enums\RoleName;
use App\Enums\SellerStatus;
use App\Enums\WithdrawalStatus;
use App\Filament\Admin\Pages\ModerationQueue;
use App\Filament\Admin\Pages\RevenueDashboard;
use App\Filament\Admin\Resources\ActivityLog\Pages\ListActivities;
use App\Models\BuyerRequest;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\Reporting\RevenueByStream;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;

use function Pest\Livewire\livewire;

/**
 * The three things an administrator needs that no single module provides.
 *
 * Revenue split by where it came from, one queue of everything waiting, and a
 * record of who changed what.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    Cache::flush();
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->create();
    $this->admin->assignRole(RoleName::Admin->value);
    $this->actingAs($this->admin);

    /**
     * A real mentorship invoice, for the tests that need one.
     *
     * Built through the actual chain rather than with a made-up id: the
     * distinction between mentorship and marketplace commission IS the foreign
     * key, so a test that faked it would be testing nothing.
     */
    $this->invoice = function (): \App\Models\MentorshipInvoice {
        $mentorUser = User::factory()->create();
        $mentorUser->assignRole(RoleName::Mentor->value);
        $mentor = \App\Models\MentorProfile::factory()->create(['user_id' => $mentorUser->id]);
        $package = \App\Models\MentorshipPackage::factory()->create(['mentor_profile_id' => $mentor->id]);

        $engagement = (new \App\Models\MentorshipEngagement)->forceFill([
            'reference' => 'ENG-TEST-'.uniqid(),
            'client_id' => User::factory()->create()->id,
            'mentor_profile_id' => $mentor->id,
            'mentorship_package_id' => $package->id,
            'package_title' => 'Test package',
            'billing_type' => \App\Enums\BillingType::OneTime,
            'price_kobo' => 1_000_000,
            'commission_percent_snapshot' => 15,
            'platform_amount_kobo' => 150_000,
            'mentor_amount_kobo' => 850_000,
        ]);
        $engagement->save();

        $invoice = (new \App\Models\MentorshipInvoice)->forceFill([
            'reference' => 'INV-TEST-'.uniqid(),
            'mentorship_engagement_id' => $engagement->id,
            'sequence' => 1,
            'amount_kobo' => 1_000_000,
            'platform_amount_kobo' => 150_000,
            'mentor_amount_kobo' => 850_000,
        ]);
        $invoice->save();

        return $invoice;
    };

    /**
     * A platform-side ledger entry of a given type.
     */
    $this->earn = function (LedgerType $type, int $kobo, ?int $invoiceId = null): WalletTransaction {
        return WalletTransaction::query()->create([
            'user_id' => null,
            'mentorship_invoice_id' => $invoiceId,
            'type' => $type,
            'amount_kobo' => $kobo,
            'state' => LedgerState::Released,
            'description' => 'test',
        ]);
    };
});

it('shows the revenue dashboard', function (): void {
    livewire(RevenueDashboard::class)->assertSuccessful();
});

it('splits revenue by the stream it came from', function (): void {
    ($this->earn)(LedgerType::Commission, 500_000);
    ($this->earn)(LedgerType::CourseSale, 1_200_000);
    ($this->earn)(LedgerType::ConsultationFee, 300_000);
    ($this->earn)(LedgerType::QuotationStudyFee, 5_000_000);

    $streams = collect(app(RevenueByStream::class)->between(now()->subDay(), now()->addDay()))
        ->pluck('kobo', 'key');

    expect($streams['marketplace'])->toBe(500_000)
        ->and($streams['courses'])->toBe(1_200_000)
        ->and($streams['consultations'])->toBe(300_000)
        ->and($streams['quotations'])->toBe(5_000_000);
});

it('tells mentorship commission apart from marketplace commission', function (): void {
    /*
     * Both are written as LedgerType::Commission on the platform account, so
     * type alone cannot separate them — a mentorship entry carries an invoice
     * id and a marketplace one never does. Getting this wrong would count every
     * naira of mentorship revenue twice.
     */
    ($this->earn)(LedgerType::Commission, 400_000);
    ($this->earn)(LedgerType::Commission, 900_000, invoiceId: ($this->invoice)()->id);

    $streams = collect(app(RevenueByStream::class)->between(now()->subDay(), now()->addDay()))
        ->pluck('kobo', 'key');

    expect($streams['marketplace'])->toBe(400_000)
        ->and($streams['mentorship'])->toBe(900_000);
});

it('does not count the same naira in two streams', function (): void {
    ($this->earn)(LedgerType::Commission, 400_000);
    ($this->earn)(LedgerType::Commission, 900_000, invoiceId: ($this->invoice)()->id);

    $streams = app(RevenueByStream::class)->between(now()->subDay(), now()->addDay());

    expect(array_sum(array_column($streams, 'kobo')))->toBe(1_300_000);
});

it('reports every one of the five streams the brief names', function (): void {
    expect(array_keys(RevenueByStream::streams()))
        ->toBe(['marketplace', 'mentorship', 'courses', 'consultations', 'quotations']);
});

it('survives a platform with no revenue at all', function (): void {
    // A brand-new deployment draws this dashboard before anybody has paid for
    // anything, and dividing by a zero peak would take it down.
    $data = livewire(RevenueDashboard::class)->instance()->getViewData();

    expect($data['peak'])->toBeGreaterThan(0)
        ->and($data['months'])->toHaveCount(12);
});

it('counts new accounts month by month with a running total', function (): void {
    $growth = app(RevenueByStream::class)->userGrowth(3);

    expect($growth)->toHaveCount(3)
        // The admin created in beforeEach.
        ->and(end($growth)['total'])->toBeGreaterThanOrEqual(1);
});

it('shows the moderation queue', function (): void {
    livewire(ModerationQueue::class)->assertSuccessful();
});

it('counts everything waiting on a decision in one place', function (): void {
    $sellerUser = User::factory()->create();
    SellerProfile::factory()->create([
        'user_id' => $sellerUser->id,
        'status' => SellerStatus::Pending,
    ]);

    $approved = SellerProfile::factory()->approved()->create([
        'user_id' => User::factory()->create()->id,
    ]);

    Product::factory()->for($approved, 'seller')->create(['status' => ProductStatus::PendingReview]);
    BuyerRequest::factory()->create(['status' => BuyerRequestStatus::PendingApproval]);

    $data = livewire(ModerationQueue::class)->instance()->getViewData();
    $counts = collect($data['rows'])->pluck('count', 'key');

    expect($counts['sellers'])->toBe(1)
        ->and($counts['products'])->toBe(1)
        ->and($counts['requests'])->toBe(1)
        ->and($data['total'])->toBeGreaterThanOrEqual(3);
});

it('badges the navigation so nobody has to open it to find out', function (): void {
    SellerProfile::factory()->create([
        'user_id' => User::factory()->create()->id,
        'status' => SellerStatus::Pending,
    ]);

    expect(ModerationQueue::getNavigationBadge())->toBe('1')
        // Amber, not red: a badge that is always red is a badge nobody reads.
        ->and(ModerationQueue::getNavigationBadgeColor())->toBe('warning');
});

it('shows no badge when nothing is waiting', function (): void {
    expect(ModerationQueue::getNavigationBadge())->toBeNull();
});

it('records who changed a withdrawal', function (): void {
    $seller = User::factory()->create();
    $seller->assignRole(RoleName::Seller->value);

    $withdrawal = Withdrawal::factory()->create([
        'user_id' => $seller->id,
        'status' => WithdrawalStatus::Requested,
    ]);

    $withdrawal->forceFill(['status' => WithdrawalStatus::Paid])->save();

    $activity = \Spatie\Activitylog\Models\Activity::query()
        ->where('subject_type', Withdrawal::class)
        ->where('subject_id', $withdrawal->id)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->description)->toBe('updated')
        ->and($activity->attribute_changes['attributes']['status'] ?? null)
        ->toBe(WithdrawalStatus::Paid->value);
});

it('records a moderation decision on a seller application', function (): void {
    $seller = SellerProfile::factory()->create([
        'user_id' => User::factory()->create()->id,
        'status' => SellerStatus::Pending,
    ]);

    $seller->forceFill(['status' => SellerStatus::Approved])->save();

    expect(\Spatie\Activitylog\Models\Activity::query()
        ->where('subject_type', SellerProfile::class)
        ->where('subject_id', $seller->id)
        ->where('description', 'updated')
        ->exists())->toBeTrue();
});

it('writes no row for a save that changed nothing', function (): void {
    $withdrawal = Withdrawal::factory()->create([
        'user_id' => User::factory()->create()->id,
    ]);

    $before = \Spatie\Activitylog\Models\Activity::query()->count();

    $withdrawal->save();

    // Without dontLogEmptyChanges every touch writes a row saying nothing
    // happened, which is most of them.
    expect(\Spatie\Activitylog\Models\Activity::query()->count())->toBe($before);
});

it('keeps a password out of the log', function (): void {
    $user = User::factory()->create();

    $user->forceFill(['password' => bcrypt('a-new-password')])->save();

    $logged = json_encode(\Spatie\Activitylog\Models\Activity::query()
        ->where('subject_type', User::class)
        ->where('subject_id', $user->id)
        ->get()
        ->toArray());

    // A log is a copy of the data, and a table nobody thinks of as sensitive
    // is exactly where a copy of a credential should not be.
    expect($logged)->not->toContain('password');
});

it('shows the activity log', function (): void {
    livewire(ListActivities::class)->assertSuccessful();
});

it('offers no way to create an activity record by hand', function (): void {
    expect(\App\Filament\Admin\Resources\ActivityLog\ActivityResource::canCreate())->toBeFalse();
});

it('keeps a seller out of the oversight screens', function (): void {
    $seller = User::factory()->create();
    $seller->assignRole(RoleName::Seller->value);

    $this->actingAs($seller);

    $this->get(RevenueDashboard::getUrl(panel: 'admin'))->assertForbidden();
    $this->get(ModerationQueue::getUrl(panel: 'admin'))->assertForbidden();
});
