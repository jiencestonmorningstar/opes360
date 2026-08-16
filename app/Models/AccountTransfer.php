<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Money moved between the business's own accounts.
 *
 * Its own record rather than two loose journal entries, because the pairing
 * has to survive: "why is there less in the bank on the 3rd" is answerable
 * only if the withdrawal and the deposit are visibly one act.
 */
class AccountTransfer extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'transferred_on' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'to_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The ledger entry this transfer produced, found through the source morph. */
    public function journalEntries(): MorphMany
    {
        return $this->morphMany(JournalEntry::class, 'source');
    }
}
