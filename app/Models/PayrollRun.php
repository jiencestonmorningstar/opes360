<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One month's payroll.
 *
 * Drafted, checked, approved, paid — in that order, and each step is a door
 * that only opens forwards. A draft can be rebuilt from the employee records
 * as many times as it takes; once approved it is posted to the books and
 * frozen, because from that moment the CNPS declaration and the employees'
 * copies exist outside this database.
 */
class PayrollRun extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    public const STATUSES = [
        'draft' => 'Draft',
        'approved' => 'Approved',
        'paid' => 'Paid',
        'void' => 'Void',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period' => 'date',
            'pay_date' => 'date',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'rates' => 'array',
            'gross' => 'decimal:2',
            'employee_deductions' => 'decimal:2',
            'net' => 'decimal:2',
            'employer_charges' => 'decimal:2',
        ];
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }

    /** A draft is the only state that may be rebuilt or deleted. */
    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    public function periodLabel(): string
    {
        return $this->period->translatedFormat('F Y');
    }

    /**
     * What the business actually pays out, all in: the net to the staff plus
     * everything withheld on their behalf plus the employer's own charges.
     * The gross alone understates the bill by roughly a fifth.
     */
    public function totalCost(): float
    {
        return round((float) $this->gross + (float) $this->employer_charges, 2);
    }
}
