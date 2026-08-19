<?php

namespace App\Http\Controllers;

use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\WebhookHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The endpoint a payment provider calls.
 *
 * Always answers 200 once the delivery has been recorded, whatever the outcome.
 * A provider that receives anything else retries, and retrying a webhook this
 * application has already stored and deliberately rejected achieves nothing
 * except filling the log — the forensic record is the response that matters.
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $gateway,
        WebhookHandler $handler,
        PaymentGatewayManager $gateways,
    ): JsonResponse {
        if (! array_key_exists($gateway, PaymentGatewayManager::GATEWAYS)) {
            return response()->json(['message' => 'Unknown gateway.'], 404);
        }

        $result = $handler->handle($gateway, $request);

        return response()->json(['outcome' => $result['outcome']]);
    }
}
