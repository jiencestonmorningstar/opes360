<?php

namespace App\Support;

/**
 * The groups a person mutes.
 *
 * Not the same list as DomainEvents, and deliberately much shorter. Events are
 * written for whoever builds a rule; categories are written for whoever
 * receives one. A preferences screen offering sixty switches gets one of two
 * responses — all on, or all off — and both are the same as having no screen.
 *
 * A rule names its category. Several rules may share one, which is the point:
 * "no more stock alerts" has to work without the person knowing that three
 * separate rules produce them.
 */
class NotificationCategories
{
    /** key => [label, description] */
    public const CATALOGUE = [
        'approvals' => [
            'label' => 'Approvals',
            'description' => 'Something is waiting for your decision.',
        ],
        'money' => [
            'label' => 'Money',
            'description' => 'Invoices, payments, expenses and what they cost.',
        ],
        'documents' => [
            'label' => 'Documents',
            'description' => 'Papers filed, signed, published or expiring.',
        ],
        'stock' => [
            'label' => 'Stock',
            'description' => 'Items running low, moving, or counted.',
        ],
        'people' => [
            'label' => 'People',
            'description' => 'Staff, leave and payroll.',
        ],
        'operations' => [
            'label' => 'Operations',
            'description' => 'Day-to-day running of the business.',
        ],
        'automation' => [
            'label' => 'Automation',
            'description' => 'Messages your own rules asked for.',
        ],
        'system' => [
            'label' => 'System',
            'description' => 'The account itself — your plan, your access, your security.',
        ],
    ];

    /**
     * How loud a notification is allowed to be.
     *
     * `critical` is the only level that ignores mutes, digests and quiet
     * hours. Keeping exactly one such level is what makes the other two safe
     * to switch off — if `normal` could also break through, nobody could
     * trust a mute, and untrusted mutes get replaced with mail filters that
     * hide the critical ones too.
     */
    public const SEVERITIES = [
        'critical' => 'Needs a decision — always delivered',
        'normal' => 'Worth interrupting for',
        'low' => 'Only if you asked for it',
    ];

    /** @return array<string, array<string, string>> */
    public static function all(): array
    {
        return self::CATALOGUE;
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::CATALOGUE);
    }

    public static function label(string $key): string
    {
        return self::CATALOGUE[$key]['label'] ?? ucfirst($key);
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::CATALOGUE);
    }

    public static function severityExists(string $key): bool
    {
        return array_key_exists($key, self::SEVERITIES);
    }
}
