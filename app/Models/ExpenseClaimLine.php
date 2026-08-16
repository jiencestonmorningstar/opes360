<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\Accounting\ChartOfAccounts;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One receipt on a claim, and the budget it comes out of.
 *
 * The cost centre lives here rather than on the claim because a single trip
 * routinely spends against more than one budget.
 */
class ExpenseClaimLine extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'incurred_on' => 'date',
            'amount' => 'decimal:2',
            'vat_rate' => 'decimal:4',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(ExpenseClaim::class, 'expense_claim_id');
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    public function categoryLabel(): string
    {
        return ChartOfAccounts::EXPENSE_CATEGORIES[$this->category][2] ?? ucfirst((string) $this->category);
    }

    public function recompute(): void
    {
        $amount = round((float) $this->amount, 2);
        $vat = round($amount * (float) $this->vat_rate, 2);

        $this->vat_amount = $vat;
        $this->total = round($amount + $vat, 2);
    }
}
