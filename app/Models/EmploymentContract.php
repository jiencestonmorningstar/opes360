<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * What an employee was engaged on, and for how much.
 *
 * Separate from the employee because pay changes and history must not. A
 * payroll run reads the contract in force on its own period rather than the
 * latest one, which is the only way June's payslip still says June's salary
 * after a July promotion.
 */
class EmploymentContract extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'ended_on' => 'date',
            'trial_ends_on' => 'date',
            'signed_on' => 'date',
            'base_salary' => 'decimal:2',
            'hours_per_week' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function typeLabel(): string
    {
        return config('payroll.contract_types')[$this->type] ?? ucfirst((string) $this->type);
    }

    public function isOpenEnded(): bool
    {
        return $this->ends_on === null;
    }

    /** Whether this contract was the one in force on a given day. */
    public function coversDate(string $date): bool
    {
        $day = Carbon::parse($date);

        if ($this->starts_on->gt($day)) {
            return false;
        }

        // `ended_on` is early termination; `ends_on` is the term it was
        // written for. Either one closes the contract, whichever came first.
        $closed = collect([$this->ends_on, $this->ended_on])->filter()->min();

        return $closed === null || Carbon::parse($closed)->gte($day);
    }

    /** A fixed-term contract inside its last month, which needs a decision. */
    public function expiresSoon(int $days = 30): bool
    {
        return $this->ends_on !== null
            && $this->status === 'active'
            && $this->ends_on->isFuture()
            && now()->diffInDays($this->ends_on) <= $days;
    }

    public function isBelowMinimumWage(): bool
    {
        $smig = (float) config('payroll.smig', 0);

        return $smig > 0 && (float) $this->base_salary < $smig;
    }
}
