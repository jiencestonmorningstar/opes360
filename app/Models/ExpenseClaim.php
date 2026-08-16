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
 * What a member of staff paid for themselves and wants back.
 *
 * Approval is not implemented here. The model is `Approvable` and the shared
 * workflow engine answers "has this been agreed to" — a second approval
 * mechanism living on this model is precisely the failure the master brief
 * names, and the reason `status` below is a cache of the engine's answer
 * rather than a rival source of it.
 */
class ExpenseClaim extends Model
{
    use Approvable;
    use BelongsToCompany;
    use EmitsDomainEvents;
    use HasUlids;
    use SoftDeletes;

    public const STATUSES = [
        'draft' => 'Draft',
        'submitted' => 'Awaiting approval',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'reimbursed' => 'Reimbursed',
    ];

    public const METHODS = [
        'cash' => 'Cash',
        'mobile_money' => 'Mobile Money',
        'bank' => 'Bank transfer',
        'cheque' => 'Cheque',
    ];

    protected $guarded = ['id'];

    /**
     * What an automation rule may set here.
     *
     * Notes and title only. Never `status`, `total` or `amount_reimbursed`: a
     * rule able to write those would be a way to approve and settle a claim
     * from a settings screen, without an approver ever seeing it.
     *
     * @return array<int, string>
     */
    public function automatableFields(): array
    {
        return ['notes', 'title'];
    }

    protected function casts(): array
    {
        return [
            'claim_date' => 'date',
            'subtotal' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_reimbursed' => 'decimal:2',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'reimbursed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ExpenseClaimLine::class)->orderBy('sort_order');
    }

    public function reimbursements(): HasMany
    {
        return $this->hasMany(ExpenseClaimReimbursement::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Still owed to the employee. */
    public function balance(): float
    {
        return round((float) $this->total - (float) $this->amount_reimbursed, 2);
    }

    public function isSettled(): bool
    {
        return $this->balance() <= 0.005;
    }

    /** Editable and resubmittable; anything past submission is not. */
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Totals recomputed from the lines, never accumulated.
     *
     * A running total updated on each edit drifts the first time a line is
     * removed inside a failed transaction, and the drift is invisible until
     * somebody reconciles the reimbursement against the receipts.
     */
    public function recompute(): void
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();

        $net = round((float) $lines->sum(fn (ExpenseClaimLine $line) => (float) $line->amount), 2);
        $vat = round((float) $lines->sum(fn (ExpenseClaimLine $line) => (float) $line->vat_amount), 2);

        $this->subtotal = $net;
        $this->vat_amount = $vat;
        $this->total = round($net + $vat, 2);
    }
}
