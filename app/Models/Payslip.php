<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One person, one month, every figure that made it up.
 *
 * A record of an arithmetic that happened, not a view over one that could
 * happen. When the finance act moves the IRPP bands in January, every payslip
 * already issued keeps saying what it said — the employee has a copy and the
 * CNPS has a declaration built from it, so recomputing on read would rewrite
 * documents that exist outside this database.
 */
class Payslip extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'paid_on' => 'date',
            'snapshot' => 'array',
            'base_salary' => 'decimal:2',
            'taxable_allowances' => 'decimal:2',
            'exempt_allowances' => 'decimal:2',
            'overtime' => 'decimal:2',
            'gross' => 'decimal:2',
            'taxable_gross' => 'decimal:2',
            'cnps_base' => 'decimal:2',
            'cnps_base_uncapped' => 'decimal:2',
            'cnps_employee' => 'decimal:2',
            'irpp' => 'decimal:2',
            'cac' => 'decimal:2',
            'cfc_employee' => 'decimal:2',
            'tdl' => 'decimal:2',
            'rav' => 'decimal:2',
            'other_deductions' => 'decimal:2',
            'advances' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'net_pay' => 'decimal:2',
            'cnps_employer_pension' => 'decimal:2',
            'cnps_employer_family' => 'decimal:2',
            'cnps_employer_risk' => 'decimal:2',
            'cfc_employer' => 'decimal:2',
            'fne' => 'decimal:2',
            'employer_charges' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'days_worked' => 'decimal:2',
            'days_absent' => 'decimal:2',
            'leave_days' => 'decimal:2',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(EmploymentContract::class, 'employment_contract_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayslipLine::class);
    }

    /**
     * The employee's name as it was when this was issued.
     *
     * From the snapshot rather than the relation: a payslip has to keep naming
     * the person and the job it was issued for, and a corrected spelling three
     * years later must not silently reissue history.
     */
    public function employeeName(): string
    {
        return $this->snapshot['name'] ?? $this->employee?->name() ?? '—';
    }

    public function jobTitle(): ?string
    {
        return $this->snapshot['job_title'] ?? null;
    }

    /** Everything the state takes, whichever side of the payslip it sits on. */
    public function totalStatutory(): float
    {
        return round(
            (float) $this->cnps_employee + (float) $this->irpp + (float) $this->cac
            + (float) $this->cfc_employee + (float) $this->tdl + (float) $this->rav
            + (float) $this->employer_charges,
            2
        );
    }
}
