<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The statement a supplier sent us, as they sent it.
 *
 * Held rather than derived. Everything else in payables is our own record of
 * what we owe; this is the counterparty's claim, and the only useful thing to
 * do with a claim is lay it beside our record and look at the difference. A
 * version edited to agree with our books can no longer show that difference,
 * which was the only reason to keep it.
 */
class SupplierStatement extends Model
{
    use BelongsToCompany;
    use HasUlids;

    public const STATUS_OPEN = 'open';

    public const STATUS_RECONCILED = 'reconciled';

    public const STATUS_DISPUTED = 'disputed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'statement_date' => 'date',
            'period_from' => 'date',
            'period_to' => 'date',
            'closing_balance' => 'decimal:2',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierStatementLine::class);
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    /**
     * What the supplier's own lines add up to.
     *
     * Kept separate from `closing_balance`, which is what they claim. When the
     * two disagree the supplier's arithmetic is wrong, and that is worth seeing
     * before anybody spends an afternoon hunting for a missing invoice.
     */
    public function lineTotal(): float
    {
        return round((float) $this->lines->sum(fn (SupplierStatementLine $l) => (float) $l->amount), 2);
    }

    public function selfConsistent(): bool
    {
        return abs($this->lineTotal() - (float) $this->closing_balance) < 1.0;
    }
}
