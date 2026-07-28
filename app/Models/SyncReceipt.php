<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
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

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
