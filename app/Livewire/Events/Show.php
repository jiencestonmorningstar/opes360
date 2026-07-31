<?php

namespace App\Livewire\Events;

use App\Models\Event;
use App\Models\Ticket;
use App\Services\QrCodes;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * One event: the numbers that matter on the day, the attendee list, and the
 * share link that sells it.
 */
class Show extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public Event $event;

    #[Url]
    public string $filter = 'all';

    public function mount(Event $event): void
    {
        $this->authorize('view', $event);

        $this->event = $event;
    }

    public function publish(): void
    {
        $this->authorize('update', $this->event);

        if ($this->event->ticketTypes()->count() === 0) {
            $this->addError('event', 'Add at least one ticket type before publishing.');

            return;
        }

        $this->event->update(['status' => 'published']);
        $this->event->refresh();
    }

    public function unpublish(): void
    {
        $this->authorize('update', $this->event);

        $this->event->update(['status' => 'draft']);
        $this->event->refresh();
    }

    public function cancel(): void
    {
        $this->authorize('update', $this->event);

        $this->event->update(['status' => 'cancelled']);
        $this->event->refresh();
    }

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['all', 'issued', 'checked_in', 'void'], true) ? $filter : 'all';
        $this->resetPage();
    }

    public function checkIn(string $ticketId): void
    {
        $ticket = $this->event->tickets()->whereKey($ticketId)->firstOrFail();

        $this->authorize('checkIn', $ticket);

        if (! $ticket->isVoid() && ! $ticket->isCheckedIn()) {
            $ticket->forceFill([
                'status' => 'checked_in',
                'checked_in_at' => now(),
                'checked_in_by' => auth()->id(),
            ])->save();
        }
    }

    public function voidTicket(string $ticketId): void
    {
        $ticket = $this->event->tickets()->whereKey($ticketId)->firstOrFail();

        $this->authorize('void', $ticket);

        if (! $ticket->isVoid()) {
            $ticket->forceFill(['status' => 'void'])->save();
        }
    }

    public function togglePaid(string $ticketId): void
    {
        $this->authorize('update', $this->event);

        $ticket = $this->event->tickets()->whereKey($ticketId)->firstOrFail();

        $ticket->forceFill(['paid_at' => $ticket->paid_at === null ? now() : null])->save();
    }

    public function render(QrCodes $qr): View
    {
        $tickets = $this->event->tickets();

        $stats = [
            'sold' => (clone $tickets)->where('status', '!=', 'void')->count(),
            'checked_in' => (clone $tickets)->where('status', 'checked_in')->count(),
            'revenue' => (clone $tickets)->where('status', '!=', 'void')->whereNotNull('paid_at')->sum('price'),
            'unpaid' => (clone $tickets)->where('status', '!=', 'void')->whereNull('paid_at')->where('price', '>', 0)->count(),
        ];

        $list = $this->event->tickets()->with('ticketType')->latest();

        if ($this->filter !== 'all') {
            $list->where('status', $this->filter);
        }

        return view('livewire.events.show', [
            'types' => $this->event->ticketTypes,
            'stats' => $stats,
            'tickets' => $list->paginate(25),
            'shareQr' => $this->event->isPublished() ? $qr->svg($this->event->publicUrl()) : null,
        ])->layout('components.layouts.app', [
            'title' => $this->event->title,
            'active' => 'events',
        ]);
    }
}
