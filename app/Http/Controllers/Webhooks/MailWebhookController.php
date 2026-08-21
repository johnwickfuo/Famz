<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\EmailSuppression;
use App\Services\Mail\SuppressionList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Bounce and complaint webhooks from the mail provider.
 *
 * Four providers are supported and each describes the same two events
 * differently, so the shapes are normalised here rather than anywhere the rest
 * of the application can see them.
 *
 * Two things are deliberately not done.
 *
 * A *soft* bounce is ignored. A full mailbox or a server having a bad afternoon
 * is not a dead address, and suppressing on one would lock people out of their
 * own receipts for a temporary fault. Only a hard bounce and a spam complaint
 * suppress.
 *
 * And the endpoint answers 200 to anything it does not understand. A provider
 * that gets a 4xx or 5xx retries, backs off, and eventually disables the
 * webhook — so an unrecognised event type returning an error would end with the
 * bounces we *do* care about no longer arriving at all.
 */
class MailWebhookController extends Controller
{
    /**
     * Event names that mean the address is dead, by provider.
     *
     * Deliberately explicit rather than matched on a substring: Postmark's
     * "SoftBounce" and "HardBounce" differ by four characters, and a substring
     * match on "Bounce" would suppress people for a full mailbox.
     */
    private const HARD_BOUNCE = [
        'resend' => ['email.bounced'],
        'postmark' => ['HardBounce', 'BadEmailAddress', 'SpamNotification', 'ManuallyDeactivated'],
        'brevo' => ['hard_bounce', 'blocked', 'invalid_email'],
        'mailgun' => ['failed', 'rejected'],
    ];

    private const COMPLAINT = [
        'resend' => ['email.complained'],
        'postmark' => ['SpamComplaint'],
        'brevo' => ['spam', 'unsubscribed'],
        'mailgun' => ['complained', 'unsubscribed'],
    ];

    public function __invoke(Request $request, string $provider, SuppressionList $suppressions): JsonResponse
    {
        if (! $this->authorised($request)) {
            // The one case that does get an error: an unauthenticated caller is
            // not a provider whose retries we care about preserving.
            return response()->json(['message' => 'Unauthorised.'], 401);
        }

        if (! array_key_exists($provider, self::HARD_BOUNCE)) {
            return response()->json(['message' => 'Unknown provider.'], 404);
        }

        $payload = $request->all();
        $event = $this->eventName($provider, $payload);
        $email = $this->recipient($provider, $payload);

        if ($email === null || $event === null) {
            Log::info('A mail webhook arrived without an address or an event name.', [
                'provider' => $provider,
            ]);

            return response()->json(['message' => 'Ignored.']);
        }

        $type = match (true) {
            in_array($event, self::HARD_BOUNCE[$provider], true) => EmailSuppression::TYPE_BOUNCE,
            in_array($event, self::COMPLAINT[$provider], true) => EmailSuppression::TYPE_COMPLAINT,
            // A soft bounce, a delivery, an open. Nothing to do, and answering
            // 200 keeps the provider sending us the ones that matter.
            default => null,
        };

        if ($type === null) {
            return response()->json(['message' => 'Ignored.']);
        }

        $suppressions->suppress(
            email: $email,
            type: $type,
            provider: $provider,
            reason: $this->reason($provider, $payload) ?? $event,
            payload: $payload,
        );

        Log::warning('An address has been suppressed.', [
            'provider' => $provider,
            'event' => $event,
            'type' => $type,
        ]);

        return response()->json(['message' => 'Recorded.']);
    }

    /**
     * A shared secret, checked in constant time.
     *
     * Of the four providers only two sign their payloads, and they sign them
     * differently. A secret in the URL is what all four can do, and it is the
     * difference between an endpoint anybody can use to silence a competitor's
     * mail and one they cannot.
     */
    private function authorised(Request $request): bool
    {
        $expected = (string) config('services.mail_webhook.secret');

        if ($expected === '') {
            // Unconfigured means closed. An open suppression endpoint is worse
            // than no endpoint: anybody could post a rival seller's address.
            return false;
        }

        $given = (string) ($request->query('token') ?? $request->header('X-Webhook-Token', ''));

        return hash_equals($expected, $given);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function eventName(string $provider, array $payload): ?string
    {
        $value = match ($provider) {
            'resend' => $payload['type'] ?? null,
            'postmark' => $payload['RecordType'] ?? null,
            'brevo' => $payload['event'] ?? null,
            'mailgun' => $payload['event-data']['event'] ?? $payload['event'] ?? null,
            default => null,
        };

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recipient(string $provider, array $payload): ?string
    {
        $value = match ($provider) {
            // Resend sends `to` as an array, because one message may have had
            // several recipients and only one of them bounced.
            'resend' => is_array($payload['data']['to'] ?? null)
                ? ($payload['data']['to'][0] ?? null)
                : ($payload['data']['to'] ?? null),
            'postmark' => $payload['Email'] ?? $payload['Recipient'] ?? null,
            'brevo' => $payload['email'] ?? null,
            'mailgun' => $payload['event-data']['recipient'] ?? $payload['recipient'] ?? null,
            default => null,
        };

        return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function reason(string $provider, array $payload): ?string
    {
        $value = match ($provider) {
            'resend' => $payload['data']['bounce']['message'] ?? null,
            'postmark' => $payload['Description'] ?? $payload['Details'] ?? null,
            'brevo' => $payload['reason'] ?? null,
            'mailgun' => $payload['event-data']['delivery-status']['message'] ?? null,
            default => null,
        };

        return is_string($value) ? mb_substr($value, 0, 500) : null;
    }
}
