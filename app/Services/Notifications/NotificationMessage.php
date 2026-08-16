<?php

namespace App\Services\Notifications;

use App\Models\NotificationRule;
use Illuminate\Database\Eloquent\Model;

/**
 * One thing to say, and everything needed to decide whether to say it.
 *
 * A value object rather than a long argument list, because the dispatcher
 * passes the same bundle through five decisions in a row and a positional
 * argument would eventually be swapped.
 *
 * It carries a reference to the subject, never a copy of the record — the same
 * rule DomainEvent follows. Whoever renders it loads the current row with
 * current permissions applied, rather than a snapshot that has drifted.
 */
class NotificationMessage
{
    public function __construct(
        public readonly string $companyId,
        public readonly string $event,
        public readonly string $category,
        public readonly string $severity,
        public readonly string $title,
        public readonly ?string $body = null,
        public readonly ?string $url = null,
        public readonly ?string $subjectType = null,
        public readonly int|string|null $subjectId = null,
        public readonly ?string $ruleId = null,
        /** @var array<int, string> */
        public readonly array $channels = ['in_app'],
        public readonly int $dedupeMinutes = 0,
    ) {}

    public static function fromRule(NotificationRule $rule, string $event, ?Model $subject): self
    {
        return new self(
            companyId: (string) $rule->company_id,
            event: $event,
            category: (string) $rule->category,
            severity: (string) $rule->severity,
            title: (string) $rule->title,
            body: $rule->body,
            url: $rule->url,
            subjectType: $subject?->getMorphClass(),
            subjectId: $subject?->getKey(),
            ruleId: (string) $rule->id,
            channels: array_values(array_filter((array) $rule->channels, 'is_string')),
            dedupeMinutes: (int) $rule->dedupe_minutes,
        );
    }

    /**
     * Always delivered, whatever the recipient has switched off.
     *
     * Exactly one severity may do this. If `normal` could too, a mute would
     * mean nothing, and people whose mutes are ignored stop using them and
     * start filtering the whole sender — which hides the critical ones.
     */
    public function isCritical(): bool
    {
        return $this->severity === 'critical';
    }

    /**
     * The fingerprint two notifications must share to count as the same news.
     *
     * The record is in it, so two invoices never suppress each other. The
     * title is in it, so "approved" does not suppress "rejected" about the
     * same invoice a minute later.
     */
    public function dedupeKey(int $userId, string $channel): string
    {
        return hash('sha256', implode('|', [
            $this->companyId,
            $this->ruleId ?? $this->event,
            $userId,
            $channel,
            $this->subjectType ?? '',
            (string) ($this->subjectId ?? ''),
            $this->title,
        ]));
    }
}
