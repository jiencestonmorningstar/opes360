<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\TicketResource;
use App\Models\Event;
use App\Models\Ticket;
use App\Services\SoldOutException;
use App\Services\TicketSeller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The door: who holds a ticket, and letting them in.
 *
 * Issuing goes through TicketSeller — the same service the public sales page
 * calls — and for the reason its docblock gives: `sold` on the ticket type is
 * the oversell guard, and it is only correct because every sale takes the row
 * lock. An endpoint that inserted ticket rows directly would sell the last
 * seat twice on a busy night and nothing would notice until two people arrived
 * holding it.
 *
 * There is no delete route. A ticket that should not have been sold is voided
 * on the screen, which keeps the row, its serial and its QR — a serial that
 * simply vanished would make an honest buyer at the door indistinguishable
 * from a forged one.
 */
class TicketController extends ApiController
{
    public function __construct(private readonly TicketSeller $seller) {}

    /** The attendee list for one event. */
    public function index(Request $request, Event $event): AnonymousResourceCollection
    {
        $this->authorize('view', $event);

        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(['issued', 'checked_in', 'void'])],
            'q' => ['sometimes', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $tickets = $event->tickets()
            ->with('ticketType')
            ->when(isset($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(isset($filters['q']), function (Builder $q) use ($filters) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($filters['q'])).'%';

                $q->where(fn (Builder $inner) => $inner
                    ->where('serial', 'like', $term)
                    ->orWhere('buyer_name', 'like', $term)
                    ->orWhere('buyer_email', 'like', $term));
            })
            ->latest()
            ->paginate($filters['per_page'] ?? 25);

        return TicketResource::collection($tickets);
    }

    /**
     * Sell — or issue — one or more tickets for an event.
     *
     * Under `events.create` rather than a scope of its own: the catalogue has
     * no separate "sell" action, and issuing a ticket is the act of creating
     * one. That does mean a cashier, who may scan at the door, cannot issue
     * over the API; that is the same line the role catalogue already draws and
     * not one to redraw here.
     *
     * `quantities` is keyed by ticket type id — one call issues a whole order,
     * because a four-seat order that half-succeeded is worse than one that
     * failed, and only TicketSeller's single transaction can promise that.
     */
    public function store(Request $request, Event $event): JsonResponse
    {
        $this->authorize('view', $event);
        $this->authorize('create', Ticket::class);

        $data = $request->validate([
            'buyer_name' => ['required', 'string', 'max:120'],
            'buyer_email' => ['nullable', 'email', 'max:255', 'required_without:buyer_phone'],
            'buyer_phone' => ['nullable', 'string', 'max:40', 'required_without:buyer_email'],
            'quantities' => ['required', 'array', 'min:1'],
            'quantities.*' => ['integer', 'min:0', 'max:10'],
        ]);

        try {
            $tickets = $this->seller->sell(
                $event,
                $data['quantities'],
                $data['buyer_name'],
                $data['buyer_email'] ?? null,
                $data['buyer_phone'] ?? null,
            );
        } catch (SoldOutException $e) {
            // The message is written for a buyer and is safe to hand back.
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $tickets = Ticket::query()
            ->with(['ticketType', 'verificationToken'])
            ->whereKey(collect($tickets)->pluck('id'))
            ->get();

        return TicketResource::collection($tickets)->response()->setStatusCode(201);
    }

    /**
     * Admit the holder.
     *
     * Nested under the event so that a serial from another event 404s rather
     * than quietly admitting somebody to the wrong night.
     *
     * A second check-in is refused with `422` rather than silently accepted.
     * The screen no-ops because the person operating it can see the row; a
     * scanner cannot, and "this ticket was already used at 20:14" is the one
     * answer a door actually needs.
     */
    public function checkIn(Event $event, Ticket $ticket): TicketResource|JsonResponse
    {
        $this->authorize('view', $event);

        if ($ticket->event_id !== $event->id) {
            throw new NotFoundHttpException;
        }

        $this->authorize('checkIn', $ticket);

        if ($ticket->isVoid()) {
            return response()->json(['message' => 'That ticket has been voided.'], 422);
        }

        if ($ticket->isCheckedIn()) {
            return response()->json([
                'message' => 'That ticket was already checked in at '
                    .$ticket->checked_in_at?->toIso8601String().'.',
            ], 422);
        }

        $ticket->forceFill([
            'status' => 'checked_in',
            'checked_in_at' => now(),
            'checked_in_by' => request()->user()->id,
        ])->save();

        return TicketResource::make($ticket->fresh()->load('ticketType'));
    }
}
