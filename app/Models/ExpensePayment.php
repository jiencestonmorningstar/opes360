<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A settlement against an expense.
 *
 * Deliberately not the Payment model. That one records money coming in and is
 * allocated against receivables; merging the two would mean every question
 * about cash received had to remember to exclude cash paid, which is the kind
 * of thing that is remembered nine times out of ten.
 */
class ExpensePayment extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'paid_on' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function methodLabel(): string
    {
        return Expense::METHODS[$this->method] ?? ucfirst((string) $this->method);
    }

    /** Cash and mobile money hit the till; everything else hits the bank. */
    public function isCashLike(): bool
    {
        return in_array($this->method, ['cash', 'mobile_money'], true);
    }
}
