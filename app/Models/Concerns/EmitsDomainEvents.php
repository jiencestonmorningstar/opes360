<?php

namespace App\Models\Concerns;

use App\Events\DomainEvent;
use App\Support\CurrentCompany;

/**
 * Lets a model announce what happened to it.
 *
 * Called at the point the thing actually happens — inside the service or the
 * model, never in a controller. A screen and an API call must produce the same
 * event, and they only do if the emitter sits below both of them.
 */
trait EmitsDomainEvents
{
    /**
     * Which fields an automation rule may set on this model.
     *
     * Empty by default, so a model gains nothing by being listenable. A global
     * allow-list was the first attempt and was wrong: it had to guess column
     * names across every table, and guessing wide enough to be useful meant
     * guessing wide enough to let a rule set `status` on an invoice. Each
     * model names its own, or automation cannot write to it at all.
     *
     * @return array<int, string>
     */
    public function automatableFields(): array
    {
        return [];
    }

    /** @param  array<string, mixed>  $context */
    public function emitDomainEvent(string $name, array $context = []): void
    {
        $companyId = $this->getAttribute('company_id') ?? app(CurrentCompany::class)->id();

        /*
         * No company, no event. An event with no tenant cannot be matched
         * against any company's rules, and dispatching one anyway would mean
         * every listener had to re-check something the bus should have
         * guaranteed.
         */
        if ($companyId === null) {
            return;
        }

        DomainEvent::dispatch($name, (string) $companyId, $this, $context, auth()->id());
    }
}
