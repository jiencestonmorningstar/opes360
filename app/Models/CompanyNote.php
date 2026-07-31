<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A free-text support note on a business, written by a platform admin.
 * Append-only by design — no edit/delete, so the record of who said what
 * and when never gets rewritten.
 */
class CompanyNote extends Model
{
    use HasUlids;

    protected $fillable = ['company_id', 'platform_admin_id', 'body'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'platform_admin_id');
    }
}
