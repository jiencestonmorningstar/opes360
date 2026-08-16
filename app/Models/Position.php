<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A job, as a thing the business keeps rather than a phrase somebody typed.
 *
 * This replaces a free-text `employees.job_title` column the same way
 * Department replaced `employees.department`. That column is still there and
 * still populated: it is what the business typed, payroll snapshots it onto
 * payslips, and if the backfill merged two spellings that were really two
 * different jobs they have to be able to see that.
 */
class Position extends Model
{
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function performanceReviews(): HasMany
    {
        return $this->hasMany(PerformanceReview::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * People currently doing this job.
     *
     * Leavers keep their position_id — their payslips and appraisals are read
     * against it years later — so a plain count of the relation would report a
     * shop with two assistants as having had eleven.
     */
    public function activeHeadcount(): int
    {
        return $this->employees()->where('status', 'active')->count();
    }

    public function label(): string
    {
        return $this->department === null
            ? (string) $this->title
            : $this->department->name.' / '.$this->title;
    }
}
