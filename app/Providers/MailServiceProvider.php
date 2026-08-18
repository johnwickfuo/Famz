<?php

namespace App\Providers;

use App\Services\Branding\BrandingService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoApiTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Platform mail goes through a transactional provider, never through the
 * server's own SMTP: mail relayed from a VPS lands in spam at Gmail and Yahoo.
 *
 * Resend, Postmark and Mailgun are first-party Laravel transports. Brevo is a
 * Symfony bridge, so it is registered here.
 */
class MailServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->brandFrameworkNotifications();

        Mail::extend('brevo', function (array $config = []): TransportInterface {
            return new BrevoApiTransport(
                (string) ($config['key'] ?? config('services.brevo.key')),
                $this->app->bound(HttpClientInterface::class)
                    ? $this->app->make(HttpClientInterface::class)
                    : null,
            );
        });
    }

    /**
     * The framework's own verification and password-reset notifications ship
     * with Laravel's generic markdown layout, which takes its heading from the
     * framework configuration rather than from settings. Both are re-pointed at
     * the branded layout so every message a person receives carries the same
     * identity, and follows a rename.
     */
    private function brandFrameworkNotifications(): void
    {
        $branding = fn (): BrandingService => app(BrandingService::class);

        VerifyEmail::toMailUsing(fn ($notifiable, string $url): MailMessage => (new MailMessage)
            ->from(config('mail.from.address'), $branding()->name())
            ->subject($branding()->replacePlaceholders(__('Verify your email for {company}')))
            ->view('emails.messages.verify-email', ['url' => $url]));

        ResetPassword::toMailUsing(fn ($notifiable, string $token): MailMessage => (new MailMessage)
            ->from(config('mail.from.address'), $branding()->name())
            ->subject($branding()->replacePlaceholders(__('Reset your {company_short} password')))
            ->view('emails.messages.reset-password', [
                'url' => url(route('password.reset', [
                    'token' => $token,
                    'email' => $notifiable->getEmailForPasswordReset(),
                ], absolute: false)),
                'expiresInMinutes' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire'),
            ]));
    }
}
