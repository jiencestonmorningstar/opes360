<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One card or letterhead a partner generated, and the fee it was charged at.
 *
 * A billing record, so it is written once and not edited: a mistake is voided,
 * which leaves the original row in place with a reason attached. A partner's
 * statement for a past month has to keep saying what it said at the time.
 */
class CardIssuance extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasUlids;

    public const ASSETS = ['card', 'letterhead'];

    protected $guarded = ['id'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(PartnerClient::class, 'partner_client_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function isBilled(): bool
    {
        return $this->status === 'billed';
    }

    public function void(string $reason): void
    {
        $this->forceFill(['status' => 'void', 'void_reason' => $reason])->save();
    }
}
