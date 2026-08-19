<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Services\Payments\Gateways\FlutterwaveGateway;
use App\Services\Payments\Gateways\PaystackGateway;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves the payment gateway named in platform settings.
 *
 * Which provider a platform uses is an administrator's decision on a settings
 * screen, so this reads the setting on every resolve rather than binding once.
 */
class PaymentGatewayManager
{
    /**
     * @var array<string, class-string<PaymentGateway>>
     */
    public const GATEWAYS = [
        PaystackGateway::KEY => PaystackGateway::class,
        FlutterwaveGateway::KEY => FlutterwaveGateway::class,
    ];

    public function __construct(private readonly Container $container) {}

    public function gateway(?string $key = null): PaymentGateway
    {
        $key ??= (string) settings('active_payment_gateway', PaystackGateway::KEY);

        $class = self::GATEWAYS[$key] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException(
                "Unknown payment gateway [{$key}]. Known gateways: ".implode(', ', array_keys(self::GATEWAYS)).'.'
            );
        }

        return $this->container->make($class);
    }

    /**
     * Every gateway, whether or not it has been given keys.
     *
     * @return array<int, PaymentGateway>
     */
    public function all(): array
    {
        return array_map(
            fn (string $class): PaymentGateway => $this->container->make($class),
            array_values(self::GATEWAYS),
        );
    }

    /**
     * The gateways a buyer can actually be sent to for a given currency.
     *
     * @return array<int, PaymentGateway>
     */
    public function availableFor(string $currency): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (PaymentGateway $gateway): bool => $gateway->isConfigured()
                && $gateway->supportsCurrency($currency),
        ));
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            PaystackGateway::KEY => 'Paystack',
            FlutterwaveGateway::KEY => 'Flutterwave',
        ];
    }
}
