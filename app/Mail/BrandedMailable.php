<?php

namespace App\Mail;

use App\Services\Branding\BrandingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Every mail the platform sends extends this.
 *
 * Two things happen here that must never be repeated anywhere else: the sender
 * name is stamped from BrandingService at send time, so a rename in Settings
 * changes the "from" on the next email, and the branded layout is what every
 * message renders inside.
 *
 * Mail is queued, always: a slow provider must never hold up a web request.
 */
abstract class BrandedMailable extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Non-transactional mail (announcements, digests) must carry an
     * unsubscribe link. Transactional mail must not.
     */
    protected bool $transactional = true;

    protected ?string $unsubscribeUrl = null;

    /**
     * The subject line, resolved at send time so {company} placeholders in
     * admin-authored subjects expand.
     */
    abstract protected function subjectLine(): string;

    public function envelope(): Envelope
    {
        $branding = app(BrandingService::class);

        return new Envelope(
            from: new Address(
                config('mail.from.address'),
                $branding->name(),
            ),
            replyTo: filled($branding->email())
                ? [new Address($branding->email(), $branding->name())]
                : [],
            subject: $branding->replacePlaceholders($this->subjectLine()) ?? '',
        );
    }

    /**
     * Values every branded template can rely on.
     *
     * @return array<string, mixed>
     */
    protected function layoutData(): array
    {
        return [
            'showUnsubscribe' => ! $this->transactional,
            'unsubscribeUrl' => $this->unsubscribeUrl,
        ];
    }

    public function withUnsubscribeUrl(string $url): static
    {
        $this->transactional = false;
        $this->unsubscribeUrl = $url;

        return $this;
    }
}
