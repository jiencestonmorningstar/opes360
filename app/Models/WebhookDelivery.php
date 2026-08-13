<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One attempt — or one series of attempts — to tell an endpoint about an event.
 *
 * The row is created before the first request goes out, not after it comes
 * back, so a delivery that is still being retried is visible rather than
 * invisible. "Nothing arrived and nothing is recorded" and "nothing arrived
 * and we tried five times" look identical to the receiving side and are
 * completely different problems.
 */
class WebhookDelivery extends Model
{
    use BelongsToCompany;
    use HasUlids;

    public const PENDING = 'pending';

    public const DELIVERED = 'delivered';

    public const FAILED = 'failed';

    /**
     * How long to wait before each retry, in seconds: a minute, five minutes,
     * half an hour, two hours, six hours.
     *
     * Five attempts spanning a little under nine hours. The shape matters more
     * than the numbers: the first retry is quick because most failures are a
     * dropped connection or a server that was restarting, and the last one is
     * far away because a failure that has survived two hours is an outage
     * somebody has to fix rather than one that will pass. Stopping at nine
     * hours rather than retrying for days is the honest position — an event
     * delivered a day late is rarely worth having, and the deliveries screen
     * plus manual redelivery is a better answer than an infinite queue.
     */
    public const BACKOFF = [60, 300, 1800, 7200, 21600];

    /** One first attempt plus one per backoff step. */
    public const MAX_ATTEMPTS = 5;

    /** Keep enough of a response to debug with, not enough to fill a disk. */
    public const TRUNCATE_BODY_AT = 2000;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'delivered_at' => 'datetime',
            'next_attempt_at' => 'datetime',
        ];
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    /**
     * The wait before the attempt after the one just made, or null when there
     * are none left.
     *
     * @param  int  $attemptsMade  Attempts already completed, including the one
     *                             that just failed.
     */
    public static function backoffAfter(int $attemptsMade): ?int
    {
        return self::BACKOFF[$attemptsMade - 1] ?? null;
    }

    public static function truncate(?string $body): ?string
    {
        if ($body === null || $body === '') {
            return null;
        }

        return Str::limit($body, self::TRUNCATE_BODY_AT, '… [truncated]');
    }

    public function hasAttemptsLeft(): bool
    {
        return $this->attempts < self::MAX_ATTEMPTS;
    }

    public function isFailed(): bool
    {
        return $this->status === self::FAILED;
    }
}
