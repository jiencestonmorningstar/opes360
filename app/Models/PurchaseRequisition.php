<?php

namespace App\Models;

use App\Models\Concerns\Approvable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\EmitsDomainEvents;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Somebody asking the business to buy something.
 *
 * The step before a purchase order, and the reason one exists: an order
 * commits money, so somebody other than the person who wants the thing has to
 * agree first. That agreement is not implemented here. The model is
 * `Approvable`, the shared workflow engine answers "has this been agreed to",
 * and `status` below is a cache of that answer rather than a rival source of
 * it — a second approval mechanism is the failure the master brief names.
 */
class PurchaseRequisition extends Model
{
    use Approvable;
    use BelongsToCompany;
    use EmitsDomainEvents;
    use HasUlids;
    use SoftDeletes;

    public const STATUSES = [
        'draft' => 'Draft',
        'submitted' => 'Awaiting approval',
        'returned' => 'Changes requested',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'sourcing' => 'Out to suppliers',
        'ordered' => 'Ordered',
        'cancelled' => 'Cancelled',
    ];

    protected $guarded = ['id'];

    /**
     * What an automation rule may set here.
     *
     * Never `status` or `estimated_total`: a rule able to write those would be
     * a way to approve spending from a settings screen without an approver
     * ever seeing the request.
     *
     * @return array<int, string>
     */
    public function automatableFields(): array
    {
        return ['notes', 'title', 'justification'];
    }

    protected function casts(): array
    {
        return [
            'needed_by' => 'date',
            'estimated_total' => 'decimal:2',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'ordered_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseRequisitionLine::class)->orderBy('sort_order');
    }

    public function rfqs(): HasMany
    {
        return $this->hasMany(Rfq::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class, 'cost_centre_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'purchase_order_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Editable and submittable; anything past submission is not. */
    public function isOpen(): bool
    {
        return in_array($this->status, ['draft', 'returned'], true);
    }

    /**
     * May this become an RFQ or a purchase order?
     *
     * The engine's verdict, not the cached status, because the two can differ
     * for exactly as long as it takes the listener to run — and the gap is
     * precisely where an unapproved requisition would slip through into a
     * committed order.
     */
    public function isSourceable(): bool
    {
        return $this->isApproved() && $this->purchase_order_id === null;
    }

    /**
     * Totals recomputed from the lines, never accumulated.
     *
     * A running total drifts the first time a line is removed inside a failed
     * transaction, and the drift is invisible until the approval threshold it
     * feeds picks the wrong approver.
     */
    public function recompute(): void
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();

        $this->estimated_total = round(
            (float) $lines->sum(fn (PurchaseRequisitionLine $line) => (float) $line->estimated_total),
            2
        );
    }
}
