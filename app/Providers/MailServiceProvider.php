<?php

namespace App\Providers;

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
        Mail::extend('brevo', function (array $config = []): TransportInterface {
            return new BrevoApiTransport(
                (string) ($config['key'] ?? config('services.brevo.key')),
                $this->app->bound(HttpClientInterface::class)
                    ? $this->app->make(HttpClientInterface::class)
                    : null,
            );
        });
    }
}
