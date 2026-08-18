<?php

use App\Enums\BusinessType;
use App\Enums\ProductStatus;
use App\Enums\RoleName;
use App\Enums\SellerStatus;
use App\Mail\SellerApprovedMail;
use App\Mail\SellerMoreInfoMail;
use App\Mail\SellerRejectedMail;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Sellers\SellerApplicationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->seed(Database\Seeders\RoleSeeder::class);
    $this->seed(Database\Seeders\CategorySeeder::class);

    $this->applicant = User::factory()->create();
    $this->applications = app(SellerApplicationService::class);
});

/**
 * @return array<string, mixed>
 */
function applicationPayload(array $overrides = []): array
{
    return [
        'business_name' => 'Ilorin Feed Depot',
        'cac_number' => 'RC1234567',
        'business_type' => BusinessType::RegisteredCompany->value,
        'address' => '14 Taiwo Road',
        'state' => 'Kwara',
        'lga' => 'Ilorin West',
        'phone' => '08030000000',
        'whatsapp' => '08030000001',
        'description' => 'We sell poultry feed, day-old chicks and brooding equipment across Kwara State and have traded at Ipata market since 2011.',
        'categories' => Category::query()->active()->limit(3)->pluck('id')->all(),
        'id_document' => UploadedFile::fake()->image('id.jpg'),
        ...$overrides,
    ];
}

// ---------------------------------------------------------------------------
// Applying
// ---------------------------------------------------------------------------

it('shows the application form to a signed-in user', function () {
    $this->actingAs($this->applicant)->get('/sell')->assertOk();
});

it('does not show the application form to a guest', function () {
    $this->get('/sell')->assertRedirect(route('login'));
});

it('creates a pending seller profile from a completed application', function () {
    Storage::fake('local');

    $this->actingAs($this->applicant)
        ->post('/sell', applicationPayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('seller-application.create'));

    $seller = SellerProfile::where('user_id', $this->applicant->id)->firstOrFail();

    expect($seller->status)->toBe(SellerStatus::Pending)
        ->and($seller->business_name)->toBe('Ilorin Feed Depot')
        ->and($seller->state)->toBe('Kwara')
        ->and($seller->slug)->toBe('ilorin-feed-depot')
        ->and($seller->submitted_at)->not->toBeNull()
        ->and($seller->categories)->toHaveCount(3);

    Storage::disk('local')->assertExists($seller->id_document);
});

it('does not hand out the seller role just for applying', function () {
    Storage::fake('local');

    $this->actingAs($this->applicant)->post('/sell', applicationPayload());

    expect($this->applicant->fresh()->hasRole(RoleName::Seller->value))->toBeFalse();
});

it('lets an unregistered trader apply without a CAC number', function () {
    Storage::fake('local');

    $this->actingAs($this->applicant)
        ->post('/sell', applicationPayload([
            'cac_number' => '',
            'business_type' => BusinessType::SoleProprietor->value,
        ]))
        ->assertSessionHasNoErrors();

    expect(SellerProfile::where('user_id', $this->applicant->id)->exists())->toBeTrue();
});

it('requires the fields an application cannot do without', function (string $field) {
    Storage::fake('local');

    $this->actingAs($this->applicant)
        ->post('/sell', applicationPayload([$field => $field === 'categories' ? [] : '']))
        ->assertSessionHasErrors($field);
})->with(['business_name', 'business_type', 'address', 'state', 'lga', 'phone', 'description', 'categories']);

it('requires an ID document on a first application', function () {
    Storage::fake('local');

    $payload = applicationPayload();
    unset($payload['id_document']);

    $this->actingAs($this->applicant)
        ->post('/sell', $payload)
        ->assertSessionHasErrors('id_document');
});

it('rejects a state that is not a Nigerian state', function () {
    Storage::fake('local');

    $this->actingAs($this->applicant)
        ->post('/sell', applicationPayload(['state' => 'Atlantis']))
        ->assertSessionHasErrors('state');
});

it('lets an applicant resubmit after more information was requested, keeping the stored document', function () {
    Storage::fake('local');

    $this->actingAs($this->applicant)->post('/sell', applicationPayload());
    $seller = SellerProfile::where('user_id', $this->applicant->id)->firstOrFail();
    $document = $seller->id_document;

    $this->applications->requestMoreInformation($seller, 'Please send a clearer photograph of your ID.');

    $payload = applicationPayload(['business_name' => 'Ilorin Feed Depot Limited']);
    unset($payload['id_document']);

    $this->actingAs($this->applicant)
        ->post('/sell', $payload)
        ->assertSessionHasNoErrors();

    $seller->refresh();

    expect($seller->status)->toBe(SellerStatus::Pending)
        ->and($seller->business_name)->toBe('Ilorin Feed Depot Limited')
        ->and($seller->id_document)->toBe($document)
        // The old request has been answered, so it should no longer be shown.
        ->and($seller->review_notes)->toBeNull();
});

it('will not let a rejected applicant quietly resubmit through the same route', function () {
    Storage::fake('local');

    $seller = SellerProfile::factory()->rejected()->create(['user_id' => $this->applicant->id]);

    $this->actingAs($this->applicant)
        ->post('/sell', applicationPayload())
        ->assertForbidden();

    expect($seller->fresh()->status)->toBe(SellerStatus::Rejected);
});

// ---------------------------------------------------------------------------
// Reviewing
// ---------------------------------------------------------------------------

it('grants the seller role and emails the applicant on approval', function () {
    Mail::fake();

    $seller = SellerProfile::factory()->create(['user_id' => $this->applicant->id]);

    $this->applications->approve($seller, User::factory()->create());

    $seller->refresh();

    expect($seller->status)->toBe(SellerStatus::Approved)
        ->and($seller->reviewed_at)->not->toBeNull()
        ->and($seller->reviewed_by)->not->toBeNull()
        ->and($this->applicant->fresh()->hasRole(RoleName::Seller->value))->toBeTrue();

    Mail::assertQueued(SellerApprovedMail::class, fn ($mail): bool => $mail->hasTo($this->applicant->email));
});

it('records the reason and emails the applicant on rejection', function () {
    Mail::fake();

    $seller = SellerProfile::factory()->create(['user_id' => $this->applicant->id]);

    $this->applications->reject($seller, 'The ID document was not readable.');

    $seller->refresh();

    expect($seller->status)->toBe(SellerStatus::Rejected)
        ->and($seller->review_notes)->toBe('The ID document was not readable.')
        ->and($this->applicant->fresh()->hasRole(RoleName::Seller->value))->toBeFalse();

    Mail::assertQueued(SellerRejectedMail::class);
});

it('takes a rejected seller\'s live listings out of the catalogue but leaves their drafts', function () {
    Mail::fake();

    $seller = SellerProfile::factory()->approved()->create(['user_id' => $this->applicant->id]);
    $live = Product::factory()->for($seller, 'seller')->create();
    $draft = Product::factory()->draft()->for($seller, 'seller')->create();

    $this->applications->reject($seller, 'Repeated complaints from buyers.');

    expect($live->fresh()->status)->toBe(ProductStatus::Rejected)
        ->and($draft->fresh()->status)->toBe(ProductStatus::Draft);
});

it('leaves the application open for editing when more information is requested', function () {
    Mail::fake();

    $seller = SellerProfile::factory()->create(['user_id' => $this->applicant->id]);

    $this->applications->requestMoreInformation($seller, 'Your address does not match the LGA given.');

    $seller->refresh();

    expect($seller->status)->toBe(SellerStatus::NeedsMoreInfo)
        ->and($seller->status->isOpenToApplicant())->toBeTrue()
        ->and($seller->review_notes)->toBe('Your address does not match the LGA given.');

    Mail::assertQueued(SellerMoreInfoMail::class);
});

it('only lets an approved seller sell', function () {
    expect(SellerProfile::factory()->create()->canSell())->toBeFalse()
        ->and(SellerProfile::factory()->needsMoreInfo()->create()->canSell())->toBeFalse()
        ->and(SellerProfile::factory()->rejected()->create()->canSell())->toBeFalse()
        ->and(SellerProfile::factory()->approved()->create()->canSell())->toBeTrue();
});
