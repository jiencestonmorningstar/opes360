<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * Somewhere the business keeps stock: a shop, a warehouse, a delivery van.
 *
 * Stock at a location is the sum of the movements attributed to it, never a
 * stored total — the same append-only rule the movement ledger already works
 * by, and the reason two offline devices can both sell the last unit and be
 * reconciled afterwards instead of overwriting each other.
 */
class StockLocation extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    public const KINDS = [
        'shop' => 'Shop',
        'warehouse' => 'Warehouse',
        'vehicle' => 'Vehicle',
        'other' => 'Other',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function transfersOut(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'from_location_id');
    }

    public function transfersIn(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'to_location_id');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? ucfirst((string) $this->kind);
    }

    public function label(): string
    {
        return $this->code ? $this->name.' ('.$this->code.')' : $this->name;
    }

    /** How much of one item is here. */
    public function stockOf(Item $item): float
    {
        return (float) $this->movements()->where('item_id', $item->id)->sum('quantity');
    }

    /**
     * Every item with a non-zero balance here.
     *
     * @return Collection<int, object>
     */
    public function stockOnHand()
    {
        return StockMovement::query()
            ->where('stock_location_id', $this->id)
            ->selectRaw('item_id, SUM(quantity) AS quantity')
            ->groupBy('item_id')
            ->havingRaw('SUM(quantity) <> 0')
            ->get();
    }
}
