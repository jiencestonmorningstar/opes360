<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rent review: what the rent was, what it became, from when, and why.
 *
 * The row is history, never machinery — the tenancy's `rent` and the
 * recurring schedule's line carry the current figure; this table is why the
 * old figure keeps existing. Written only by Tenancies::reviewRent().
 */
class TenancyRentChange extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rent_before' => 'decimal:2',
            'rent_after' => 'decimal:2',
            'effective_on' => 'date',
        ];
    }

    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
