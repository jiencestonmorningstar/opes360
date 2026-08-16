<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One thing that happened to a ticket. Append-only.
 *
 * This is the clock's evidence. A deadline that moved by ninety minutes is
 * either defensible or it is an argument with the customer, and it is only
 * defensible if there is a row saying who paused it, when, and how much
 * working time that turned out to be.
 */
class ServiceTicketEvent extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'clock_minutes' => 'integer',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ServiceTicket::class, 'ticket_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        // Same guarantee an issued document already gives: history that can be
        // rewritten is not history. Nothing about a past event can change.
        static::updating(function () {
            throw new RuntimeException('A ticket event is a record of what happened and cannot be edited.');
        });

        static::deleting(function () {
            throw new RuntimeException('A ticket event cannot be deleted.');
        });
    }
}
