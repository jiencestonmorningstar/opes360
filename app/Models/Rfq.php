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
 * The business asking several suppliers what something would cost.
 *
 * Carries no prices of its own. An RFQ that quoted a figure would be telling
 * suppliers the answer, which defeats the point of asking three of them.
 */
class Rfq extends Model
{
    use BelongsToCompany;
    use EmitsDomainEvents;
    use HasUlids;
    use SoftDeletes;

    public const STATUSES = [
        'draft' => 'Draft',
        'sent' => 'Out to suppliers',
        'closed' => 'Closed',
        'awarded' => 'Awarded',
        'cancelled' => 'Cancelled',
    ];

    protected $table = 'rfqs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'closes_on' => 'date',
            'responded_at' => 'datetime',
            'awarded_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RfqLine::class)->orderBy('sort_order');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(RfqSupplier::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(SupplierQuotation::class);
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'purchase_order_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['draft', 'sent'], true);
    }

    public function hasInvited(Contact $supplier): bool
    {
        return $this->invitations()->where('supplier_id', $supplier->id)->exists();
    }
}
