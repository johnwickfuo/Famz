<?php

use App\Mail\BrandedMailable;
use App\Mail\ContactMessageMail;
use App\Mail\MailTemplateRegistry;
use App\Mail\ReconciliationAlertMail;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Symfony\Component\Finder\Finder;

use function Pest\Laravel\seed;

/**
 * Every template the platform sends can be previewed, and every one renders.
 *
 * This exists because the audit that produced it found the opposite: ten
 * mailables that went out to real people and could not be previewed or tested
 * by anybody, and five more that were named in the specification and had never
 * been written at all. A buyer paid and heard nothing.
 *
 * The registry is what the admin preview screen and `mail:test` both read, so a
 * template missing from it is a template nobody looks at until a customer
 * complains about it.
 */
beforeEach(function (): void {
    seed(RoleSeeder::class);
    seed(SettingsSeeder::class);
});

it('has a preview sample for every mailable', function (): void {
    $classes = collect(iterator_to_array(
        (new Finder)->files()->in(app_path('Mail'))->name('*Mail.php')
    ))
        ->map(fn ($file): string => 'App\\Mail\\'.$file->getBasename('.php'))
        ->filter(fn (string $class): bool => is_subclass_of($class, BrandedMailable::class))
        // Two are addressed to the company rather than to a user, and neither
        // is something an administrator would preview: the contact form's
        // relay, and the nightly reconciliation alert.
        ->reject(fn (string $class): bool => in_array($class, [
            ContactMessageMail::class,
            ReconciliationAlertMail::class,
        ], true));

    $registered = collect(app(MailTemplateRegistry::class)->all())->pluck('class');

    $missing = $classes->diff($registered)->sort()->values()->all();

    expect($missing)->toBe([], "Mailables with no entry in MailTemplateRegistry:\n".implode("\n", $missing));
});

it('renders every registered template', function (): void {
    $registry = app(MailTemplateRegistry::class);
    $broken = [];

    foreach ($registry->all() as $template) {
        try {
            $mailable = $registry->sample($template['key']);

            if ($mailable === null) {
                $broken[] = $template['key'].': the registry has no sample for it';

                continue;
            }

            $html = $mailable->render();

            // A subject line is not optional: it is the only part of a message
            // most people read before deciding whether to open it.
            if (blank($mailable->envelope()->subject)) {
                $broken[] = $template['key'].': no subject line';
            }

            // An unexpanded placeholder is the most visible branding bug there
            // is, and email is the surface where nobody sees it before the
            // recipient does.
            if (str_contains($html, '{company}')) {
                $broken[] = $template['key'].': left a {company} placeholder in the body';
            }
        } catch (Throwable $exception) {
            $broken[] = $template['key'].': '.$exception->getMessage();
        }
    }

    expect($broken)->toBe([], "Templates that would fail to send:\n".implode("\n", $broken));
});

it('would notice a template that cannot render', function (): void {
    /*
     * The control. The loop above catches its own exceptions, so a bug that
     * made every sample return null would produce an empty $broken and a green
     * test. This proves the null branch reports rather than skips.
     */
    expect(app(MailTemplateRegistry::class)->sample('no-such-template'))->toBeNull();
});
