<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

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

    /**
     * $subject is typically a Company, but not always — inviting or revoking
     * another platform admin logs against that PlatformAdmin instead. The
     * subject_type/subject_id columns are generic on purpose.
     */
    public static function log(PlatformAdmin $admin, string $action, ?Model $subject = null, array $meta = []): void
    {
        static::create([
            'platform_admin_id' => $admin->id,
            'action' => $action,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id' => $subject?->getKey(),
            'meta' => $meta,
            // Captured here rather than passed in by every caller, so no
            // future admin action can accidentally ship without it.
            'ip_address' => Request::ip(),
            'user_agent' => Str::limit((string) Request::userAgent(), 512, ''),
        ]);
    }
}
