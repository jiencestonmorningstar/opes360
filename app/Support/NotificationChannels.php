<?php

namespace App\Support;

/**
 * The ways this product can reach a person.
 *
 * Each entry maps a business-facing channel onto a Laravel notification
 * channel. That mapping is the whole abstraction, and it is deliberately thin:
 * the product keeps exactly one delivery path — `$user->notify()` — and this
 * table only decides which drivers that one call is handed.
 *
 * A second path was the alternative and would have been worse. A channel class
 * that sent its own mail would have its own failures, its own retries and its
 * own idea of what an unsubscribe means, and "why did this arrive twice" would
 * have two places to look.
 *
 * SMS and WhatsApp are catalogued and unavailable. Every gateway worth using
 * charges per message and needs an account this business does not have, so
 * they are listed here as the shape a future one has to fit rather than half
 * built against a vendor nobody has chosen. A rule may name them; the
 * dispatcher logs the attempt as `channel_unavailable` and sends nothing,
 * because a channel that silently pretends to have sent is the one failure
 * mode a delivery log cannot recover from.
 */
class NotificationChannels
{
    /**
     * key => [label, driver, available, description]
     *
     * `driver` is the Laravel notification channel the message is routed
     * through. To turn a catalogued channel on, write the Laravel channel
     * class, name it here, and flip `available`. Nothing else in the engine
     * changes — see docs/handoff/4.4-integration.md.
     */
    public const CATALOGUE = [
        'in_app' => [
            'label' => 'In the app',
            'driver' => 'database',
            'available' => true,
            'description' => 'The bell in the top bar.',
        ],
        'email' => [
            'label' => 'Email',
            'driver' => 'mail',
            'available' => true,
            'description' => 'Sent to the address on the account.',
        ],
        'sms' => [
            'label' => 'SMS',
            'driver' => null,
            'available' => false,
            'description' => 'Needs a paid gateway account. Not connected.',
        ],
        'whatsapp' => [
            'label' => 'WhatsApp',
            'driver' => null,
            'available' => false,
            'description' => 'Needs a paid gateway account. Not connected.',
        ],
    ];

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return self::CATALOGUE;
    }

    /** The ones that can actually deliver something today. */
    public static function available(): array
    {
        return array_filter(self::CATALOGUE, fn (array $c) => $c['available'] === true);
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::CATALOGUE);
    }

    public static function isAvailable(string $key): bool
    {
        return (self::CATALOGUE[$key]['available'] ?? false) === true;
    }

    /** The Laravel channel a message on this channel is routed through. */
    public static function driver(string $key): ?string
    {
        return self::CATALOGUE[$key]['driver'] ?? null;
    }

    public static function label(string $key): string
    {
        return self::CATALOGUE[$key]['label'] ?? ucfirst($key);
    }
}
