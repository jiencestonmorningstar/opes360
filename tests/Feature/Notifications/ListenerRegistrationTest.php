<?php

namespace Tests\Feature\Notifications;

use App\Events\DomainEvent;
use App\Models\NotificationDelivery;
use Illuminate\Support\Facades\Event;

/**
 * The listener must be wired exactly once.
 *
 * Laravel's event discovery already finds anything in app/Listeners whose
 * handle() type-hints an event, so adding an explicit Event::listen() for this
 * one would register it twice — and a listener registered twice sends every
 * notification twice, which is the exact failure the whole of Phase 4.4 exists
 * to prevent. This test fails the moment somebody adds the extra line.
 */
class ListenerRegistrationTest extends NotificationTestCase
{
    public function test_the_listener_is_registered_exactly_once(): void
    {
        $registered = collect(Event::getListeners(DomainEvent::class))
            ->filter(fn ($listener) => str_contains(
                is_string($listener) ? $listener : $this->describe($listener),
                'RunNotificationRules'
            ));

        $this->assertCount(1, $registered, 'RunNotificationRules is wired more than once.');
    }

    public function test_one_event_produces_one_delivery_per_recipient_and_channel(): void
    {
        $this->notificationRule(['channels' => ['in_app']]);

        $this->expense()->emitDomainEvent('expense.recorded');

        $this->assertSame(1, NotificationDelivery::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, $this->owner->notifications()->count());
    }

    protected function describe(mixed $listener): string
    {
        if (! $listener instanceof \Closure) {
            return '';
        }

        $reflection = new \ReflectionFunction($listener);
        $bound = $reflection->getStaticVariables();

        return json_encode(array_map(
            fn ($value) => is_object($value) ? $value::class : $value,
            $bound
        )) ?: '';
    }
}
