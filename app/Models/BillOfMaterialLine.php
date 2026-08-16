<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One component of a recipe: which product, and how much of it per run.
 *
 * No company_id of its own — it is reachable only through its recipe, the same
 * arrangement as a stocktake line.
 */
class BillOfMaterialLine extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $attributes = [
        'scrap_percent' => 0,
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'scrap_percent' => 'decimal:2',
        ];
    }

    public function billOfMaterial(): BelongsTo
    {
        return $this->belongsTo(BillOfMaterial::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
