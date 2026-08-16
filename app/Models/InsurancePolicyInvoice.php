<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One link between a policy and the sales invoice collecting its premium.
 *
 * A link, never a copy: the invoice is an ordinary `documents` row owned by
 * Sales, and everything about the money — issue, dunning, receipt, aging —
 * happens there. A table rather than a column on the policy because a policy
 * billed by instalments has several invoices.
 */
class InsurancePolicyInvoice extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(InsurancePolicy::class, 'insurance_policy_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
