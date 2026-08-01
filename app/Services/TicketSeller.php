<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\VerificationToken;
use App\Notifications\TicketSoldNotification;
use App\Support\NotifyCompany;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Issues tickets for a public purchase.
 *
 * All inside one transaction with the ticket-type rows locked: two buyers
 * racing for the last seat must not both get it, and a five-ticket order that
 * fails on the fifth must leave nothing behind. `sold` on the type is the
 * source of truth for availability — counting ticket rows instead would make
 * voided tickets silently re-open seats the organiser may not want re-sold.
 */
class TicketSeller
{
    /**
     * @param  array<string, int>  $quantities  ticket_type_id => how many
     * @return array<int, Ticket>
     *
     * @throws SoldOutException when any requested type lacks availability
     */
    public function sell(Event $event, array $quantities, string $buyerName, ?string $buyerEmail, ?string $buyerPhone): array
    {
        if (! $event->isSelling()) {
            throw new SoldOutException('Ticket sales for this event have closed.');
        }

        $quantities = array_filter(array_map(intval(...), $quantities), fn (int $n) => $n > 0);

        if ($quantities === []) {
            throw new RuntimeException('Choose at least one ticket.');
        }

        return DB::transaction(function () use ($event, $quantities, $buyerName, $buyerEmail, $buyerPhone) {
            $types = TicketType::query()
                ->whereKey(array_keys($quantities))
                ->where('event_id', $event->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // A type id that is not this event's is a tampered request.
            if ($types->count() !== count($quantities)) {
                throw new RuntimeException('One of the ticket types no longer exists.');
            }

            foreach ($quantities as $typeId => $wanted) {
                $remaining = $types[$typeId]->remaining();

                if ($remaining !== null && $remaining < $wanted) {
                    throw new SoldOutException(sprintf(
                        '%s has %d ticket%s left.',
                        $types[$typeId]->name,
                        $remaining,
                        $remaining === 1 ? '' : 's',
                    ));
                }
            }

            $tickets = [];

            foreach ($quantities as $typeId => $wanted) {
                $type = $types[$typeId];

                for ($i = 0; $i < $wanted; $i++) {
                    $ticket = Ticket::create([
                        'company_id' => $event->company_id,
                        'event_id' => $event->id,
                        'ticket_type_id' => $type->id,
                        'serial' => Ticket::newSerial($event->company_id),
                        'buyer_name' => $buyerName,
                        'buyer_email' => $buyerEmail,
                        'buyer_phone' => $buyerPhone,
                        'price' => $type->price,
                        'status' => 'issued',
                    ]);

                    $token = VerificationToken::create([
                        'company_id' => $event->company_id,
                        'token' => VerificationToken::newToken(),
                        'subject_type' => Ticket::class,
                        'subject_id' => $ticket->id,
                    ]);

                    $ticket->forceFill(['verification_token_id' => $token->id])->save();

                    $tickets[] = $ticket;
                }

                $type->increment('sold', $wanted);
            }

            // Recipients are resolved once and reused for every ticket in this
            // order — looping NotifyCompany::about() per ticket would re-run
            // the company-membership and permission query once per ticket for
            // an order that is, from the door's point of view, one sale.
            try {
                $recipients = NotifyCompany::recipients($event->company, 'events.view');
            } catch (\Throwable) {
                $recipients = collect();
            }

            foreach ($tickets as $ticket) {
                try {
                    $recipients->each->notify(new TicketSoldNotification($ticket));
                } catch (\Throwable) {
                }
            }

            return $tickets;
        });
    }
}
