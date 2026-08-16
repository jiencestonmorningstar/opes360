<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Artisan;
use App\Models\BankAccount;
use App\Models\BusinessDocument;
use App\Models\CompanyUserPermission;
use App\Models\Contact;
use App\Models\Document;
use App\Models\EmploymentContract;
use App\Models\ExpenseClaim;
use App\Models\Item;
use App\Models\LedgerAccount;
use App\Models\PaymentRun;
use App\Models\PayrollRun;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Str;

/**
 * Turning the trail's stored class names and event slugs into English.
 *
 * The log stores `App\Models\BusinessDocument` and `permission-changed` because
 * those are stable; a screen that shows them to a business owner during a
 * dispute is a screen that gets ignored.
 */
class AuditSubjects
{
    /** Only where the guessed name would be wrong or unhelpful. */
    protected const NAMES = [
        Document::class => 'Invoice or quote',
        BusinessDocument::class => 'Document',
        Contact::class => 'Customer or supplier',
        Item::class => 'Product',
        Artisan::class => 'Artisan profile',
        CompanyUserPermission::class => 'Permission grant',
        PaymentRun::class => 'Payment run',
        PayrollRun::class => 'Payroll run',
        EmploymentContract::class => 'Employment contract',
        ExpenseClaim::class => 'Expense claim',
        LedgerAccount::class => 'Ledger account',
        BankAccount::class => 'Bank account',
        WebhookEndpoint::class => 'Webhook endpoint',
    ];

    /** event slug => how it reads in a sentence about a record */
    public const EVENTS = [
        'created' => 'Created',
        'updated' => 'Changed',
        'deleted' => 'Deleted',
        'trashed' => 'Moved to trash',
        'restored' => 'Restored',
        'accessed' => 'Opened',
        'exported' => 'Exported',
        'permission-changed' => 'Permissions changed',
    ];

    /** @return array<string, string> */
    public static function events(): array
    {
        return self::EVENTS;
    }

    public static function eventLabel(?string $event): string
    {
        return self::EVENTS[$event] ?? Str::headline((string) $event);
    }

    public static function typeLabel(?string $class): string
    {
        if (blank($class)) {
            return 'The business';
        }

        return self::NAMES[$class] ?? Str::headline(class_basename($class));
    }

    /**
     * The record types this business actually has history for.
     *
     * Built from the log rather than from the observer registration list: a
     * business a week old has touched four kinds of thing, and offering it a
     * filter with thirty empty options is worse than offering four real ones.
     *
     * @return array<string, string> class => label
     */
    public static function typesPresentIn(?string $companyId): array
    {
        if ($companyId === null) {
            return [];
        }

        return ActivityLog::query()
            ->where('company_id', $companyId)
            ->whereNotNull('subject_type')
            ->distinct()
            ->pluck('subject_type')
            ->mapWithKeys(fn (string $class) => [$class => self::typeLabel($class)])
            ->sort()
            ->all();
    }

    /**
     * What changed, as a short phrase.
     *
     * Field names only, never values — the list is read over shoulders and in
     * screenshots, and a summary line that prints the old and new salary has
     * leaked the thing the detail view is gated for.
     */
    public static function summarise(ActivityLog $entry): string
    {
        $properties = (array) $entry->properties;

        return match ($entry->event) {
            'updated' => static::fieldPhrase(array_keys((array) ($properties['after'] ?? []))),
            'accessed' => (string) ($properties['reason'] ?? 'Opened a restricted record'),
            'exported' => trim(($properties['rows'] ?? '?').' rows'),
            'permission-changed' => (string) ($properties['summary'] ?? 'Permissions changed'),
            default => '',
        };
    }

    /** @param array<int, string> $fields */
    protected static function fieldPhrase(array $fields): string
    {
        if ($fields === []) {
            return '';
        }

        $names = collect($fields)->map(fn (string $f) => Str::lower(Str::headline($f)));

        if ($names->count() <= 3) {
            return $names->join(', ', ' and ');
        }

        return $names->take(2)->join(', ').' and '.($names->count() - 2).' more';
    }
}
