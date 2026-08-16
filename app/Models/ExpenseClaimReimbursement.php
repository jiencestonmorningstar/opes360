<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cash actually handed back to the employee.
 *
 * Separate rows rather than a single `paid_on` on the claim, because a
 * business short of cash pays a large claim in instalments and each one is a
 * distinct movement out of the till that the books have to see.
 */
class ExpenseClaimReimbursement extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'paid_on' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(ExpenseClaim::class, 'expense_claim_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** Cash and mobile money both leave the till, not the bank account. */
    public function isCashLike(): bool
    {
        return in_array($this->method, ['cash', 'mobile_money'], true);
    }

    public function methodLabel(): string
    {
        return ExpenseClaim::METHODS[$this->method] ?? ucfirst((string) $this->method);
    }
}
