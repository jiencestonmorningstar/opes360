<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One balanced journal entry. Immutable once written — see the migration.
 */
class JournalEntry extends Model
{
    use BelongsToCompany;
    use HasUlids;

    /** The SYSCOHADA journals a small business actually keeps. */
    public const JOURNALS = [
        'VE' => 'Ventes',
        'AC' => 'Achats',
        'BQ' => 'Banque',
        'CA' => 'Caisse',
        'OD' => 'Opérations diverses',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['entry_date' => 'date'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('sort_order');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    public function totalDebit(): float
    {
        return (float) $this->lines()->sum('debit');
    }

    public function totalCredit(): float
    {
        return (float) $this->lines()->sum('credit');
    }

    public function isBalanced(): bool
    {
        return abs($this->totalDebit() - $this->totalCredit()) < 0.005;
    }

    public function journalName(): string
    {
        return self::JOURNALS[$this->journal] ?? $this->journal;
    }
}
