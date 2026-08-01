<?php

namespace App\Services\Billing;

use App\Contracts\MobileMoneyGateway;
use InvalidArgumentException;

/**
 * Looks up a gateway by its `provider` key and tells the billing UI which
 * providers are actually usable — a fresh install with no MTN/Orange
 * credentials set shows neither rather than failing at payment time.
 */
class MobileMoneyGateways
{
    /** @var array<int, class-string<MobileMoneyGateway>> */
    protected static array $classes = [
        MtnMomoGateway::class,
        OrangeMoneyGateway::class,
    ];

    public static function resolve(string $key): MobileMoneyGateway
    {
        foreach (static::$classes as $class) {
            if ($class::key() === $key) {
                return app($class);
            }
        }

        throw new InvalidArgumentException("Unknown mobile money provider [{$key}].");
    }

    /**
     * Providers with credentials configured, for the payment picker.
     *
     * @return array<int, array{key: string, label: string}>
     */
    public static function available(): array
    {
        $available = [];

        foreach (static::$classes as $class) {
            if (static::isConfigured($class)) {
                $available[] = ['key' => $class::key(), 'label' => $class::label()];
            }
        }

        return $available;
    }

    protected static function isConfigured(string $class): bool
    {
        return match ($class) {
            MtnMomoGateway::class => filled(config('services.mtn_momo.subscription_key'))
                && filled(config('services.mtn_momo.api_user'))
                && filled(config('services.mtn_momo.api_key')),
            OrangeMoneyGateway::class => filled(config('services.orange_money.client_id'))
                && filled(config('services.orange_money.client_secret'))
                && filled(config('services.orange_money.merchant_key')),
            default => false,
        };
    }
}
