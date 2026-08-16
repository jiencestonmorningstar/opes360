<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\EmitsDomainEvents;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer's order: the promise, before the paper.
 *
 * The order itself owns no stock and no money. Confirming it reserves stock
 * through the one reservations service; delivering writes ordinary stock
 * movements and a delivery note; invoicing creates an ordinary Document from
 * what was delivered. Everything the order "has" is a view over those
 * records, which is why cancelling one is nothing more than releasing its
 * reservations.
 */
class SalesOrder extends Model
{
    use BelongsToCompany;
    use EmitsDomainEvents;
    use HasUlids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_PICKING = 'picking';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_INVOICED = 'invoiced';

    public const STATUS_CANCELLED = 'cancelled';

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    protected function casts(): array
    {
        return [
            'promised_date' => 'date',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** The customer. A Contact like every other counterparty in the product. */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class)->orderBy('sort_order');
    }

    public function deliveryNotes(): HasMany
    {
        return $this->hasMany(DeliveryNote::class);
    }

    /** The ordinary Documents that bill this order — links, never copies. */
    public function invoices(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'sales_order_invoices')->withTimestamps();
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id');
    }

    /** The accepted quotation this order was converted from, when there was one. */
    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'source_document_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /** Confirmed and not yet fully delivered: stock may still be held for it. */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_CONFIRMED, self::STATUS_PICKING], true);
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /** The remainder nobody is allowed to hide. */
    public function backorderedTotal(): float
    {
        return round((float) $this->lines->sum('quantity_backordered'), 3);
    }

    public function total(): float
    {
        return round($this->lines->sum(
            fn (SalesOrderLine $line) => (float) $line->quantity_ordered * (float) $line->unit_price
        ), 2);
    }
}
