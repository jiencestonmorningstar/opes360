<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\DomainEvents;
use App\Support\NotificationCategories;
use App\Support\NotificationChannels;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

/**
 * When this happens, and these things are true, tell these people.
 *
 * The conditions half is not reimplemented — it is the same shape and the same
 * matcher the workflow engine and the automation engine both use. The
 * recipients half is not reimplemented either; it goes through the same
 * resolver that decides who approves a workflow step.
 */
class NotificationRule extends Model
{
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    /**
     * Who a rule may name.
     *
     * All of them are descriptions of a position, not of a person, which is
     * the point — see App\Support\NotificationRecipients.
     */
    public const RECIPIENT_MODES = [
        'user' => 'A particular person',
        'role' => 'Everyone with a role',
        'permission' => 'Everyone who can see this kind of record',
        'owner' => 'The business owner',
        'creator' => 'Whoever raised the record',
        'manager' => "The raiser's manager",
        'department' => 'A department manager',
    ];

    protected $guarded = ['id'];

    /**
     * Model::create() does not backfill database defaults, so a rule created
     * without these would read back with nulls until it was refreshed — and a
     * null severity would fail the "is this critical?" check in a way that
     * looks like the rule working.
     */
    protected $attributes = [
        'category' => 'operations',
        'severity' => 'normal',
        'dedupe_minutes' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'recipients' => 'array',
            'channels' => 'array',
            'is_active' => 'boolean',
            'dedupe_minutes' => 'integer',
            'last_fired_at' => 'datetime',
        ];
    }

    /**
     * A rule against an unknown event is a rule that never fires, and nothing
     * would ever say why. Validating here rather than only in the form means
     * an import, a seeder or a console command cannot create one either.
     */
    protected static function booted(): void
    {
        static::saving(function (self $rule) {
            if (! DomainEvents::exists((string) $rule->event)) {
                throw ValidationException::withMessages([
                    'event' => "There is no event called [{$rule->event}].",
                ]);
            }

            if (! NotificationCategories::exists((string) $rule->category)) {
                throw ValidationException::withMessages([
                    'category' => "There is no notification category called [{$rule->category}].",
                ]);
            }

            if (! NotificationCategories::severityExists((string) $rule->severity)) {
                throw ValidationException::withMessages([
                    'severity' => "There is no severity called [{$rule->severity}].",
                ]);
            }

            foreach ((array) $rule->channels as $channel) {
                if (! NotificationChannels::exists((string) $channel)) {
                    throw ValidationException::withMessages([
                        'channels' => "There is no channel called [{$channel}].",
                    ]);
                }
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class, 'rule_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeListeningFor(Builder $query, string $event): Builder
    {
        return $query->where('event', $event);
    }

    public function isCritical(): bool
    {
        return $this->severity === 'critical';
    }
}
