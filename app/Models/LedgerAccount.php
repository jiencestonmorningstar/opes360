<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One account in a company's SYSCOHADA chart.
 */
class LedgerAccount extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /** The SYSCOHADA class, which is simply the leading digit. */
    public static function classOf(string $number): int
    {
        return (int) substr($number, 0, 1);
    }

    /**
     * Which side increases this account, from its class.
     *
     * Classes 2 (immobilisations), 3 (stocks), 5 (trésorerie) and 6 (charges)
     * are debit-normal; 1 (ressources durables), 4 (tiers) and 7 (produits)
     * are credit-normal. Class 4 is the awkward one — it holds both what is
     * owed to the business and what the business owes — so it is stored per
     * account rather than inferred at read time.
     */
    public static function normalBalanceFor(int $class): string
    {
        return in_array($class, [2, 3, 5, 6], true) ? 'debit' : 'credit';
    }

    public function isDebitNormal(): bool
    {
        return $this->normal_balance === 'debit';
    }

    /** Debit less credit, signed so it reads positive in the account's own direction. */
    public function balance(): float
    {
        $debit = (float) $this->lines()->sum('debit');
        $credit = (float) $this->lines()->sum('credit');

        return $this->isDebitNormal() ? $debit - $credit : $credit - $debit;
    }

    public function label(): string
    {
        return $this->number.' — '.$this->name;
    }
}
