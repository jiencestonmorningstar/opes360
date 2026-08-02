<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\Accounting\ChartOfAccounts;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Money going out: a supplier bill or a direct expense.
 *
 * One shape for both, because the difference is a due date rather than a kind
 * of thing — a bill sits in payables until settled, a taxi fare is paid on the
 * spot, and a business wants one answer to "what did we spend last month".
 */
class Expense extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    public const METHODS = [
        'cash' => 'Cash',
        'mobile_money' => 'Mobile Money',
        'bank' => 'Bank transfer',
        'cheque' => 'Cheque',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'amount' => 'decimal:2',
            'vat_rate' => 'decimal:4',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ExpensePayment::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function categoryLabel(): string
    {
        return ChartOfAccounts::EXPENSE_CATEGORIES[$this->category][2] ?? ucfirst((string) $this->category);
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }

    public function balance(): float
    {
        return round((float) $this->total - (float) $this->amount_paid, 2);
    }

    public function isPaid(): bool
    {
        return $this->balance() <= 0.005;
    }

    /**
     * Overdue only means something for a bill with terms. A direct expense
     * with no due date was settled when it happened and is never chased.
     */
    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && ! $this->isPaid()
            && ! $this->isVoid()
            && $this->due_date->isPast();
    }

    /** The two totals the list and the reports both need, computed once. */
    public function recompute(): void
    {
        $amount = round((float) $this->amount, 2);
        $vat = round($amount * (float) $this->vat_rate, 2);

        $this->vat_amount = $vat;
        $this->total = round($amount + $vat, 2);
    }
}
