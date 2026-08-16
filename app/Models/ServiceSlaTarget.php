<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One promise: at this priority, answer within so long and fix within so long.
 *
 * Either figure may be null — plenty of businesses promise a response time and
 * nothing about resolution, and inventing a resolution target for them would
 * put breaches on the board that were never agreed with anybody.
 */
class ServiceSlaTarget extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'response_minutes' => 'integer',
            'resolution_minutes' => 'integer',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(ServiceSlaPolicy::class, 'sla_policy_id');
    }
}
