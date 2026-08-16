<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A supplier this RFQ was actually sent to.
 *
 * Kept as a row rather than inferred from "whoever quoted", because the
 * suppliers who did *not* answer are the interesting ones: three invitations
 * and one reply is a sourcing problem, and it is invisible if only replies
 * are stored.
 */
class RfqSupplier extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'responded_at' => 'datetime',
            'declined_at' => 'datetime',
        ];
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }
}
