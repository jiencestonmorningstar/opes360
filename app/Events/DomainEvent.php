<?php

namespace App\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something happened, said once, in a form anything can listen to.
 *
 * One class rather than one per event, because the name is data. Sixty event
 * classes would be sixty files differing only in a string, and an automation
 * rule matching on a name would have to map class names back to it anyway.
 */
class DomainEvent
{
    use Dispatchable;

    /** @param  array<string, mixed>  $context */
    public function __construct(
        public readonly string $name,
        public readonly string $companyId,
        public readonly Model $subject,
        public readonly array $context = [],
        public readonly ?int $actorId = null,
    ) {}

    /**
     * A reference to the subject, never a copy of it.
     *
     * Whoever handles this loads the record and gets the current one, with the
     * current permissions applied, rather than a snapshot that has silently
     * drifted. A payload embedding a copy of an invoice would be a second copy
     * of an invoice, which is what §56 of the brief spends its length
     * forbidding.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'event' => $this->name,
            'company_id' => $this->companyId,
            'subject_type' => $this->subject->getMorphClass(),
            'subject_id' => $this->subject->getKey(),
            'actor_id' => $this->actorId,
            'context' => $this->context,
        ];
    }
}
