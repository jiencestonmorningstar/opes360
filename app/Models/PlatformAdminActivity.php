<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every write a platform admin makes against a business — suspend, activate,
 * change plan — is a row here. Admins have full read access to every
 * business's data (a deliberate choice for this platform's support model),
 * so the accountability has to live on the write side: what changed, who
 * changed it, and when.
 */
class PlatformAdminActivity extends Model
{
    protected $table = 'platform_admin_activity';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'platform_admin_id');
    }

    public static function log(PlatformAdmin $admin, string $action, ?Company $company = null, array $meta = []): void
    {
        static::create([
            'platform_admin_id' => $admin->id,
            'action' => $action,
            'subject_type' => $company ? Company::class : null,
            'subject_id' => $company?->id,
            'meta' => $meta,
        ]);
    }
}
