<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A person on the payroll.
 *
 * Deliberately not a `User`. Most staff in the businesses this serves will
 * never log in and several have no email address; requiring an account before
 * a person can be paid would mean inventing credentials for a night watchman.
 * `user_id` links the ones who do have a login, and is null for everyone else.
 */
class Employee extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    public const STATUSES = [
        'active' => 'Active',
        'suspended' => 'Suspended',
        'ended' => 'Left',
    ];

    public const PAYMENT_METHODS = [
        'cash' => 'Cash',
        'mobile_money' => 'Mobile Money',
        'bank' => 'Bank transfer',
        'cheque' => 'Cheque',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'hired_on' => 'date',
            'ended_on' => 'date',
            'dependants' => 'integer',
            'leave_opening_balance' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(EmploymentContract::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(SalaryComponent::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function name(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function initials(): string
    {
        return strtoupper(mb_substr($this->first_name ?: '?', 0, 1).mb_substr($this->last_name ?: '', 0, 1));
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * The contract in force on a given date.
     *
     * A payroll run asks for the contract that applied to *its own* period,
     * not the one that applies today: an employee promoted in June must keep
     * producing June's payslip at June's salary for the rest of time.
     */
    public function contractOn(string $date): ?EmploymentContract
    {
        return $this->contracts
            ->where('status', '!=', 'draft')
            ->filter(fn (EmploymentContract $c) => $c->coversDate($date))
            ->sortByDesc('starts_on')
            ->first();
    }

    /** The current one, for the screens. */
    public function activeContract(): ?EmploymentContract
    {
        return $this->contractOn(now()->toDateString());
    }

    /**
     * Days of paid leave accrued since hire, less what has been taken.
     *
     * A day and a half a month under the Code du travail, plus whatever the
     * business carried in when it started using the software — a business
     * that has been running for eight years cannot have its staff start from
     * zero because the software is new.
     */
    public function leaveBalance(?string $asOf = null): float
    {
        $asOf = $asOf ? Carbon::parse($asOf) : now();

        $months = $this->hired_on === null
            ? 0
            : max(0, $this->hired_on->copy()->startOfMonth()->diffInMonths($asOf->copy()->startOfMonth()));

        $accrued = $months * (float) config('payroll.leave.accrual_days_per_month', 1.5);

        $taken = (float) $this->leaveRequests
            ->where('status', 'approved')
            ->where('deducts_balance', true)
            ->sum('days');

        return round((float) $this->leave_opening_balance + $accrued - $taken, 2);
    }
}
