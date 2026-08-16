<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of what a supplier says passed between us.
 *
 * Never edited to agree with our books, and never used to edit them. Matching
 * records that a statement line and one of our bills describe the same event.
 */
class SupplierStatementLine extends Model
{
    use BelongsToCompany;
    use HasUlids;

    public const STATUS_UNMATCHED = 'unmatched';

    public const STATUS_MATCHED = 'matched';

    public const STATUS_DISPUTED = 'disputed';

    public const STATUS_IGNORED = 'ignored';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'line_date' => 'date',
            'amount' => 'decimal:2',
            'matched_at' => 'datetime',
        ];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(SupplierStatement::class, 'supplier_statement_id');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /** A charge the supplier says we owe, as opposed to a payment they credited. */
    public function isCharge(): bool
    {
        return (float) $this->amount > 0;
    }

    public function absoluteAmount(): float
    {
        return abs(round((float) $this->amount, 2));
    }

    public function isMatched(): bool
    {
        return $this->status === self::STATUS_MATCHED;
    }

    /**
     * Two lines are the same line when they agree on date, amount, reference
     * and description. Used to make a re-import of an overlapping period
     * harmless, which it has to be: suppliers send statements that restate the
     * whole account every month, not just what changed.
     */
    public function fingerprint(): string
    {
        return sha1(implode('|', [
            $this->supplier_statement_id,
            $this->line_date?->toDateString(),
            number_format((float) $this->amount, 2, '.', ''),
            mb_strtolower(trim((string) $this->reference)),
            mb_strtolower(trim((string) $this->description)),
        ]));
    }
}
