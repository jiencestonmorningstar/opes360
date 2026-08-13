<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A record that a particular request has already been done.
 *
 * See the migration for why this exists. In short: on a mobile network a
 * client that loses the connection mid-payment cannot tell whether the money
 * moved, and both of its options — retry, or don't — are wrong without this.
 */
class IdempotencyKey extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'response' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    /** A stable fingerprint of what the caller sent. */
    public static function hashPayload(array $payload): string
    {
        ksort($payload);

        return hash('sha256', json_encode($payload));
    }
}
