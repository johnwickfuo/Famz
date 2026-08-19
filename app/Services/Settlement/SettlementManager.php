<?php

namespace App\Services\Settlement;

use App\Contracts\SettlementDriver;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves the settlement driver named in platform settings.
 *
 * Switching a platform between escrow and instant settlement is an
 * administrator's decision made on a settings screen, not a deploy — so this
 * reads the setting on every resolve rather than binding once at boot.
 */
class SettlementManager
{
    /**
     * @var array<string, class-string<SettlementDriver>>
     */
    public const DRIVERS = [
        EscrowDriver::KEY => EscrowDriver::class,
        InstantDriver::KEY => InstantDriver::class,
    ];

    public function __construct(private readonly Container $container) {}

    public function driver(?string $key = null): SettlementDriver
    {
        $key ??= (string) settings('settlement_driver', EscrowDriver::KEY);

        $class = self::DRIVERS[$key] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException(
                "Unknown settlement driver [{$key}]. Known drivers: ".implode(', ', array_keys(self::DRIVERS)).'.'
            );
        }

        return $this->container->make($class);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            EscrowDriver::KEY => __('Escrow — money is held until the buyer confirms delivery'),
            InstantDriver::KEY => __('Instant — the seller is paid as soon as the payment clears'),
        ];
    }
}
