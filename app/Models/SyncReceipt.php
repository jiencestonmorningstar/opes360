<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per push envelope the server has seen.
 *
 * The primary key is the client-generated envelope ULID rather than a
 * server-side id — that is what makes the replay check a single primary-key
 * lookup instead of a scan.
 */
class SyncReceipt extends Model
{
    use BelongsToCompany;
    use Prunable;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * Ninety days of replay protection.
     *
     * Comfortably longer than any realistic offline spell, and long enough that
     * the receipts still serve as an audit trail of what each device sent while
     * anyone is likely to ask. Unscoped on purpose: pruning runs from the
     * scheduler, which has no current company, and must sweep every tenant.
     */
    public function prunable(): Builder
    {
        return static::query()
            ->acrossAllCompanies()
            ->where('created_at', '<', now()->subDays(90));
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
