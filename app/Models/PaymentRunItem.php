<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bill inside a payment run, and what the run proposes to pay on it.
 *
 * The amount is held here rather than read off the bill: when cash is short the
 * answer is often "half of it now", and a run that could only pay balances in
 * full would send people back to paying bills one at a time outside the run.
 */
class PaymentRunItem extends Model
{
    use BelongsToCompany;
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_SKIPPED = 'skipped';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PaymentRun::class, 'payment_run_id');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(ExpensePayment::class, 'expense_payment_id');
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }
}
