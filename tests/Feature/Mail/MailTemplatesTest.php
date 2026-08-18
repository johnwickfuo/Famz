<?php

use App\Enums\RoleName;
use App\Filament\Admin\Pages\MailTemplates;
use App\Mail\BrandedMailable;
use App\Mail\MailTemplateRegistry;
use App\Models\User;
use App\Services\Branding\BrandingKey;
use App\Services\Settings\SettingsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Finder\Finder;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->seed(\Database\Seeders\RoleSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(RoleName::Admin->value);
});

it('lists the mail templates to an administrator', function () {
    $this->actingAs($this->admin)->get('/admin/mail-templates')->assertOk();
});

it('is not reachable by anyone else', function () {
    $mentor = User::factory()->create();
    $mentor->assignRole(RoleName::Mentor->value);

    $this->actingAs($mentor)->get('/admin/mail-templates')->assertForbidden();
});

it('sends a test copy of every template it lists', function () {
    Mail::fake();

    $this->actingAs($this->admin);

    foreach (app(MailTemplateRegistry::class)->all() as $template) {
        livewire(MailTemplates::class)
            ->callAction(
                'sendTest',
                ['email' => 'tester@example.test'],
                arguments: ['template' => $template['key']],
            );
    }

    // Queued, not sent: a slow provider must never hold up the admin request.
    Mail::assertQueuedCount(count(app(MailTemplateRegistry::class)->all()));

    foreach (app(MailTemplateRegistry::class)->all() as $template) {
        Mail::assertQueued(
            $template['class'],
            fn ($mail): bool => $mail->hasTo('tester@example.test'),
        );
    }
});

it('renders every registered template against the current branding', function () {
    app(SettingsService::class)->setMany([
        BrandingKey::Name->value => 'Ilorin Grainstore',
        BrandingKey::Address->value => '14 Taiwo Road, Ilorin',
    ], BrandingKey::GROUP);

    $registry = app(MailTemplateRegistry::class);
    $user = User::factory()->create();

    foreach ($registry->all() as $template) {
        $html = $registry->sample($template['key'], $user)->render();

        expect($html)
            ->toContain('Ilorin Grainstore')
            ->toContain('14 Taiwo Road, Ilorin');
    }
});

it('puts an unsubscribe link on non-transactional mail only', function () {
    $registry = app(MailTemplateRegistry::class);
    $user = User::factory()->create();

    foreach ($registry->all() as $template) {
        $html = $registry->sample($template['key'], $user)->render();

        expect(str_contains($html, 'Unsubscribe'))->toBe(! $template['transactional'],
            "{$template['key']} has the wrong unsubscribe behaviour");
    }
});

it('queues every mail class the platform ships', function () {
    $classes = collect(iterator_to_array(
        (new Finder)->files()->in(app_path('Mail'))->name('*.php')
    ))
        ->map(fn ($file): string => 'App\\Mail\\'.$file->getFilenameWithoutExtension())
        ->filter(fn (string $class): bool => class_exists($class)
            && is_subclass_of($class, BrandedMailable::class))
        ->values();

    expect($classes)->not->toBeEmpty();

    foreach ($classes as $class) {
        expect(is_subclass_of($class, ShouldQueue::class))->toBeTrue("{$class} is not queued");
    }
});

it('never sends platform mail through the host SMTP by default', function () {
    // Deliverability to Gmail and Yahoo depends on this. The shipped default is
    // Mailpit locally; production must name a transactional provider.
    $transactional = ['resend', 'postmark', 'brevo', 'mailgun', 'ses', 'ses-v2'];
    $localOnly = ['mailpit', 'log', 'array'];

    expect([...$transactional, ...$localOnly])->toContain(config('mail.default'));

    // And every provider the platform advertises is actually configured.
    foreach ($transactional as $mailer) {
        if (in_array($mailer, ['ses', 'ses-v2'], true)) {
            continue;
        }

        expect(config("mail.mailers.{$mailer}"))->not->toBeNull("{$mailer} is offered but not configured");
    }
});
