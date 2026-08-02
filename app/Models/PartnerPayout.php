<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A partner asking to be paid the balance they have built up.
 *
 * The money moves outside the app — mobile money, in practice — so this record
 * exists to hold the request, the amount as at the moment it was made, and the
 * decision. A requested payout already reduces the available balance, so a
 * partner cannot request the same money twice while the first is in flight.
 */
class PartnerPayout extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasUlids;

    public const STATUSES = ['requested', 'paid', 'rejected'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'settled_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isOpen(): bool
    {
        return $this->status === 'requested';
    }
}
