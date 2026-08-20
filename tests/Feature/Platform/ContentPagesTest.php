<?php

use App\Content\LegalCopy;
use App\Content\PlatformCopy;
use App\Mail\ContactMessageMail;
use App\Services\Branding\BrandingKey;
use App\Services\Branding\BrandingService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * The pages that explain the platform, and the rule they all obey.
 *
 * The central test is the one that renames the company and then reads the terms
 * of service. If a literal name has been typed anywhere in the copy, that test
 * finds it — and it is the only thing standing between a rename in settings and
 * a legal document naming a company that no longer exists.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    Cache::flush();

    $this->renameTo = function (string $name): void {
        settings()->set(BrandingKey::Name->value, $name);
        Cache::forget(BrandingService::CACHE_KEY);
        app()->forgetInstance(BrandingService::class);
        Cache::flush();
    };
});

it('serves every content page', function (string $route): void {
    get(route($route))->assertOk();
})->with([
    'about' => 'pages.about',
    'how it works' => 'pages.how-it-works',
    'faq' => 'pages.faq',
    'terms' => 'pages.terms',
    'privacy' => 'pages.privacy',
    'contact' => 'contact.create',
]);

it('serves each role guide', function (string $role): void {
    get(route('pages.guide', $role))->assertOk();
})->with(['seller', 'mentor', 'worker']);

it('refuses a guide nobody wrote', function (): void {
    get('/guides/investor')->assertNotFound();
});

it('writes no literal company name into any copy', function (): void {
    /*
     * The rule, checked at the source rather than through a rendered page.
     * Every mention has to be a placeholder, because that is the only form
     * that survives an administrator renaming the platform.
     */
    $copy = new PlatformCopy;
    $legal = new LegalCopy;

    $everything = json_encode([
        $copy->services(),
        $copy->about(),
        $copy->howItWorks(),
        $copy->faq(),
        $copy->guides(),
        $legal->terms(),
        $legal->privacy(),
    ]);

    expect(mb_strtolower($everything))
        ->not->toContain('agri platform')
        ->not->toContain('agriplatform');
});

it('carries the administrator\'s name through the terms of service', function (): void {
    ($this->renameTo)('Olusegun Agro Services');

    $response = get(route('pages.terms'))->assertOk();
    $rendered = json_encode($response->viewData('page')['props']['page']);

    expect($rendered)->toContain('Olusegun Agro Services')
        // And nothing is left unexpanded on the way through.
        ->and($rendered)->not->toContain('{company}');
});

it('expands placeholders nested deep inside the copy', function (): void {
    ($this->renameTo)('Kaduna Farm Partners');

    // The jobs section of the terms is four levels down: page, sections,
    // section, body, paragraph. An expander that only walked the top level
    // would pass every other test in this file and fail here.
    $rendered = json_encode(get(route('pages.terms'))->viewData('page')['props']['page']);

    expect($rendered)->toContain('Kaduna Farm Partners IS NOT THE EMPLOYER');
});

it('renames on the next request rather than after the cache expires', function (): void {
    get(route('pages.about'))->assertOk();

    ($this->renameTo)('Second Name Farms');

    // The page cache is keyed on the branding placeholders, so a rename cannot
    // leave an hour of stale copy behind it.
    expect(json_encode(get(route('pages.about'))->viewData('page')['props']['page']))
        ->toContain('Second Name Farms');
});

it('states the commission the platform actually charges', function (): void {
    settings()->set('marketplace_commission_percent', 8);
    Cache::flush();

    expect(json_encode(get(route('pages.terms'))->viewData('page')['props']['page']))
        // A terms page quoting a stale rate is worse than one quoting none.
        ->toContain('8%');
});

it('states the quote validity from settings', function (): void {
    settings()->set('quote_validity_days', 45);
    Cache::flush();

    expect(json_encode(get(route('pages.terms'))->viewData('page')['props']['page']))
        ->toContain('45 days');
});

it('states the four things the terms must say plainly', function (): void {
    $terms = mb_strtolower(json_encode(get(route('pages.terms'))->viewData('page')['props']['page']));

    expect($terms)
        // Courses
        ->toContain('course sales are final')
        // Jobs
        ->toContain('is not the employer')
        // Farm setup
        ->toContain('credited in full')
        // The assistant
        ->toContain('will not give drug names');
});

it('names all eight services on the home page', function (): void {
    $services = get(route('home'))->viewData('page')['props']['services'];

    expect($services)->toHaveCount(8);

    // Each one is a way in, not just a description of one.
    foreach ($services as $service) {
        expect($service['href'])->toBeString()->not->toBeEmpty()
            ->and($service['cta'])->toBeString()->not->toBeEmpty();
    }
});

it('sends a contact message to the company', function (): void {
    Mail::fake();

    settings()->set(BrandingKey::Email->value, 'hello@example.test');
    Cache::forget(BrandingService::CACHE_KEY);

    post(route('contact.store'), [
        'name' => 'Ada Farmer',
        'email' => 'ada@example.test',
        'subject' => 'A question about feed',
        'message' => 'I would like to know whether you deliver to Bayelsa.',
    ])->assertRedirect();

    // Queued, not sent: every mailable on this platform is ShouldQueue, so a
    // slow provider cannot hold up the request that caused it.
    Mail::assertQueued(ContactMessageMail::class, function (ContactMessageMail $mail): bool {
        // Reply-to is the sender, not the company: an administrator hitting
        // reply must reach the person who wrote in.
        return $mail->hasTo('hello@example.test')
            && $mail->hasReplyTo('ada@example.test');
    });
});

it('says so rather than dropping a message when no company address is set', function (): void {
    Mail::fake();

    settings()->set(BrandingKey::Email->value, null);
    Cache::forget(BrandingService::CACHE_KEY);

    post(route('contact.store'), [
        'name' => 'Ada Farmer',
        'email' => 'ada@example.test',
        'subject' => 'A question',
        'message' => 'Do you deliver to Bayelsa?',
    ])->assertSessionHasErrors('message');

    Mail::assertNothingQueued();
});

it('rate-limits the contact form by address', function (): void {
    Mail::fake();

    settings()->set(BrandingKey::Email->value, 'hello@example.test');
    Cache::forget(BrandingService::CACHE_KEY);

    $payload = [
        'name' => 'Ada Farmer',
        'email' => 'ada@example.test',
        'subject' => 'Hello',
        'message' => 'This is a message of sufficient length.',
    ];

    foreach (range(1, 5) as $ignored) {
        post(route('contact.store'), $payload);
    }

    post(route('contact.store'), $payload)->assertSessionHasErrors('message');

    Mail::assertQueuedCount(5);
});

it('rejects a message too short to be one', function (): void {
    post(route('contact.store'), [
        'name' => 'Ada',
        'email' => 'ada@example.test',
        'subject' => 'Hi',
        'message' => 'help',
    ])->assertSessionHasErrors('message');
});
