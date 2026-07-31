<?php

namespace App\Models;

use App\Contracts\TwoFactorAuthenticatable;
use App\Models\Concerns\HasTwoFactorAuthentication;
use App\Notifications\AdminResetPassword;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Platform staff. Authenticates on its own guard ('admin'), entirely
 * separate from the business `web` guard — see config/auth.php. A platform
 * admin session and a business session can even be open in the same browser
 * without either one leaking into the other.
 *
 * Soft-deleted rather than hard-deleted when revoked — see the
 * add_soft_deletes_to_platform_admins migration for why. A soft-deleted
 * admin also just... can't log in: the auth provider's lookup query
 * respects the SoftDeletingScope like any other query, no extra guard code
 * required.
 */
class PlatformAdmin extends Authenticatable implements TwoFactorAuthenticatable
{
    use HasTwoFactorAuthentication;
    use Notifiable;
    use SoftDeletes;

    /** Everything: managing other admins and changing plans, on top of ROLE_SUPPORT's access. */
    public const ROLE_ADMIN = 'admin';

    /** Full read access and routine actions (suspend/reactivate/member management), not plans or other admins. */
    public const ROLE_SUPPORT = 'support';

    public const ROLES = [self::ROLE_ADMIN, self::ROLE_SUPPORT];

    protected $fillable = ['name', 'email', 'password', 'role'];

    protected $hidden = ['password', 'remember_token'];

    // Mirrors the migration's DB-level default. Without this, a model built
    // via create()/new without an explicit role reads as null in memory
    // until the next fetch — the DB row is correct, but anything acting on
    // the same in-memory instance right after creation (tests reusing it
    // via actingAs(), for one) sees the wrong role until then.
    protected $attributes = ['role' => self::ROLE_ADMIN];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            // The password reset flow assigns a plain password expecting
            // this cast to hash it — without it, resetPassword() would
            // write a plaintext password straight into the column.
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function activity(): HasMany
    {
        return $this->hasMany(PlatformAdminActivity::class);
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new AdminResetPassword($token));
    }
}
