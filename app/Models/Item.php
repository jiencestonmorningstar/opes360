<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Item extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;
    use Syncable;

    /**
     * How closely this product is traced. Opt-in, and 'none' — the default and
     * what every existing product carries — is the whole of the behaviour that
     * existed before: no lot to type, no serial to scan, no expiry to answer.
     */
    public const TRACKING_NONE = 'none';

    public const TRACKING_BATCH = 'batch';

    public const TRACKING_SERIAL = 'serial';

    public const TRACKING_MODES = [
        self::TRACKING_NONE => 'No tracking',
        self::TRACKING_BATCH => 'Batch / lot number',
        self::TRACKING_SERIAL => 'Serial number',
    ];

    protected $guarded = ['id'];

    /**
     * Model::create() does not read column defaults back off the database, so
     * a product created without saying would report a null tracking mode to
     * the code that just made it. Stated here as well as in the schema.
     */
    protected $attributes = [
        'tracking_mode' => self::TRACKING_NONE,
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost' => 'decimal:2',
            'reorder_level' => 'decimal:3',
            'max_level' => 'decimal:3',
            'track_stock' => 'boolean',
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(StockBatch::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    /**
     * Who this can be bought from, and how long each of them takes.
     *
     * Named `supplierLinks` rather than `suppliers` deliberately: the rows are
     * ItemSupplier pivots carrying lead time and price, not bare contacts, and
     * a relation whose name promises contacts would be read wrongly the first
     * time somebody plucked `name` off it.
     */
    public function supplierLinks(): HasMany
    {
        return $this->hasMany(ItemSupplier::class);
    }

    /**
     * The link to buy from when nobody says otherwise: the one marked
     * preferred, else the quickest.
     */
    public function preferredSupplierLink(): ?ItemSupplier
    {
        return $this->supplierLinks
            ->sortBy([['is_preferred', 'desc'], ['lead_days', 'asc']])
            ->first();
    }

    /** Recipes that make this product out of other products. */
    public function billsOfMaterials(): HasMany
    {
        return $this->hasMany(BillOfMaterial::class);
    }

    /** Orders to make this product. */
    public function productionOrders(): HasMany
    {
        return $this->hasMany(ProductionOrder::class);
    }

    public function tracksBatches(): bool
    {
        return $this->tracking_mode === self::TRACKING_BATCH;
    }

    public function tracksSerials(): bool
    {
        return $this->tracking_mode === self::TRACKING_SERIAL;
    }

    /** Traced by lot or by unit — either way, arrivals need naming. */
    public function isTraceable(): bool
    {
        return $this->tracksBatches() || $this->tracksSerials();
    }

    public function scopeTraceable(Builder $query): Builder
    {
        return $query->whereIn('tracking_mode', [self::TRACKING_BATCH, self::TRACKING_SERIAL]);
    }

    public function scopeProducts(Builder $query): Builder
    {
        return $query->where('type', 'product');
    }

    public function scopeServices(Builder $query): Builder
    {
        return $query->where('type', 'service');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Stock is the sum of the append-only movement ledger, never a stored column.
     * If this becomes a hot query, add a periodically-refreshed snapshot table
     * rather than denormalising a mutable quantity back onto this model — that
     * would reintroduce the concurrency bug the ledger exists to avoid.
     */
    public function stockOnHand(): float
    {
        /*
         * A list is where this is most often called, and a list of items each
         * running its own SUM is the N+1 it is easiest to write by accident —
         * it was live on the products list and on the dashboard's low-stock
         * count. `withStock()` puts one grouped aggregate on the query and
         * this reads it, so the same call costs one query for the whole page
         * instead of one per row.
         */
        if (array_key_exists('stock_on_hand', $this->attributes)) {
            return round((float) $this->attributes['stock_on_hand'], 3);
        }

        return (float) $this->movements()->sum('quantity');
    }

    /**
     * Load each item's stock on hand alongside it, in one aggregate.
     *
     * Still a sum of the movement ledger, not a stored column — the whole
     * point of the ledger is that two offline devices can both sell the last
     * unit and be reconciled, which a mutable quantity would undo.
     */
    public function scopeWithStock(Builder $query): Builder
    {
        return $query->withSum('movements as stock_on_hand', 'quantity');
    }

    public function isLowStock(): bool
    {
        if (! $this->track_stock || $this->reorder_level === null) {
            return false;
        }

        return $this->stockOnHand() <= (float) $this->reorder_level;
    }
}
