<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product bought from one supplier: how long they take and what they
 * charged last.
 *
 * Suppliers are contacts — the same list procurement invites to RFQs, not a
 * second one. This row adds only what replenishment needs to plan with: the
 * lead time and a price good enough to cost a draft requisition.
 */
class ItemSupplier extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    /**
     * Stated as well as in the schema: create() does not read column defaults
     * back, and a link made in code would otherwise plan with a null lead
     * time.
     */
    protected $attributes = [
        'lead_days' => 7,
        'is_preferred' => false,
    ];

    protected function casts(): array
    {
        return [
            'lead_days' => 'integer',
            'last_price' => 'decimal:2',
            'is_preferred' => 'boolean',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }
}
