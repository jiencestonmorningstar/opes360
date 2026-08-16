<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\EmitsDomainEvents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A customer asking for help.
 *
 * The customer is a `contacts` row and nothing here duplicates it. The machine,
 * where there is one, is a `fixed_assets` row. This model's own job is the
 * clock: when the answer was promised, when the fix was promised, and how much
 * of that promise has been suspended.
 */
class ServiceTicket extends Model
{
    use BelongsToCompany;
    use EmitsDomainEvents;
    use HasUlids;
    use SoftDeletes;

    public const STATUSES = [
        'new' => 'New',
        'open' => 'Open',
        'pending_customer' => 'Waiting on customer',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
        'cancelled' => 'Cancelled',
    ];

    public const PRIORITIES = [
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
        'urgent' => 'Urgent',
    ];

    public const CHANNELS = [
        'phone' => 'Phone',
        'email' => 'Email',
        'walk_in' => 'Walk-in',
        'portal' => 'Portal',
        'whatsapp' => 'WhatsApp',
        'internal' => 'Raised internally',
    ];

    /** Statuses in which nobody is working the ticket, so no clock runs. */
    public const SETTLED = ['resolved', 'closed', 'cancelled'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'response_due_at' => 'datetime',
            'resolution_due_at' => 'datetime',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'paused_at' => 'datetime',
            'breach_notified_at' => 'datetime',
            'paused_minutes' => 'integer',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The equipment this is about.
     *
     * Named `asset` and not `fixedAsset` for readability, but note the trap
     * `FixedAsset::locationRecord()` already documents: Eloquent resolves an
     * attribute before a same-named relation, silently. There is no `asset`
     * column on this table, and there must not be one.
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(ServiceSlaPolicy::class, 'sla_policy_id');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(ServiceJob::class, 'ticket_id')->orderBy('scheduled_for');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ServiceTicketEvent::class, 'ticket_id')->orderBy('occurred_at');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /*
     * The clock, read.
     *
     * Every question below is answered from stored instants alone. Nothing
     * here consults the working calendar, which is what lets the same question
     * be asked of ten thousand rows in SQL — see SlaBoard.
     */

    public function isPaused(): bool
    {
        return $this->status === 'pending_customer';
    }

    public function isSettled(): bool
    {
        return in_array($this->status, self::SETTLED, true);
    }

    public function isOpen(): bool
    {
        return ! $this->isSettled();
    }

    public function hasBreachedResponse(?Carbon $at = null): bool
    {
        if ($this->response_due_at === null) {
            return false;
        }

        // Answered late is still late: a breach does not un-happen because
        // somebody eventually picked up the phone.
        if ($this->first_response_at !== null) {
            return $this->first_response_at->gt($this->response_due_at);
        }

        if ($this->isPaused() || $this->isSettled()) {
            return false;
        }

        return ($at ?? now())->gt($this->response_due_at);
    }

    public function hasBreachedResolution(?Carbon $at = null): bool
    {
        if ($this->resolution_due_at === null) {
            return false;
        }

        if ($this->resolved_at !== null) {
            return $this->resolved_at->gt($this->resolution_due_at);
        }

        if ($this->isPaused() || $this->isSettled()) {
            return false;
        }

        return ($at ?? now())->gt($this->resolution_due_at);
    }

    public function hasBreached(?Carbon $at = null): bool
    {
        return $this->hasBreachedResponse($at) || $this->hasBreachedResolution($at);
    }

    /** Tickets somebody is expected to be working on right now. */
    public function scopeOnTheClock(Builder $query): Builder
    {
        return $query->whereNotIn('status', self::SETTLED)->where('status', '!=', 'pending_customer');
    }

    public function scopeAwaitingResponse(Builder $query): Builder
    {
        return $query->whereNull('first_response_at');
    }

    public function scopeAssignedTo(Builder $query, User $user): Builder
    {
        return $query->where('assignee_id', $user->id);
    }

    public function priorityLabel(): string
    {
        return self::PRIORITIES[$this->priority] ?? ucfirst((string) $this->priority);
    }

    /** @return array{label: string, tone: string} */
    public function state(): array
    {
        return match ($this->status) {
            'new' => ['label' => 'New', 'tone' => 'neutral'],
            'open' => ['label' => 'Open', 'tone' => 'positive'],
            'pending_customer' => ['label' => 'Waiting on customer', 'tone' => 'warning'],
            'resolved' => ['label' => 'Resolved', 'tone' => 'positive'],
            'closed' => ['label' => 'Closed', 'tone' => 'muted'],
            default => ['label' => 'Cancelled', 'tone' => 'muted'],
        };
    }

    /**
     * What an automation rule may set.
     *
     * Deliberately excludes every clock column. A rule that could write
     * `resolution_due_at` could clear the business's own promise, and the
     * breach board would then be reporting whatever the rule felt like.
     */
    public function automatableFields(): array
    {
        return ['priority', 'assignee_id', 'category'];
    }
}
