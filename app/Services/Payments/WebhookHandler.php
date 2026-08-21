<?php

namespace App\Services\Payments;

use App\Models\PaymentWebhook;
use App\Services\Payments\Data\WebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Receiving a webhook.
 *
 * Three rules, in this order:
 *
 *  1. Log it first, whatever it turns out to be. A rejected delivery is the
 *     most interesting kind — a run of invalid signatures is what somebody
 *     probing the endpoint looks like.
 *  2. Never act on one whose signature does not verify.
 *  3. Never act on the same event twice. The database's unique index is what
 *     enforces that, not a read-then-write check that two workers could both
 *     pass.
 */
class WebhookHandler
{
    public const OUTCOME_PROCESSED = 'processed';

    public const OUTCOME_DUPLICATE = 'duplicate';

    public const OUTCOME_INVALID_SIGNATURE = 'invalid_signature';

    public const OUTCOME_IGNORED = 'ignored';

    public const OUTCOME_UNMATCHED = 'unmatched';

    public const OUTCOME_FAILED = 'failed';

    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly PaymentProcessor $payments,
    ) {}

    /**
     * @return array{outcome: string, webhook: PaymentWebhook|null}
     */
    public function handle(string $gatewayKey, Request $request): array
    {
        $gateway = $this->gateways->gateway($gatewayKey);
        $event = $gateway->parseWebhook($request);

        $webhook = $this->log($gatewayKey, $event, $request);

        // A duplicate is caught by the unique index rather than by looking
        // first: two deliveries arriving together would both pass a check.
        if ($webhook === null) {
            return ['outcome' => self::OUTCOME_DUPLICATE, 'webhook' => null];
        }

        if (! $event->signatureValid) {
            Log::warning('Rejected a payment webhook with an invalid signature.', [
                'gateway' => $gatewayKey,
                'ip' => $request->ip(),
            ]);

            $webhook->markProcessed(self::OUTCOME_INVALID_SIGNATURE);

            return ['outcome' => self::OUTCOME_INVALID_SIGNATURE, 'webhook' => $webhook];
        }

        if (! $event->isSuccessfulCharge()) {
            $webhook->markProcessed(self::OUTCOME_IGNORED, 'Not a successful charge event.');

            return ['outcome' => self::OUTCOME_IGNORED, 'webhook' => $webhook];
        }

        if (blank($event->reference)) {
            $webhook->markProcessed(self::OUTCOME_UNMATCHED, 'No order reference on the event.');

            return ['outcome' => self::OUTCOME_UNMATCHED, 'webhook' => $webhook];
        }

        try {
            $changed = $this->payments->markPaidFromReference($gatewayKey, $event->reference);
        } catch (Throwable $exception) {
            report($exception);

            $webhook->markProcessed(self::OUTCOME_FAILED, $exception->getMessage());

            return ['outcome' => self::OUTCOME_FAILED, 'webhook' => $webhook];
        }

        $outcome = $changed ? self::OUTCOME_PROCESSED : self::OUTCOME_DUPLICATE;

        $webhook->markProcessed(
            $outcome,
            $changed ? null : 'The order was already paid, or verification did not match.',
        );

        return ['outcome' => $outcome, 'webhook' => $webhook];
    }

    /**
     * Record the delivery. Returns null when this exact event has already been
     * recorded, which is how a redelivery is recognised.
     */
    private function log(string $gatewayKey, WebhookEvent $event, Request $request): ?PaymentWebhook
    {
        try {
            return PaymentWebhook::query()->create([
                'gateway' => $gatewayKey,
                'event_id' => $event->id,
                'event_type' => $event->type,
                'gateway_reference' => $event->reference,
                'signature_valid' => $event->signatureValid,
                'payload' => $event->payload,
                'ip_address' => $request->ip(),
            ]);
        } catch (QueryException $exception) {
            // A unique violation on (gateway, event_id) is the duplicate we
            // wanted to catch. Anything else is a real problem.
            if ($this->isUniqueViolation($exception)) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * A DUPLICATE, specifically — not any integrity constraint.
     *
     * SQLSTATE 23000 is the general integrity-constraint class: a NOT NULL
     * violation, a foreign key violation and a check constraint all carry it.
     * Treating the whole class as "duplicate" means a genuinely broken insert
     * is answered with `duplicate`, the gateway is told everything is fine, it
     * stops retrying, and a paid order stays unpaid with nothing in the log
     * that looks like a problem.
     *
     * So the driver's own code is what decides: 1062 on MySQL and MariaDB,
     * 23505 on Postgres, and SQLite says so in words.
     */
    private function isUniqueViolation(QueryException $exception): bool
    {
        $driverCode = $exception->errorInfo[1] ?? null;

        return match (true) {
            // MySQL / MariaDB: ER_DUP_ENTRY.
            (int) $driverCode === 1062 => true,
            // Postgres.
            $exception->getCode() === '23505' => true,
            // SQLite reports it in the message rather than a distinct code.
            str_contains(mb_strtolower($exception->getMessage()), 'unique constraint failed') => true,
            default => false,
        };
    }
}
