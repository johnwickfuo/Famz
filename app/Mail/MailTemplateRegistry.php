<?php

namespace App\Mail;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Every branded mail class the platform can send, with a way to build a
 * realistic sample of each. The admin mail templates page lists these and
 * sends test copies so branding can be checked against the real thing.
 */
class MailTemplateRegistry
{
    /**
     * @return array<int, array{key: string, class: class-string<BrandedMailable>, name: string, description: string, transactional: bool}>
     */
    public function all(): array
    {
        return [
            [
                'key' => 'welcome',
                'class' => WelcomeMail::class,
                'name' => __('Welcome'),
                'description' => __('Sent when somebody finishes registering.'),
                'transactional' => true,
            ],
            [
                'key' => 'account-activated',
                'class' => AccountActivatedMail::class,
                'name' => __('Account activated'),
                'description' => __('Sent when an administrator moves an account from pending to active.'),
                'transactional' => true,
            ],
            [
                'key' => 'certificate-issued',
                'class' => CertificateIssuedMail::class,
                'name' => __('Certificate issued'),
                'description' => __('Sent when a training certificate is issued.'),
                'transactional' => true,
            ],
            [
                'key' => 'announcement',
                'class' => PlatformAnnouncementMail::class,
                'name' => __('Platform announcement'),
                'description' => __('Admin-authored. Carries an unsubscribe link; {company} placeholders are expanded.'),
                'transactional' => false,
            ],
        ];
    }

    /**
     * @return array{key: string, class: class-string<BrandedMailable>, name: string, description: string, transactional: bool}|null
     */
    public function find(string $key): ?array
    {
        return collect($this->all())->firstWhere('key', $key);
    }

    /**
     * Build a sample of one template, using the given user where the template
     * needs one. Nothing is persisted.
     */
    public function sample(string $key, ?User $user = null): ?BrandedMailable
    {
        $template = $this->find($key);

        if ($template === null) {
            return null;
        }

        $user ??= $this->placeholderUser();

        return match ($template['class']) {
            WelcomeMail::class => new WelcomeMail($user),
            AccountActivatedMail::class => new AccountActivatedMail($user),
            CertificateIssuedMail::class => new CertificateIssuedMail(
                $user,
                __('Brooder management for day-old chicks'),
                'CERT-'.Str::upper(Str::random(8)),
            ),
            PlatformAnnouncementMail::class => new PlatformAnnouncementMail(
                __('A note from {company}'),
                __("This is a preview of how an announcement from {company} looks.\n\nAnything an administrator writes here is sent with the platform's own branding, and {company_short} is filled in from the settings screen."),
                url('/'),
            ),
            default => null,
        };
    }

    /**
     * A user that exists only for the duration of a preview.
     */
    private function placeholderUser(): User
    {
        $user = new User([
            'name' => __('Test recipient'),
            'email' => 'preview@example.test',
            'status' => UserStatus::Active,
        ]);

        $user->id = 0;
        $user->exists = false;

        return $user;
    }
}
