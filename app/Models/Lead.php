<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Somebody who might become a customer, before they are anyone.
 *
 * Kept out of the customer book on purpose — Contacts are business
 * relationships, and a list padded with trade-fair phone numbers stops being
 * trusted. Converting creates the Contact (and optionally a Deal) through the
 * existing models and links back; the lead row survives as the record of where
 * the customer came from.
 */
class Lead extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    /**
     * The funnel, in order. `converted` and `lost` are terminal and reachable
     * only through LeadFunnel::convert() / lose(), which keep the links and
     * the reason honest against the status.
     */
    public const STATUSES = [
        'new' => 'New',
        'working' => 'Working',
        'qualified' => 'Qualified',
        'converted' => 'Converted',
        'lost' => 'Lost',
    ];

    public const CLOSED_STATUSES = ['converted', 'lost'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'converted_at' => 'datetime',
        ];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** The customer this became, if it made it that far. */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(CrmActivity::class, 'subject')->latest();
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, self::CLOSED_STATUSES, true);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', self::CLOSED_STATUSES);
    }
}
