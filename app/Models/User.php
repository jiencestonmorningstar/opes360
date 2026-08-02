<?php

namespace App\Models;

use App\Contracts\TwoFactorAuthenticatable;
use App\Models\Concerns\HasTwoFactorAuthentication;
use App\Notifications\ResetPassword;
use App\Notifications\VerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable implements TwoFactorAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasTwoFactorAuthentication;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar_path',
        'phone',
        'current_company_id',
        'theme',
        'locale',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Per-request memos for the permission lookups.
     *
     * Not persisted and not serialised — they answer questions about rows in
     * other tables, and a stale copy carried into a queued job would be a
     * permission decision made from history. See roleIn().
     *
     * @var array<string, ?Role>
     */
    protected array $roleCache = [];

    /** @var array<string, bool> "companyId|ability" => allowed */
    protected array $permissionCache = [];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /*
     * Both account emails are branded and queued rather than Laravel's stock
     * synchronous ones — see the notification classes. Overridden here because
     * that is the only hook Laravel offers for either.
     */

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPassword($token));
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmail);
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)
            ->withPivot(['role_id', 'job_title', 'status', 'joined_at'])
            ->withTimestamps();
    }

    public function currentCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'current_company_id');
    }

    /**
     * The user's role within a given company (roles are per-company, not global).
     *
     * Only an *active* membership carries a role. Every other place that reads
     * the pivot — the business switcher, notification recipients, the fallback
     * in SetCurrentCompany — already filters this way; leaving it out here
     * meant a membership marked inactive still resolved its old role, and with
     * it every permission that role grants.
     */
    /**
     * This user's role in a company, resolved once per request.
     *
     * The memo is the whole point. Every `@can` in a template, every navigation
     * entry and every route middleware asks the gate, the gate asks
     * hasPermissionIn, and that asked this — so a page with forty ability
     * checks ran forty membership joins, forty role lookups and forty override
     * queries for an answer that cannot change while the page is being built.
     * On the dashboard that was over a hundred of the hundred and thirty-seven
     * queries the page made.
     *
     * Scoped to the model instance, which is scoped to the request: the
     * authenticated user is one object for the life of a request, and a role
     * changed by somebody else is picked up on the next one. `forgetRoleCache`
     * exists for the one case that changes a role and then keeps going.
     */
    public function roleIn(Company $company): ?Role
    {
        if (array_key_exists($company->id, $this->roleCache)) {
            return $this->roleCache[$company->id];
        }

        $pivot = $this->activeMemberships()
            ->where('companies.id', $company->id)
            ->first()?->pivot;

        return $this->roleCache[$company->id] = $pivot ? Role::find($pivot->role_id) : null;
    }

    /** Drop the memo after something changes what it answered. */
    public function forgetRoleCache(): void
    {
        $this->roleCache = [];
        $this->permissionCache = [];
    }

    /**
     * Re-reading the row re-reads the permissions with it.
     *
     * `refresh()` is the idiomatic "this model may be out of date" signal, and
     * a memo that survived it would go on answering from before a membership
     * was suspended — which is the one direction a permission cache must never
     * be wrong in.
     */
    public function refresh()
    {
        $this->forgetRoleCache();

        return parent::refresh();
    }

    public function belongsToCompany(Company $company): bool
    {
        return $this->activeMemberships()->where('companies.id', $company->id)->exists();
    }

    /** Memberships that actually grant access, as opposed to merely existing. */
    public function activeMemberships(): BelongsToMany
    {
        return $this->companies()->wherePivot('status', 'active');
    }

    /**
     * Resolved permission for a company: the role's grants, with any per-user
     * override applied on top. An explicit revoke beats a role grant.
     */
    public function hasPermissionIn(Company $company, string $permission): bool
    {
        $key = $company->id.'|'.$permission;

        if (array_key_exists($key, $this->permissionCache)) {
            return $this->permissionCache[$key];
        }

        return $this->permissionCache[$key] = $this->resolvePermissionIn($company, $permission);
    }

    protected function resolvePermissionIn(Company $company, string $permission): bool
    {
        $role = $this->roleIn($company);

        /*
         * The Owner is the account's ultimate authority within their plan and
         * is never locked out of their own business — including from the
         * screens that would let them undo a permission mistake, so this beats
         * an explicit revoke.
         *
         * It lives here rather than as a `true` short-circuit in Gate::before
         * because that form answered *every* check, policies included. It
         * therefore skipped CompanyScopedPolicy::owns() — the cross-tenant
         * second line of defence — and the state guards built on top of it,
         * like "an issued document is frozen". Granting the permission and
         * skipping the policy are not the same thing, and only the first one
         * was ever intended.
         */
        if ($role?->slug === Role::OWNER) {
            return true;
        }

        $override = CompanyUserPermission::query()
            ->where('company_id', $company->id)
            ->where('user_id', $this->id)
            ->whereHas('permission', fn ($q) => $q->where('slug', $permission))
            ->first();

        if ($override !== null) {
            return (bool) $override->granted;
        }

        return (bool) $role?->hasPermission($permission);
    }

    public function firstName(): string
    {
        return explode(' ', trim($this->name))[0] ?? $this->name;
    }

    public function initials(): string
    {
        $words = preg_split('/\s+/', trim($this->name)) ?: [];

        return strtoupper(collect($words)->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode(''));
    }

    public function avatarUrl(): ?string
    {
        if (blank($this->avatar_path)) {
            return null;
        }

        // Already an absolute URL (seeded demo data, or an external identity provider).
        if (str_starts_with($this->avatar_path, 'http')) {
            return $this->avatar_path;
        }

        return Storage::disk('public')->url($this->avatar_path);
    }
}
