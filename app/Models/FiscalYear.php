<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A business's financial year.
 *
 * Not derived from the calendar: a financial year does not have to start in
 * January, and several of the businesses this serves close in June or
 * September. The dates are the business's own.
 */
class FiscalYear extends Model
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

    public function periods(): HasMany
    {
        return $this->hasMany(FiscalPeriod::class)->orderBy('starts_on');
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
