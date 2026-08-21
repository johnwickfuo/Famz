<?php

use App\Documents\CompletionCertificate;
use App\Documents\OrderReceipt;
use App\Documents\QuotationProposalDocument;
use App\Enums\QuotationStatus;
use App\Mail\MailTemplateRegistry;
use App\Models\Quotation;
use App\Models\QuotationRequest;
use App\Models\User;
use App\Services\Branding\BrandingKey;
use App\Services\Branding\BrandingService;
use App\Services\Settings\SettingsService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;

/**
 * The company name, set to values chosen to break things.
 *
 * The rule is that the administrator can type anything into `company_name` and
 * every surface renders it. "Anything" is doing real work in that sentence:
 * a cooperative's registered name runs to eighty characters and will not wrap
 * inside a fixed-width PDF header, and a two-letter name is short enough that
 * anything deriving initials from it, or padding a layout around it, quietly
 * produces nonsense.
 *
 * So rather than one plausible name, every surface below is rendered with both
 * extremes, and asserted to contain the name intact — not truncated, not
 * escaped into something else, and not silently replaced by the fallback.
 */

/**
 * A registered cooperative name of the length people really use.
 */
const LONG_NAME = 'Federated Cooperative Union of Smallholder Poultry and Aquaculture Producers of the Middle Belt';

/**
 * Short enough to break anything that assumes two words or derives initials.
 *
 * Deliberately a pair of letters that does not occur in ordinary English copy.
 * "Ok" was the obvious choice and a bad one: it appears inside "Okay", inside
 * button labels, inside half the placeholder text on the site — so every
 * assertion below would have passed whether the name rendered or not.
 */
const SHORT_NAME = 'Qz';

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->rename = function (string $name): void {
        app(SettingsService::class)->set(BrandingKey::Name->value, $name);

        // The payload is cached forever and memoised per instance, so both have
        // to go or the next read returns the previous name.
        cache()->forget(BrandingService::CACHE_KEY);
        app()->forgetInstance(BrandingService::class);
    };
});

dataset('hostile names', [
    'a very long registered name' => [LONG_NAME],
    'a two-character name' => [SHORT_NAME],
]);

it('renders the name in the page shell, title and meta tags', function (string $name): void {
    ($this->rename)($name);

    $html = $this->get('/')->getContent();

    // The document title and the Open Graph pair are separate code paths, and
    // each has been wrong on its own before.
    preg_match('/<title[^>]*>(.*?)<\/title>/s', $html, $title);
    preg_match('/<meta property="og:site_name" content="([^"]*)"/', $html, $ogSite);

    expect($title[1] ?? '')->toContain(e($name))
        ->and($ogSite[1] ?? '')->toBe(e($name));
})->with('hostile names');

it('renders the name in every transactional email', function (string $name): void {
    ($this->rename)($name);

    $registry = app(MailTemplateRegistry::class);
    $missing = [];

    foreach ($registry->all() as $template) {
        $mailable = $registry->sample($template['key']);

        if ($mailable === null) {
            continue;
        }

        $rendered = $mailable->render();

        /*
         * Both halves. A subject line that says "Your order from Agri Platform"
         * while the body is correctly branded is the exact failure this catches
         * — the fallback leaking into the one line the recipient sees first.
         */
        $subject = $mailable->envelope()->subject ?? '';

        if (! str_contains($rendered, e($name)) && ! str_contains($rendered, $name)) {
            $missing[] = $template['key'].' (body)';
        }

        if (str_contains($subject, (string) config('app.name'))) {
            $missing[] = $template['key'].' (subject fell back to APP_NAME)';
        }
    }

    expect($missing)->toBe([], 'Templates that did not carry the company name: '.implode(', ', $missing));
})->with('hostile names');

it('renders the name on every generated document', function (string $name): void {
    ($this->rename)($name);

    $user = User::factory()->create();

    /*
     * The real document classes rather than a view() call with a made-up slot.
     * Each of these assembles its own issuer block, and the certificate freezes
     * one at issue time — so the ways they can lose the name are all different
     * and none of them is exercised by rendering the shared layout alone.
     */
    $documents = [
        'receipt' => new OrderReceipt(
            reference: 'OR-260821-ABCDEF',
            buyerName: $user->displayName(),
            lines: [['description' => 'Layer feed, 25kg', 'quantity' => 2, 'unit_price' => 18500.0]],
        ),
        'certificate' => CompletionCertificate::for($user, 'Broiler Management'),
    ];

    foreach ($documents as $label => $document) {
        expect(str_contains($document->render(), e($name)))
            ->toBeTrue("the {$label} lost the company name");
    }
})->with('hostile names');

it('renders the name on a quotation proposal', function (string $name): void {
    ($this->rename)($name);

    $request = QuotationRequest::factory()
        ->for(User::factory())
        ->create();

    /*
     * A draft. The issuer name is frozen onto the record when the proposal is
     * sent, deliberately — a proposal already with a bank must not silently
     * rename itself. Until then it renders from live branding, which is the
     * half being audited here.
     */
    $quotation = new Quotation;
    // forceFill: the money and the parent key are guarded, which is the point
    // of guarding them.
    $quotation->forceFill([
        'quotation_request_id' => $request->id,
        'version' => 1,
        'title' => 'Layer house, 2,000 birds',
        'status' => QuotationStatus::Draft,
        'subtotal_kobo' => 4_500_000,
        'total_kobo' => 4_500_000,
        'currency' => 'NGN',
        'valid_until' => now()->addDays(30),
    ])->save();

    $html = (new QuotationProposalDocument($quotation))->render();

    /*
     * The proposal is the document that leaves the platform and goes to a bank,
     * so it is the one where a wrong company name is most expensive.
     */
    expect($html)->toContain(e($name));
})->with('hostile names');

it('introduces the chatbot with the name', function (string $name): void {
    ($this->rename)($name);

    $user = User::factory()->create();

    $html = $this->actingAs($user)->get('/ask')->getContent();

    /*
     * The disclaimer above the input, which names the company in the line
     * telling somebody to book a consultation before acting on an answer. It is
     * built server-side precisely so a redesign of the chat interface cannot
     * lose the name — this asserts that still holds.
     */
    expect($html)->toContain(json_encode($name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
})->with('hostile names');

it('leaves no placeholder unreplaced on any content page', function (string $name): void {
    ($this->rename)($name);

    foreach (['/', '/about', '/how-it-works', '/faq', '/contact', '/terms', '/privacy'] as $path) {
        $html = $this->get($path)->getContent();

        /*
         * The copy is written with {company} placeholders. One that survives to
         * the browser is the most visible possible branding bug, and the
         * cheapest to miss — it only appears on the page nobody reloaded.
         */
        expect($html)
            ->not->toContain('{company}', "{$path} left a placeholder unreplaced")
            ->and($html)->not->toContain('{company_short}');
    }
})->with('hostile names');

it('is measuring the rename and not something incidental', function (string $name): void {
    /*
     * The control. Every assertion above looks for the company name in a
     * rendered surface — which proves nothing unless that name is absent
     * beforehand. Without this, a test that never renamed anything and a test
     * whose rename silently failed would both be green.
     */
    $before = $this->get('/')->getContent();

    expect($before)->not->toContain(e($name));

    ($this->rename)($name);

    expect($this->get('/')->getContent())->toContain(e($name));
})->with('hostile names');

it('brands every error page', function (string $name): void {
    ($this->rename)($name);

    /*
     * The pages nobody looks at. Somebody who follows a stale link to a sold
     * listing sees the 404, and until this audit it was Laravel's own — an
     * unstyled, unbranded page in the middle of an otherwise branded site.
     */
    foreach (['403', '404', '419', '429', '500', '503'] as $status) {
        $html = view("errors.{$status}")->render();

        expect($html)->toContain(e($name))
            ->and($html)->toContain('<title>');
    }
})->with('hostile names');

it('shows the short name in the lockup and the full one in the footer', function (): void {
    $settings = app(SettingsService::class);
    $settings->set(BrandingKey::Name->value, LONG_NAME);
    $settings->set(BrandingKey::ShortName->value, 'Middle Belt Co-op');
    cache()->forget(BrandingService::CACHE_KEY);
    app()->forgetInstance(BrandingService::class);

    $branding = app(BrandingService::class);

    /*
     * The split that makes a ninety-character registered name survivable. The
     * lockup takes the short form; the footer keeps the legal name next to the
     * RC number, which is the line that has to be exact.
     */
    expect($branding->shortName())->toBe('Middle Belt Co-op')
        ->and($branding->name())->toBe(LONG_NAME);

    $html = $this->get('/')->getContent();

    expect($html)->toContain(e('Middle Belt Co-op'))
        ->and($html)->toContain(e(LONG_NAME));
});

it('falls back to the full name when no short name is set', function (): void {
    ($this->rename)(LONG_NAME);

    // Nothing disappears because a field was left blank.
    expect(app(BrandingService::class)->shortName())->toBe(LONG_NAME);
});

it('does not print the RC prefix twice', function (string $typed): void {
    $settings = app(SettingsService::class);
    $settings->set(BrandingKey::RcNumber->value, $typed);
    cache()->forget(BrandingService::CACHE_KEY);
    app()->forgetInstance(BrandingService::class);

    /*
     * Every surface writes "RC" in front of this, and an administrator handed a
     * field labelled "RC number" types the prefix about half the time.
     */
    expect(app(BrandingService::class)->rcNumber())->toBe('1234567');
})->with([
    'bare digits' => ['1234567'],
    'with the prefix' => ['RC 1234567'],
    'with a separator' => ['RC-1234567'],
    'lowercased' => ['rc1234567'],
]);
