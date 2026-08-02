<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A partner's share of one subscription payment made by a business they enrolled.
 *
 * Written only after a payment actually settles, never against an invoice that
 * might not be paid, and carrying the rate it was struck at so a later change
 * to the programme cannot rewrite history.
 */
class PartnerCommission extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
        ];
    }

    /** The business whose payment produced this commission. */
    public function sourceCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'source_company_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPayment::class, 'subscription_payment_id');
    }

    public function isEarned(): bool
    {
        return $this->status === 'earned';
    }
}
