<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One month, quarter or whatever span a business closes its books over.
 *
 * A closed period refuses new postings — see Ledger::post(), which is the
 * single path everything in the product reaches the books through, and so
 * the single place this needs enforcing.
 */
class FiscalPeriod extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function covers(\DateTimeInterface $date): bool
    {
        return $date >= $this->starts_on->startOfDay() && $date <= $this->ends_on->endOfDay();
    }
}
