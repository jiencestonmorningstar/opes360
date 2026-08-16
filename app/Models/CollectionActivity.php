<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One act of chasing: a call made, a mail sent, a promise given.
 *
 * Deliberately separate from `contact_notes`, which is the relationship file
 * anybody may read and write. Collections notes are about money and are read in
 * a particular order — by promise date and by how long it has been since anyone
 * spoke — and mixing them into general notes would bury them.
 */
class CollectionActivity extends Model
{
    use BelongsToCompany;
    use HasUlids;

    public const KIND_CALL = 'call';

    public const KIND_EMAIL = 'email';

    public const KIND_VISIT = 'visit';

    public const KIND_NOTE = 'note';

    public const KIND_PROMISE = 'promise';

    public const KIND_DISPUTE = 'dispute';

    /** @var array<string, string> */
    public const KINDS = [
        self::KIND_CALL => 'Call',
        self::KIND_EMAIL => 'Email',
        self::KIND_VISIT => 'Visit',
        self::KIND_NOTE => 'Note',
        self::KIND_PROMISE => 'Promise to pay',
        self::KIND_DISPUTE => 'Disputed',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'promised_at' => 'date',
            'promised_amount' => 'decimal:2',
            'happened_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** A commitment to pay a named amount on a named day. */
    public function scopePromises(Builder $query): Builder
    {
        return $query->whereNotNull('promised_at');
    }

    public function label(): string
    {
        return self::KINDS[$this->kind] ?? 'Note';
    }
}
