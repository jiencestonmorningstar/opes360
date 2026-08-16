<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to tell somebody something — including the attempts that
 * deliberately said nothing.
 *
 * Rows are written, never edited by a person, and never deleted by the
 * product. "I never got told" is a support question, and a log that only
 * recorded successes would answer it with silence — which reads exactly like
 * the bug being reported.
 */
class NotificationDelivery extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => 'sent',
    ];

    protected function casts(): array
    {
        return [
            'release_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(NotificationRule::class, 'rule_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Held messages whose hold has expired — the digest command's queue. */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where('status', 'deferred')
            ->where(fn (Builder $q) => $q->whereNull('release_at')->orWhere('release_at', '<=', now()));
    }

    /**
     * What a recipient sees in the delivery log.
     *
     * Plain words rather than the stored key: "muted" tells an administrator
     * chasing a missing alert what to change, where "suppressed/muted" tells
     * them to go and read the source.
     */
    public function outcome(): string
    {
        return match (true) {
            $this->status === 'sent' => 'Delivered',
            $this->status === 'failed' => 'Failed to send',
            $this->reason === 'muted' => 'Not sent — the recipient switched this category off',
            $this->reason === 'duplicate' => 'Not sent — the same message had just gone out',
            $this->reason === 'channel_unavailable' => 'Not sent — that channel is not connected',
            $this->reason === 'quiet_hours' => 'Held until the recipient\'s quiet hours end',
            $this->reason === 'digest' => 'Held for the recipient\'s summary',
            default => ucfirst((string) $this->status),
        };
    }
}
