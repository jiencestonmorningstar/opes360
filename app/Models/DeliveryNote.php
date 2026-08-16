<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The paper that travelled with the goods.
 *
 * A record of a physical fact, so it is born issued — there is no draft
 * delivery. The stock movements written alongside it point back here, and
 * its QR resolves through the same public verification page as an invoice's.
 */
class DeliveryNote extends Model
{
    use BelongsToCompany;
    use HasUlids;

    public const STATUS_ISSUED = 'issued';

    public const STATUS_VOID = 'void';

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => self::STATUS_ISSUED,
    ];

    protected function casts(): array
    {
        return [
            'delivered_on' => 'date',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryNoteLine::class)->orderBy('sort_order');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id');
    }

    public function verificationToken(): BelongsTo
    {
        return $this->belongsTo(VerificationToken::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * What the printed page must say about itself — the same doctrine as
     * App\Support\Watermarks::statusMark(): a voided note that prints clean
     * is a live paper again. There is no DRAFT case because a delivery note
     * has no draft state, and $isCopy follows the share-path rule.
     */
    public function statusMark(bool $isCopy = false): ?string
    {
        return match (true) {
            $this->status === self::STATUS_VOID => 'VOID',
            $isCopy => 'COPY',
            default => null,
        };
    }
}
