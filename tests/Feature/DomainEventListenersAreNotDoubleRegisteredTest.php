<?php

namespace Tests\Feature;

use App\Events\DomainEvent;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * One listener, one registration.
 *
 * Laravel's event discovery already registers every class in app/Listeners
 * with a `handle(DomainEvent $event)` signature. For a while this provider
 * ALSO registered six of them by hand, so each ran twice on every domain
 * event: automation rules fired twice, which for a notify rule meant two
 * messages and for a write rule meant two records.
 *
 * Nothing errored — that is exactly why it went unnoticed, and why it needs a
 * test rather than a comment. The next person to add a listener will be
 * tempted to add an `Event::listen` line beside it "to be safe".
 */
class DomainEventListenersAreNotDoubleRegisteredTest extends TestCase
{
    public function test_no_domain_event_listener_is_registered_twice(): void
    {
        $listeners = Event::getListeners(DomainEvent::class);

        $this->assertNotEmpty($listeners, 'Discovery found nothing — it is off, or the listeners moved.');

        $names = array_map($this->nameOf(...), $listeners);
        $duplicates = array_keys(array_filter(array_count_values($names), fn ($n) => $n > 1));

        $this->assertSame([], $duplicates, sprintf(
            'These listeners are registered more than once and will run more than once per event: %s. '.
            'Discovery already registers them; remove the Event::listen() call.',
            implode(', ', $duplicates)
        ));
    }

    /**
     * Discovery wraps each listener in a closure, so the class name has to be
     * recovered from what the closure closed over rather than read off it.
     */
    protected function nameOf(mixed $listener): string
    {
        if (is_string($listener)) {
            return $listener;
        }

        $bound = (new \ReflectionFunction($listener))->getStaticVariables();

        foreach (['listener', 'class', 'abstract'] as $key) {
            if (is_string($bound[$key] ?? null)) {
                return $bound[$key];
            }
        }

        return 'closure@'.spl_object_id($listener);
    }
}
