<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recipe: this product is made of those products, in these quantities.
 *
 * Both sides are ordinary catalogue items. The recipe holds no stock and no
 * cost of its own — quantities live in the movement ledger and costs in the
 * valuation, exactly as they do for everything bought instead of made.
 */
class BillOfMaterial extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    /** Model::create() does not read column defaults back off the database. */
    protected $attributes = [
        'output_quantity' => 1,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'output_quantity' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    /** The finished product this recipe makes. */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BillOfMaterialLine::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ProductionOrder::class);
    }

    /** What to call it when nobody named it: the product it makes. */
    public function label(): string
    {
        return $this->name ?: (string) $this->item?->name;
    }

    /**
     * What making $units finished units demands of each component, scrap
     * included. Keyed by component item id.
     *
     * @return array<string, float>
     */
    public function demandFor(float $units): array
    {
        $runs = $units / max((float) $this->output_quantity, 0.001);

        $demand = [];

        foreach ($this->lines as $line) {
            $demand[$line->item_id] = round(
                $runs * (float) $line->quantity * (1 + (float) $line->scrap_percent / 100),
                3
            );
        }

        return $demand;
    }
}
