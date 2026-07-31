<?php

namespace App\Livewire\Events;

use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Create or edit an event and its ticket types in one screen.
 *
 * Ticket types are edited as rows and written on save. A type that has sold
 * tickets can be renamed or repriced (future sales only — sold tickets keep
 * their frozen price) but not deleted: deleting it would orphan the tickets
 * already in people's hands.
 */
class Manage extends Component
{
    use AuthorizesRequests;

    public ?Event $event = null;

    public string $title = '';

    public string $description = '';

    public string $venue = '';

    public string $startsAt = '';

    public string $endsAt = '';

    /** @var array<int, array{id: ?string, name: string, price: string, quantity: string, sold: int}> */
    public array $types = [];

    public function mount(?Event $event = null): void
    {
        $this->event = $event?->exists ? $event : null;

        if ($this->event) {
            $this->authorize('update', $this->event);

            $this->title = $this->event->title;
            $this->description = (string) $this->event->description;
            $this->venue = (string) $this->event->venue;
            $this->startsAt = $this->event->starts_at->format('Y-m-d\TH:i');
            $this->endsAt = $this->event->ends_at?->format('Y-m-d\TH:i') ?? '';

            $this->types = $this->event->ticketTypes->map(fn (TicketType $type) => [
                'id' => $type->id,
                'name' => $type->name,
                'price' => (string) $type->price,
                'quantity' => $type->quantity === null ? '' : (string) $type->quantity,
                'sold' => $type->sold,
            ])->all();
        } else {
            $this->authorize('create', Event::class);

            $this->types = [$this->blankType()];
        }
    }

    protected function blankType(): array
    {
        return ['id' => null, 'name' => '', 'price' => '0', 'quantity' => '', 'sold' => 0];
    }

    public function addType(): void
    {
        $this->types[] = $this->blankType();
    }

    public function removeType(int $index): void
    {
        if (! isset($this->types[$index])) {
            return;
        }

        // Sold tickets point at this row; it stays.
        if (($this->types[$index]['sold'] ?? 0) > 0) {
            return;
        }

        $removed = $this->types[$index];
        unset($this->types[$index]);
        $this->types = array_values($this->types);

        if ($removed['id'] !== null && $this->event !== null) {
            TicketType::query()
                ->whereKey($removed['id'])
                ->where('event_id', $this->event->id)
                ->where('sold', 0)
                ->delete();
        }
    }

    public function save()
    {
        $data = $this->validate([
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'venue' => ['nullable', 'string', 'max:150'],
            'startsAt' => ['required', 'date'],
            'endsAt' => ['nullable', 'date', 'after:startsAt'],
            'types' => ['required', 'array', 'min:1'],
            'types.*.name' => ['required', 'string', 'max:100'],
            'types.*.price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'types.*.quantity' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ], [], [
            'startsAt' => 'start time',
            'endsAt' => 'end time',
            'types.*.name' => 'ticket name',
            'types.*.price' => 'ticket price',
            'types.*.quantity' => 'ticket quantity',
        ]);

        $event = DB::transaction(function () use ($data) {
            $attributes = [
                'title' => $data['title'],
                'description' => $data['description'] ?: null,
                'venue' => $data['venue'] ?: null,
                'starts_at' => $data['startsAt'],
                'ends_at' => $data['endsAt'] ?: null,
            ];

            if ($this->event) {
                $this->event->update($attributes);
                $event = $this->event;
            } else {
                $event = Event::create($attributes + [
                    'status' => 'draft',
                    'share_token' => Event::newShareToken(),
                    'created_by' => auth()->id(),
                ]);
            }

            foreach (array_values($data['types']) as $sort => $row) {
                $attributes = [
                    'name' => $row['name'],
                    'price' => $row['price'],
                    'quantity' => $row['quantity'] === '' || $row['quantity'] === null ? null : (int) $row['quantity'],
                    'sort' => $sort,
                ];

                $existing = ($row['id'] ?? null) !== null
                    ? $event->ticketTypes()->whereKey($row['id'])->first()
                    : null;

                if ($existing) {
                    // Capacity can never drop below what is already sold —
                    // that would make the remaining() maths go negative.
                    if ($attributes['quantity'] !== null && $attributes['quantity'] < $existing->sold) {
                        $attributes['quantity'] = $existing->sold;
                    }

                    $existing->update($attributes);
                } else {
                    $event->ticketTypes()->create($attributes + ['company_id' => $event->company_id]);
                }
            }

            return $event;
        });

        return $this->redirectRoute('events.show', $event);
    }

    public function render(): View
    {
        return view('livewire.events.manage')->layout('components.layouts.app', [
            'title' => $this->event ? 'Edit event' : 'New event',
            'active' => 'events',
        ]);
    }
}
