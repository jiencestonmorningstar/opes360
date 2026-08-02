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
 * Time off, asked for and decided.
 *
 * `days` is stored rather than derived from the two dates. Which days count is
 * a decision about the business's working week, and recomputing an approved
 * request years later under a changed setting would silently rewrite a balance
 * somebody already relied on.
 */
class LeaveRequest extends Model
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
            'days' => 'decimal:2',
            'paid' => 'boolean',
            'deducts_balance' => 'boolean',
            'decided_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function typeLabel(): string
    {
        return config('payroll.leave.types')[$this->type] ?? ucfirst((string) $this->type);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Working days between two dates, Saturdays and Sundays excluded.
     *
     * Public holidays are not deducted: Cameroon's calendar includes movable
     * feasts whose dates are announced rather than computed, and a wrong
     * holiday silently gives or takes a day of someone's leave. The count is
     * offered as a starting figure the approver can overwrite.
     */
    public static function workingDaysBetween(string $from, string $to): float
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();

        if ($end->lt($start)) {
            return 0;
        }

        $days = 0;

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            if (! $day->isWeekend()) {
                $days++;
            }
        }

        return (float) $days;
    }

    /** Whether this request overlaps another for the same person. */
    public function overlaps(self $other): bool
    {
        return $this->starts_on->lte($other->ends_on) && $this->ends_on->gte($other->starts_on);
    }
}
