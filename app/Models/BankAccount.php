<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One bank account, and the ledger account that mirrors it.
 *
 * Two accounts at two banks need two ledger accounts. Pointing both at the
 * `bank` role would make each reconciliation see the other's movements, which
 * is a reconciliation of neither.
 */
class BankAccount extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'statement_balance' => 'decimal:2',
            'statement_date' => 'date',
            'opening_balance' => 'decimal:2',
            'opened_on' => 'date',
            'is_default' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    public function statementLines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class);
    }

    public function label(): string
    {
        return $this->bank_name ? $this->name.' — '.$this->bank_name : $this->name;
    }

    /** The last four digits, which is all anyone needs to recognise it. */
    public function maskedNumber(): ?string
    {
        if (! $this->account_number) {
            return null;
        }

        return '…'.mb_substr($this->account_number, -4);
    }
}
