<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\EventResource;
use App\Http\Resources\TicketTypeResource;
use App\Models\Event;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Events, read only.
 *
 * Creating and editing an event is deliberately absent. An event is a poster:
 * a title, a venue, a date and a price list that somebody writes once, checks
 * on a screen, and then shares a link to. Nothing about it repeats, nothing
 * about it arrives from another system, and getting the date wrong is a
 * mistake the public page prints. The integrations that want this module want
 * to *sell* through it and *scan* at the door, and those are the two endpoints
 * that exist.
 */
class EventController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Event::class);

        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(['draft', 'published', 'cancelled'])],
            'upcoming' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $events = Event::query()
            ->when(isset($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when($request->boolean('upcoming'), fn (Builder $q) => $q->where('starts_at', '>=', now()))
            ->when(isset($filters['q']), function (Builder $q) use ($filters) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($filters['q'])).'%';

                $q->where(fn (Builder $inner) => $inner
                    ->where('title', 'like', $term)
                    ->orWhere('venue', 'like', $term));
            })
            ->orderByDesc('starts_at')
            ->paginate($filters['per_page'] ?? 25);

        return EventResource::collection($events);
    }

    public function show(Event $event): EventResource
    {
        $this->authorize('view', $event);

        return EventResource::make($event->load('ticketTypes'));
    }

    /**
     * The price list, on its own.
     *
     * Separate from `show` because it is what a seller polls: availability
     * changes with every sale, and re-fetching the whole event to learn that
     * twelve seats are left is a lot of payload for one integer.
     */
    public function ticketTypes(Event $event): AnonymousResourceCollection
    {
        $this->authorize('view', $event);

        return TicketTypeResource::collection($event->ticketTypes()->get());
    }
}
