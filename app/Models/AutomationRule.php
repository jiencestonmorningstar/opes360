<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * When this happens, and these things are true, do that.
 *
 * The conditions half is not reimplemented here — it is the same shape and the
 * same matcher the workflow engine uses. One condition language for the whole
 * product, or the second one eventually grows an eval.
 */
class AutomationRule extends Model
{
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    /**
     * What a rule may do. Deliberately closed, and deliberately short.
     *
     * Every entry is a verb the product already performs. An action typed into
     * an admin form must never be executable code, so there is no "run this"
     * and no "call that" — a rule that wants a new capability gets a new entry
     * here, reviewed, rather than a way to smuggle one in as configuration.
     */
    public const ACTIONS = [
        'start_workflow' => 'Start an approval',
        'notify_user' => 'Notify a person',
        'notify_role' => 'Notify everyone with a role',
        'send_webhook' => 'Send a webhook',
        'set_field' => 'Set a field on the record',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'action_config' => 'array',
            'is_active' => 'boolean',
            'last_fired_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeListeningFor(Builder $query, string $event): Builder
    {
        return $query->where('event', $event);
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? 'Unknown action';
    }
}
