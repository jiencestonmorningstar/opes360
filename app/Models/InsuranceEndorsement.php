<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A mid-term change to cover, and what it did to the premium.
 *
 * The row is the record of the change — what, when, and the signed premium
 * delta. The money itself is an ordinary sales document: a debit note when
 * the client owes more, a credit note when premium comes back, linked through
 * document_id and aged, dunned and receipted where every other document is.
 */
class InsuranceEndorsement extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'effective_on' => 'date',
            'previous_premium' => 'decimal:2',
            'new_premium' => 'decimal:2',
            'premium_delta' => 'decimal:2',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(InsurancePolicy::class, 'insurance_policy_id');
    }

    /** The debit or credit note adjusting the premium, once drafted. */
    public function adjustmentDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function movesMoney(): bool
    {
        return abs((float) $this->premium_delta) > 0.005;
    }
}
