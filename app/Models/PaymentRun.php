<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A batch of supplier payments, decided together.
 *
 * Bills are paid in runs rather than one at a time because the constraint is
 * shared: there is one bank balance and the whole batch comes out of it. Paying
 * bills individually as they catch somebody's eye is how a business pays a
 * small supplier on Tuesday and then cannot make payroll on Friday.
 */
class PaymentRun extends Model
{
    use BelongsToCompany;
    use HasUlids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var array<string, string> */
    public const STATUSES = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_EXECUTED => 'Executed',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'date',
            'cash_available' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PaymentRunItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_DRAFT, self::STATUS_APPROVED]);
    }

    /** What the run proposes to spend, ignoring lines somebody has struck out. */
    public function total(): float
    {
        return round((float) $this->items
            ->where('status', '!=', PaymentRunItem::STATUS_SKIPPED)
            ->sum(fn (PaymentRunItem $item) => (float) $item->amount), 2);
    }

    /** What is left of the run's budget after what it proposes to spend. */
    public function headroom(): float
    {
        return round((float) $this->cash_available - $this->total(), 2);
    }

    /**
     * A run may exceed the cash it was built against — somebody can add a line
     * by hand. That is allowed, and surfaced, rather than blocked: the business
     * may know about money the forecast does not.
     */
    public function isOverCash(): bool
    {
        return $this->headroom() < -0.005;
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_APPROVED], true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }
}
