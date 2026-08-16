<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * The one way anything is written to the audit trail.
 *
 * AuditObserver captures writes automatically; this is how the rest of the
 * system records the things an Eloquent event cannot see — a payslip being
 * opened, a report being exported, a permission being changed by hand. It is a
 * single class rather than a second observer so there is exactly one place that
 * decides what gets redacted and one table that gets written to. A second audit
 * log is worse than none: the two disagree and neither can be trusted.
 */
class Audit
{
    /**
     * Never written to the log: secrets, and churn that would bury the signal.
     *
     * `tax_id` and `vat_number` are here not because they are secret but because
     * a log that copies them has quietly created a second, less-guarded place
     * the business's tax identity can be read from.
     */
    public const REDACTED = [
        'password', 'remember_token', 'token_hash', 'two_factor_secret',
        'two_factor_recovery_codes', 'content_hash', 'tax_id', 'vat_number',
    ];

    public const IGNORED = ['updated_at', 'created_at', 'synced_at'];

    /**
     * How long the same person reading the same record stays one entry.
     *
     * The number that decides whether read logging is a control or a denial of
     * service against your own database. A payroll clerk working through
     * thirty payslips, refreshing and going back, would otherwise write
     * hundreds of rows an hour and bury every write the trail exists to keep.
     * Fifteen minutes says "Awa looked at Marie's pay this afternoon", which is
     * the fact anyone actually needs; it does not say she looked four times,
     * which nobody has ever asked in a dispute.
     */
    public const READ_WINDOW_MINUTES = 15;

    /** The attribute names a record's human label is looked for in, in order. */
    protected const LABEL_KEYS = ['name', 'number', 'title', 'reference', 'label', 'subject', 'email', 'slug'];

    /**
     * Write one entry.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function record(
        ?Model $subject,
        string $event,
        array $properties = [],
        ?string $label = null,
        ?string $companyId = null,
    ): ?ActivityLog {
        $request = request();

        /*
         * Deliberately auth('web')->id(), not the bare auth()->id(): a platform
         * admin acting on the 'admin' guard has no 'web' identity, and user_id
         * must never resolve to whichever guard happens to be "default" for the
         * request — that either FK-violates against a platform_admins id that
         * isn't a users row, or, worse, silently misattributes the change to an
         * unrelated business session open in the same browser.
         */
        $webUserId = auth('web')->id();

        if ($webUserId === null && auth('admin')->check()) {
            $properties['platform_admin'] = auth('admin')->user()->email;
        }

        return ActivityLog::create([
            // The subject's own company where it has one, falling back to the
            // acting company — company creation itself has no current company yet.
            'company_id' => $companyId ?? $subject?->getAttribute('company_id') ?? app(CurrentCompany::class)->id(),
            'user_id' => $webUserId,
            'event' => $event,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject ? (string) $subject->getKey() : null,
            'subject_label' => $label ?? ($subject ? static::label($subject) : null),
            'properties' => $properties ?: null,
            'ip' => $request?->ip(),
            'user_agent' => substr((string) $request?->userAgent(), 0, 255) ?: null,
            'created_at' => now(),
        ]);
    }

    /**
     * Record that somebody READ something they should have to answer for.
     *
     * Deliberately not called from every show() in the app. The line drawn is:
     * a read is logged when the record is one named person's confidential
     * business (a payslip, a contract, a disciplinary review), when the
     * business has explicitly marked the document restricted, or when the read
     * takes data out of the system entirely. Everything else — invoices,
     * customers, stock, the lists that make up an ordinary working day — is not
     * logged, because a table where 99% of the rows say "someone looked at an
     * invoice" is a table nobody will ever search.
     *
     * Contents are never copied in. Logging the net pay alongside the fact that
     * the payslip was read would double the number of places a salary can be
     * found, which defeats the purpose of guarding it.
     */
    public static function accessed(Model $subject, string $reason, array $properties = []): ?ActivityLog
    {
        $actor = auth('web')->id() ?? 'admin:'.(auth('admin')->id() ?? 'anon');

        $key = sprintf('audit:read:%s:%s:%s', $actor, md5($subject::class), $subject->getKey());

        // add() is atomic: two tabs opening the same payslip in the same second
        // must not both win the race and write two rows.
        if (! Cache::add($key, true, now()->addMinutes(static::READ_WINDOW_MINUTES))) {
            return null;
        }

        return static::record($subject, 'accessed', ['reason' => $reason] + $properties);
    }

    /**
     * Record data leaving the system.
     *
     * Never de-duplicated, unlike a read. Two exports are two spreadsheets in
     * the world, on two laptops, and collapsing them into one entry would hide
     * the second copy — which is the one that matters.
     */
    public static function exported(string $what, int $rows, array $properties = []): ?ActivityLog
    {
        return static::record(null, 'exported', [
            'export' => $what,
            'rows' => $rows,
        ] + $properties, label: $what);
    }

    /**
     * A permission or role change, which Eloquent events do not see cleanly
     * because the grant lives in a pivot the acting model never loads.
     */
    public static function permissionChanged(Model $subject, string $summary, array $properties = []): ?ActivityLog
    {
        return static::record($subject, 'permission-changed', ['summary' => $summary] + $properties);
    }

    /** @param  array<string, mixed>  $attributes */
    public static function sanitise(array $attributes): array
    {
        return collect($attributes)
            ->except(static::IGNORED)
            ->map(fn ($value, string $key) => in_array($key, static::REDACTED, true) ? '[redacted]' : $value)
            ->all();
    }

    /**
     * What to call this record on screen.
     *
     * Read out of the raw attribute array rather than through `$model->name`.
     * Eloquent resolves an attribute before a same-named relation and will
     * happily run a query — or return a whole related model where a string was
     * expected — for a model whose `subject` or `title` is a relationship. The
     * attributes array cannot do either.
     */
    public static function label(Model $model): ?string
    {
        if (method_exists($model, 'auditLabel')) {
            return static::trim((string) $model->auditLabel());
        }

        $attributes = $model->getAttributes();

        foreach (self::LABEL_KEYS as $key) {
            if (filled($attributes[$key] ?? null) && is_scalar($attributes[$key])) {
                return static::trim((string) $attributes[$key]);
            }
        }

        // People are stored in halves everywhere in this system.
        if (filled($attributes['first_name'] ?? null) || filled($attributes['last_name'] ?? null)) {
            return static::trim(trim(($attributes['first_name'] ?? '').' '.($attributes['last_name'] ?? '')));
        }

        return null;
    }

    protected static function trim(string $value): ?string
    {
        return $value === '' ? null : mb_substr($value, 0, 180);
    }
}
