<?php

namespace App\Observers;

use App\Models\ActivityLog;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes the audit trail.
 *
 * Registered against the models whose history matters for money and identity.
 * Attribute-level diffs are recorded so "who changed this price, and from what"
 * is answerable, which a bare "updated" row cannot do.
 */
class AuditObserver
{
    /**
     * Never written to the log: secrets, and churn that would bury the signal.
     */
    protected const REDACTED = [
        'password', 'remember_token', 'token_hash', 'two_factor_secret',
        'two_factor_recovery_codes', 'content_hash', 'tax_id', 'vat_number',
    ];

    protected const IGNORED = ['updated_at', 'created_at', 'synced_at'];

    public function created(Model $model): void
    {
        $this->record($model, 'created', ['after' => $this->sanitise($model->getAttributes())]);
    }

    public function updated(Model $model): void
    {
        $changes = collect($model->getChanges())
            ->except(self::IGNORED)
            ->keys()
            ->all();

        if ($changes === []) {
            return;
        }

        $this->record($model, 'updated', [
            'before' => $this->sanitise(array_intersect_key($model->getOriginal(), array_flip($changes))),
            'after' => $this->sanitise(array_intersect_key($model->getAttributes(), array_flip($changes))),
        ]);
    }

    public function deleted(Model $model): void
    {
        $this->record($model, method_exists($model, 'trashed') && $model->trashed() ? 'trashed' : 'deleted');
    }

    /** @param array<string, mixed> $properties */
    protected function record(Model $model, string $event, array $properties = []): void
    {
        $request = request();

        // Deliberately auth('web')->id(), not the bare auth()->id(): a
        // platform admin acting on the 'admin' guard has no 'web' identity,
        // and user_id must never resolve to whichever guard happens to be
        // "default" for the request — that either FK-violates against a
        // platform_admins id that isn't a users row, or, worse, silently
        // misattributes the change to an unrelated business session open in
        // the same browser. PlatformAdminActivity is the real record of who
        // a platform admin action was; note it here too so this table
        // doesn't just go quiet on those rows.
        $webUserId = auth('web')->id();

        if ($webUserId === null && auth('admin')->check()) {
            $properties['platform_admin'] = auth('admin')->user()->email;
        }

        ActivityLog::create([
            // The model's own company where it has one, falling back to the
            // acting company — company creation itself has no current company yet.
            'company_id' => $model->getAttribute('company_id') ?? app(CurrentCompany::class)->id(),
            'user_id' => $webUserId,
            'event' => $event,
            'subject_type' => $model::class,
            'subject_id' => (string) $model->getKey(),
            'properties' => $properties ?: null,
            'ip' => $request?->ip(),
            'user_agent' => substr((string) $request?->userAgent(), 0, 255) ?: null,
            'created_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    protected function sanitise(array $attributes): array
    {
        return collect($attributes)
            ->except(self::IGNORED)
            ->map(fn ($value, string $key) => in_array($key, self::REDACTED, true) ? '[redacted]' : $value)
            ->all();
    }
}
