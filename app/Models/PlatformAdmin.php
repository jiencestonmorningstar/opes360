<?php

namespace App\Models;

use App\Notifications\AdminResetPassword;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Platform staff. Authenticates on its own guard ('admin'), entirely
 * separate from the business `web` guard — see config/auth.php. A platform
 * admin session and a business session can even be open in the same browser
 * without either one leaking into the other.
 */
class PlatformAdmin extends Authenticatable
{
    use Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            // The password reset flow assigns a plain password expecting
            // this cast to hash it — without it, resetPassword() would
            // write a plaintext password straight into the column.
            'password' => 'hashed',
        ];
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
