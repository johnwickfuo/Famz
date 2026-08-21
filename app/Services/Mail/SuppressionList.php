<?php

namespace App\Services\Mail;

use App\Models\EmailSuppression;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Which addresses the platform must not write to, and why.
 *
 * The cost of getting this wrong is not one undelivered message. A provider
 * scores the whole platform on how much of its mail bounces and how often
 * people mark it as spam, and that score decides whether a seller's order
 * notification reaches their inbox at all. Retrying a dead address forever
 * spends everybody's deliverability on nobody's benefit.
 */
class SuppressionList
{
    /**
     * Cached because it is consulted on every single outbound message.
     */
    private const CACHE_TTL = 300;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function suppress(
        string $email,
        string $type,
        ?string $provider = null,
        ?string $reason = null,
        array $payload = [],
    ): EmailSuppression {
        $email = $this->normalise($email);

        /*
         * updateOrCreate on the address, not create.
         *
         * Providers redeliver webhooks, and an address that bounces on Monday
         * and complains on Friday is still one address. A second row would
         * violate the unique index and turn a redelivery into a 500, which
         * providers respond to by retrying harder.
         */
        $suppression = EmailSuppression::query()->firstOrNew(['email' => $email]);

        $suppression->forceFill([
            'email' => $email,
            'type' => $type,
            'provider' => $provider,
            'reason' => $reason,
            'payload' => $payload,
            'suppressed_at' => now(),
            /*
             * A fresh event un-releases an address somebody had cleared by
             * hand: the provider is telling us it is still broken.
             *
             * forceFill rather than updateOrCreate, because these two columns
             * are deliberately not mass-assignable — and mass assignment drops
             * what it cannot fill silently, so an address released last week
             * and bouncing again today would have stayed released forever.
             */
            'released_at' => null,
            'released_by' => null,
        ])->save();

        $this->forget($email);

        return $suppression;
    }

    public function release(string $email, ?User $by = null): bool
    {
        $email = $this->normalise($email);

        $updated = EmailSuppression::query()
            ->where('email', $email)
            ->whereNull('released_at')
            ->update(['released_at' => now(), 'released_by' => $by?->getKey()]);

        $this->forget($email);

        return $updated > 0;
    }

    public function isSuppressed(string $email): bool
    {
        $email = $this->normalise($email);

        return Cache::remember(
            $this->cacheKey($email),
            self::CACHE_TTL,
            fn (): bool => EmailSuppression::query()->active()->where('email', $email)->exists(),
        );
    }

    public function find(string $email): ?EmailSuppression
    {
        return EmailSuppression::query()
            ->active()
            ->where('email', $this->normalise($email))
            ->first();
    }

    private function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function cacheKey(string $email): string
    {
        return 'mail:suppressed:'.sha1($email);
    }

    private function forget(string $email): void
    {
        Cache::forget($this->cacheKey($email));
    }
}
