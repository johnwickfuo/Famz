<?php

use App\Enums\ProductStatus;
use App\Enums\RoleName;
use App\Models\Consultation;
use App\Models\MentorProfile;
use App\Models\MentorshipEngagement;
use App\Models\Order;
use App\Models\PayoutAccount;
use App\Models\Product;
use App\Models\QuotationRequest;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Withdrawal;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

/**
 * One tenant must never see another tenant's records.
 *
 * Asserted at the HTTP and Livewire layer rather than by reading policies,
 * because a policy that is correct and a resource that forgets to call it look
 * identical in a code review and completely different to a seller reading
 * somebody else's orders.
 *
 * Every test here follows the same shape: two tenants, a record belonging to
 * the first, and the second one asking for it. The assertion is always that the
 * second one gets nothing — not an error necessarily, but nothing.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    /**
     * A seller and their profile.
     */
    $this->makeSeller = function (string $name): array {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole(RoleName::Seller->value);

        return [$user, SellerProfile::factory()->approved()->create(['user_id' => $user->id])];
    };

    /**
     * A mentor and their profile.
     */
    $this->makeMentor = function (string $name): array {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole(RoleName::Mentor->value);

        return [$user, MentorProfile::factory()->create(['user_id' => $user->id])];
    };
});

// ---------------------------------------------------------------------------
// Seller panel
// ---------------------------------------------------------------------------

it('keeps one seller out of another seller\'s listings', function (): void {
    [$mine] = ($this->makeSeller)('Mine');
    [, $theirProfile] = ($this->makeSeller)('Theirs');

    $theirs = Product::factory()->for($theirProfile, 'seller')->create([
        'name' => 'Their private listing',
        'status' => ProductStatus::Draft,
    ]);

    Filament::setCurrentPanel('seller');
    $this->actingAs($mine);

    livewire(\App\Filament\Seller\Resources\Products\Pages\ListProducts::class)
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('scopes every seller resource to the signed-in seller', function (string $model, string $column): void {
    /*
     * The scope itself, exercised directly. A resource can forget to call it;
     * this proves the thing the resource is supposed to call does the right
     * thing when it is called.
     */
    [$mine] = ($this->makeSeller)('Mine');
    [$theirs] = ($this->makeSeller)('Theirs');

    $query = $model::query()->ownedBy($mine);

    expect($query->toSql())->toContain($column)
        ->and($query->getBindings())->toContain($mine->id)
        ->and($query->getBindings())->not->toContain($theirs->id);
})->with([
    'payout accounts' => [PayoutAccount::class, 'user_id'],
    'withdrawals' => [Withdrawal::class, 'user_id'],
]);

// ---------------------------------------------------------------------------
// The null-owner class of bug
// ---------------------------------------------------------------------------

it('matches nothing rather than everything when handed no owner', function (string $model, string $column): void {
    /*
     * The bug this exists to prevent, and it is subtler than it looks.
     *
     * Laravel compiles where('user_id', null) to `WHERE user_id IS NULL`, not
     * to a comparison that matches nothing. A consultation booked by a guest
     * HAS a null user_id — so an ownership scope that passed null straight
     * through returned every guest booking on the platform, each carrying a
     * name, a phone number and photographs of somebody's farm.
     */
    $query = $model::query()->ownedBy(null);

    /*
     * Named column, not a bare search for "is null": a soft-deleted model
     * carries `deleted_at is null` in every query, and a crude check would
     * report that as the bug.
     */
    expect($query->toSql())->not->toContain($column.'` is null')
        ->and($query->getBindings())->toContain(0);
})->with([
    'consultations' => [Consultation::class, 'user_id'],
    'mentor profiles' => [MentorProfile::class, 'user_id'],
    'engagements' => [MentorshipEngagement::class, 'client_id'],
    'payout accounts' => [PayoutAccount::class, 'user_id'],
    'withdrawals' => [Withdrawal::class, 'user_id'],
]);

it('returns no guest consultations to a scope with no owner', function (): void {
    // The same bug, proved against real rows rather than against SQL.
    Consultation::factory()->count(3)->create(['user_id' => null]);

    expect(Consultation::query()->ownedBy(null)->count())->toBe(0)
        ->and(Consultation::query()->count())->toBe(3);
});

// ---------------------------------------------------------------------------
// Buyer-side records
// ---------------------------------------------------------------------------

it('keeps one buyer out of another buyer\'s order', function (): void {
    $mine = User::factory()->create(['email_verified_at' => now()]);
    $theirs = User::factory()->create();

    $order = Order::factory()->create(['user_id' => $theirs->id]);

    $this->actingAs($mine)
        ->get(route('orders.show', $order))
        ->assertForbidden();
});

it('keeps one client out of another client\'s consultation', function (): void {
    $mine = User::factory()->create(['email_verified_at' => now()]);
    $theirs = User::factory()->create();

    $consultation = Consultation::factory()->create(['user_id' => $theirs->id]);

    $response = $this->actingAs($mine)->get(route('consultations.show', $consultation));

    // Either refused or not found is fine; what matters is that the contents
    // do not come back.
    expect($response->status())->toBeIn([403, 404])
        ->and($response->getContent())->not->toContain($consultation->phone);
});

it('keeps one client out of another client\'s quotation request', function (): void {
    $mine = User::factory()->create(['email_verified_at' => now()]);
    $theirs = User::factory()->create();

    $request = QuotationRequest::factory()->create(['user_id' => $theirs->id]);

    expect($this->actingAs($mine)->get(route('quotations.show', $request))->status())
        ->toBeIn([403, 404]);
});

it('lists only a buyer\'s own consultations', function (): void {
    $mine = User::factory()->create(['email_verified_at' => now()]);
    $theirs = User::factory()->create();

    $notMine = Consultation::factory()->create(['user_id' => $theirs->id]);
    $isMine = Consultation::factory()->create(['user_id' => $mine->id]);

    /*
     * Asserted on the serialised props rather than the raw HTML: Inertia
     * escapes the payload into a data-page attribute, so a name IS in the
     * document but not as the string being searched for. Encoding the props
     * back to JSON asks the question that was meant.
     */
    $props = json_encode(
        $this->actingAs($mine)->get(route('consultations.index'))->viewData('page')['props']
    );

    // References rather than names: the list shows somebody their own
    // bookings, so it has no reason to carry a name they already know.
    expect($props)->toContain($isMine->reference)
        ->not->toContain($notMine->reference);
});

// ---------------------------------------------------------------------------
// Worker contact details
// ---------------------------------------------------------------------------

it('never returns a worker phone number to somebody without the employer role', function (): void {
    $workerUser = User::factory()->create();
    $workerUser->assignRole(RoleName::Worker->value);

    $worker = \App\Models\WorkerProfile::factory()->create([
        'user_id' => $workerUser->id,
        'phone' => '08039999999',
        'is_active' => true,
    ]);

    // A signed-in user who is not an employer.
    $nosy = User::factory()->create(['email_verified_at' => now()]);

    $response = $this->actingAs($nosy)->get(route('jobs.workers.show', $worker));

    /*
     * Asserted on the whole response body, not on a template variable. The
     * number must be absent from what goes over the wire, which is a stronger
     * claim than "the component chose not to render it".
     */
    expect($response->getContent())->not->toContain('08039999999');
});

it('never returns a worker phone number to an anonymous visitor', function (): void {
    $workerUser = User::factory()->create();
    $workerUser->assignRole(RoleName::Worker->value);

    $worker = \App\Models\WorkerProfile::factory()->create([
        'user_id' => $workerUser->id,
        'phone' => '08038888888',
        'is_active' => true,
    ]);

    $response = $this->get(route('jobs.workers.show', $worker));

    expect($response->getContent())->not->toContain('08038888888');
});

// ---------------------------------------------------------------------------
// Panels
// ---------------------------------------------------------------------------

it('keeps a seller out of the mentor panel and a mentor out of the seller panel', function (): void {
    [$seller] = ($this->makeSeller)('Seller');
    [$mentor] = ($this->makeMentor)('Mentor');

    $this->actingAs($seller)->get('/mentor')->assertForbidden();
    $this->actingAs($mentor)->get('/seller')->assertForbidden();
});

it('keeps a seller out of the admin panel', function (): void {
    [$seller] = ($this->makeSeller)('Seller');

    $this->actingAs($seller)->get('/admin')->assertForbidden();
});

it('keeps an anonymous visitor out of every panel', function (string $panel): void {
    $this->get($panel)->assertRedirect();
})->with(['/admin', '/seller', '/mentor']);
