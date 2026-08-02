<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a payslip.
 *
 * Kept rather than rendered from the payslip's columns, because a payslip has
 * to name a "Prime de transport" of 25 000 F that no column knows about, and
 * an employee is entitled to see every figure that produced their net — the
 * base it was computed on and the rate applied to it, not just the result.
 */
class PayslipLine extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasUlids;

    public const KINDS = [
        'earning' => 'Earnings',
        'deduction' => 'Deductions',
        'employer' => 'Employer charges',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'base' => 'decimal:2',
            'rate' => 'decimal:5',
            'amount' => 'decimal:2',
        ];
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    /** "4.2%" for a rate line, blank for a flat amount. */
    public function rateLabel(): string
    {
        return $this->rate === null ? '' : rtrim(rtrim(number_format((float) $this->rate * 100, 3, '.', ''), '0'), '.').'%';
    }
}
