<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\EmitsDomainEvents;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What a supplier came back with.
 *
 * Not a `Document`: a Document is something this business issued and is
 * answerable for, and issued documents are immutable and numbered by us. This
 * is somebody else's paper, transcribed — it keeps *their* reference, and it
 * never enters our numbering sequence.
 */
class SupplierQuotation extends Model
{
    use BelongsToCompany;
    use EmitsDomainEvents;
    use HasUlids;
    use SoftDeletes;

    public const STATUSES = [
        'received' => 'Received',
        'shortlisted' => 'Shortlisted',
        'awarded' => 'Awarded',
        'rejected' => 'Not chosen',
        'withdrawn' => 'Withdrawn',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quoted_on' => 'date',
            'valid_until' => 'date',
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierQuotationLine::class)->orderBy('sort_order');
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }

    /** Past its own expiry date; ordering against it is a price nobody agreed. */
    public function hasLapsed(): bool
    {
        return $this->valid_until !== null && $this->valid_until->isPast();
    }

    /** Totals recomputed from the lines, never accumulated. */
    public function recompute(): void
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();

        $net = round((float) $lines->sum(fn (SupplierQuotationLine $line) => (float) $line->line_total), 2);
        $tax = round((float) $lines->sum(fn (SupplierQuotationLine $line) => (float) $line->tax_amount), 2);

        $this->subtotal = $net;
        $this->tax_total = $tax;
        $this->total = round($net + $tax, 2);
    }
}
