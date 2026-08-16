<?php

namespace App\Models;

use App\Models\Concerns\Approvable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\EmitsDomainEvents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A visit: somebody going somewhere at a time to do something about a ticket.
 *
 * Three things it deliberately does not own. The customer — that is the
 * ticket's, which is the CRM's. The hours — those are `project_time_entries`,
 * the one timesheet in this product. The invoice — `document_id` points at an
 * ordinary sales invoice; a job never renders a bill of its own.
 */
class ServiceJob extends Model
{
    use Approvable;
    use BelongsToCompany;
    use EmitsDomainEvents;
    use HasUlids;
    use SoftDeletes;

    public const STATUSES = [
        'scheduled' => 'Scheduled',
        'in_progress' => 'In progress',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'estimated_minutes' => 'integer',
            'is_billable' => 'boolean',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ServiceTicket::class, 'ticket_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    /** The servicing the asset register was already expecting, where this visit is it. */
    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(AssetMaintenance::class, 'asset_maintenance_id');
    }

    /** The sales invoice this visit ended up on. A link, never a copy. */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function parts(): HasMany
    {
        return $this->hasMany(ServiceJobPart::class);
    }

    /** The existing timesheet, filtered. There is no service timesheet. */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(ProjectTimeEntry::class, 'service_job_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Who the visit is for. Read through the ticket, never stored twice. */
    public function customer(): ?Contact
    {
        return $this->ticket?->contact;
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    public function isBilled(): bool
    {
        return $this->document_id !== null;
    }

    public function hoursLogged(): float
    {
        return (float) $this->timeEntries()->sum('hours');
    }

    public function partsTotal(): float
    {
        return (float) $this->parts()
            ->selectRaw('COALESCE(SUM(quantity * unit_price), 0) as total')
            ->value('total');
    }

    /**
     * What the visit cost the business — labour at the rate it was logged at,
     * plus the parts. Not what it is billed for: a warranty visit costs the
     * same and earns nothing, and a service line that hid that would make
     * every warranty look free.
     */
    public function costToDate(): float
    {
        $labour = (float) $this->timeEntries()
            ->selectRaw('COALESCE(SUM(hours * COALESCE(hourly_rate, 0)), 0) as total')
            ->value('total');

        return $labour + $this->partsTotal();
    }

    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('status', 'scheduled');
    }

    public function scopeUnbilled(Builder $query): Builder
    {
        return $query->where('status', 'completed')->where('is_billable', true)->whereNull('document_id');
    }

    /** @return array{label: string, tone: string} */
    public function state(): array
    {
        return match ($this->status) {
            'in_progress' => ['label' => 'In progress', 'tone' => 'positive'],
            'completed' => ['label' => 'Completed', 'tone' => 'positive'],
            'cancelled' => ['label' => 'Cancelled', 'tone' => 'muted'],
            default => ['label' => 'Scheduled', 'tone' => 'neutral'],
        };
    }

    public function automatableFields(): array
    {
        return ['status', 'technician_id', 'scheduled_for'];
    }
}
