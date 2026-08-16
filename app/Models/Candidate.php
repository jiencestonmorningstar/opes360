<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person who applied — not yet an Employee, maybe never one.
 *
 * Kept apart from the application so one person applying to two vacancies is
 * one person, and kept apart from Employee so the staff file never carries
 * the data-protection burden of everyone who was turned down.
 */
class Candidate extends Model
{
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    protected $guarded = ['id'];

    /**
     * The employee this candidate became, if hired.
     *
     * `employee`, not `employeeRecord`: the shadowing trap that forced
     * `departmentRecord` and `positionRecord` on Employee needs a same-named
     * attribute to bite, and this table has an `employee_id` column, not an
     * `employee` one. Checked against the migration before naming it.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }

    public function name(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function isHired(): bool
    {
        return $this->employee_id !== null;
    }
}
