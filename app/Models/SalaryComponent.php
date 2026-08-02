<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A recurring line on someone's payslip: a transport allowance, a housing
 * allowance, a loan repayment.
 *
 * `taxable` and `cnps_liable` are separate flags because they genuinely come
 * apart — a transport allowance within the legal limit is outside both bases,
 * and treating "allowance" as one thing would misstate every payslip that has
 * one. The business declares what each component is; the calculator only obeys.
 */
class SalaryComponent extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'rate' => 'decimal:4',
            'taxable' => 'boolean',
            'cnps_liable' => 'boolean',
            'active' => 'boolean',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isAllowance(): bool
    {
        return $this->kind === 'allowance';
    }

    /** Whether this component applied during a given month. */
    public function appliesOn(string $date): bool
    {
        if (! $this->active) {
            return false;
        }

        $day = Carbon::parse($date);

        return ($this->starts_on === null || $this->starts_on->lte($day))
            && ($this->ends_on === null || $this->ends_on->gte($day));
    }

    /**
     * What it is worth against a given base salary.
     *
     * A percentage where one is set, a flat amount otherwise. Both are stored
     * so a business can move a component from one to the other without losing
     * the figure it had before.
     */
    public function valueOn(float $baseSalary): float
    {
        if ($this->rate !== null && (float) $this->rate > 0) {
            return round($baseSalary * (float) $this->rate, 2);
        }

        return round((float) $this->amount, 2);
    }
}
