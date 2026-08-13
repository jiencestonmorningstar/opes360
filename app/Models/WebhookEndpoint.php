<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\WebhookEvents;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A URL a business asked us to post to, and the moments it asked about.
 *
 * See the migration for why this exists. In short: an integration that has to
 * poll to learn that an invoice was issued makes a thousand requests to find
 * out about four sales, and still finds out late.
 */
class WebhookEndpoint extends Model
{
    use BelongsToCompany;
    use HasUlids;

    /**
     * How many failures in a row end the relationship.
     *
     * Fifteen rather than three: an endpoint is not dead because its server
     * restarted during a deploy, and a business whose webhooks switched
     * themselves off over a two-minute outage has been given a worse problem
     * than the one this feature solves. Fifteen consecutive failures, each of
     * which has already exhausted its own five attempts across six hours, is
     * not a blip.
     */
    public const FAILURE_LIMIT = 15;

    protected $guarded = ['id'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            /*
             * Encrypted at rest. It is the key that lets anybody forge a
             * delivery to a customer's endpoint, and a database dump that
             * leaks every tenant's secret is a worse day than one that leaks
             * their invoice totals. `$hidden` above stops it serialising;
             * this stops it sitting in the table in the clear.
             */
            'secret' => 'encrypted',
            'is_active' => 'boolean',
            'disabled_at' => 'datetime',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /**
     * A fresh signing secret.
     *
     * Prefixed so that a secret leaked into a log or a support ticket is
     * recognisable as one, which is the difference between somebody rotating
     * it and somebody scrolling past it.
     */
    public static function newSecret(): string
    {
        return 'whsec_'.Str::random(48);
    }

    public function subscribesTo(string $event): bool
    {
        return in_array($event, $this->events ?? [], true);
    }

    public function isDeliverable(): bool
    {
        return $this->is_active && $this->disabled_at === null;
    }

    /** Record a success and forgive the history that came before it. */
    public function recordSuccess(): void
    {
        if ($this->consecutive_failures === 0) {
            return;
        }

        $this->forceFill(['consecutive_failures' => 0])->save();
    }

    /**
     * Record a failure, and switch the endpoint off if it has been failing
     * long enough that nobody is plausibly listening.
     *
     * The reason is stored rather than logged, because the person who needs it
     * is the business owner looking at the settings screen and asking why
     * their webhooks stopped — not an engineer with access to the logs.
     */
    public function recordFailure(string $reason): void
    {
        $failures = $this->consecutive_failures + 1;

        $attributes = ['consecutive_failures' => $failures];

        if ($failures >= self::FAILURE_LIMIT && $this->disabled_at === null) {
            $attributes['is_active'] = false;
            $attributes['disabled_at'] = now();
            $attributes['disabled_reason'] = Str::limit(
                sprintf('Switched off after %d failed deliveries in a row. Last error: %s', $failures, $reason),
                290,
            );
        }

        $this->forceFill($attributes)->save();
    }

    /** Turn it back on, and forget the failures that stopped it. */
    public function reactivate(): void
    {
        $this->forceFill([
            'is_active' => true,
            'disabled_at' => null,
            'disabled_reason' => null,
            'consecutive_failures' => 0,
        ])->save();
    }

    /** @return array<int, string> */
    public function describedEvents(): array
    {
        return array_map(
            fn (string $event) => WebhookEvents::describe($event),
            $this->events ?? [],
        );
    }
}
