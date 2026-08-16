<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One thing being asked about, kept pointed at the requisition line it came from. */
class RfqLine extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    public function requisitionLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisitionLine::class, 'purchase_requisition_line_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
