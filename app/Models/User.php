<?php

namespace App\Models;

use App\Notifications\ResetPassword;
use App\Notifications\VerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

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

    public function ownedCompanies(): BelongsToMany
    {
        return $this->companies()->wherePivot('status', 'active');
    }

    /** The user's role within a given company (roles are per-company, not global). */
    public function roleIn(Company $company): ?Role
    {
        $pivot = $this->companies()
            ->where('companies.id', $company->id)
            ->first()?->pivot;

        return $pivot ? Role::find($pivot->role_id) : null;
    }

    public function belongsToCompany(Company $company): bool
    {
        return $this->companies()->where('companies.id', $company->id)->exists();
    }

    /**
     * Resolved permission for a company: the role's grants, with any per-user
     * override applied on top. An explicit revoke beats a role grant.
     */
    public function hasPermissionIn(Company $company, string $permission): bool
    {
        $override = CompanyUserPermission::query()
            ->where('company_id', $company->id)
            ->where('user_id', $this->id)
            ->whereHas('permission', fn ($q) => $q->where('slug', $permission))
            ->first();

        if ($override !== null) {
            return (bool) $override->granted;
        }

        return (bool) $this->roleIn($company)?->hasPermission($permission);
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null && filled($this->two_factor_secret);
    }

    /** Decrypts the TOTP secret, tolerating a key rotation rather than throwing. */
    public function twoFactorSecret(): ?string
    {
        if (blank($this->two_factor_secret)) {
            return null;
        }

        try {
            return Crypt::decryptString($this->two_factor_secret);
        } catch (DecryptException) {
            return null;
        }
    }

    /** @return array<int, string> */
    public function recoveryCodes(): array
    {
        if (blank($this->two_factor_recovery_codes)) {
            return [];
        }

        try {
            return json_decode(Crypt::decryptString($this->two_factor_recovery_codes), true) ?: [];
        } catch (DecryptException) {
            return [];
        }
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
