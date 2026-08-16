<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One priced line of a supplier's answer. */
class SupplierQuotationLine extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(SupplierQuotation::class, 'supplier_quotation_id');
    }

    public function rfqLine(): BelongsTo
    {
        return $this->belongsTo(RfqLine::class, 'rfq_line_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function recompute(): void
    {
        $this->line_total = round((float) $this->quantity * (float) $this->unit_price, 2);
    }
}
