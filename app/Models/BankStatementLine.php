<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line of what the bank says happened.
 *
 * Never edited to agree with the books, and never used to edit them. Matching
 * records that a statement line and a journal line describe the same event;
 * the moment the software starts "correcting" one to match the other it can no
 * longer tell you they disagreed, which was the only thing it was for.
 */
class BankStatementLine extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'value_date' => 'date',
            'amount' => 'decimal:2',
            'running_balance' => 'decimal:2',
            'matched_at' => 'datetime',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function matchedTo(): MorphTo
    {
        return $this->morphTo();
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function isMatched(): bool
    {
        return $this->status === 'matched';
    }

    public function isIgnored(): bool
    {
        return $this->status === 'ignored';
    }

    /** Money in, from the account holder's point of view. */
    public function isCredit(): bool
    {
        return (float) $this->amount > 0;
    }

    public function absoluteAmount(): float
    {
        return abs(round((float) $this->amount, 2));
    }

    /**
     * Two lines are the same line when they agree on the date, the amount and
     * the reference. Used to make a re-import of an overlapping statement
     * period harmless — which it has to be, because a bank's CSV export
     * usually cannot be asked for "everything since last time".
     */
    public function fingerprint(): string
    {
        return sha1(implode('|', [
            $this->bank_account_id,
            $this->value_date?->toDateString(),
            number_format((float) $this->amount, 2, '.', ''),
            mb_strtolower(trim((string) $this->reference)),
            mb_strtolower(trim((string) $this->description)),
        ]));
    }
}
