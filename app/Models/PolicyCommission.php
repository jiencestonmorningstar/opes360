<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Commission earned on a placed policy — a receivable, not a second ledger.
 *
 * The row says the insurer owes the broker this much and why. Collecting it
 * is an ordinary invoice to the insurer contact, linked through document_id,
 * which then ages, dunns and receipts like every other invoice. Whether it
 * has been *paid* is that document's business — asking this row would mean
 * two places answering, and one of them wrong.
 */
class PolicyCommission extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'earned_on' => 'date',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(InsurancePolicy::class, 'insurance_policy_id');
    }

    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'insurer_contact_id');
    }

    /** The invoice that bills it, once raised. */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function isInvoiced(): bool
    {
        return $this->document_id !== null;
    }
}
