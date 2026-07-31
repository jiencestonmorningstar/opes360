<?php

namespace App\Livewire\Events;

use App\Models\Event;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Opes Events — the list. Upcoming first: the event that still needs selling
 * matters more than the one that already happened.
 */
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $query = Event::query()
            ->withCount(['tickets as sold_count' => fn ($q) => $q->where('status', '!=', 'void')])
            // Bound, not now() in SQL: the suite runs on SQLite, which has no
            // such function, and the two databases must behave identically.
            ->orderByRaw('(starts_at >= ?) desc', [now()])
            ->orderBy('starts_at');

        if (($term = trim($this->search)) !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
            $query->where(fn ($q) => $q->where('title', 'like', $like)->orWhere('venue', 'like', $like));
        }

        return view('livewire.events.index', [
            'events' => $query->paginate(12),
        ])->layout('components.layouts.app', [
            'title' => 'Events',
            'active' => 'events',
        ]);
    }
}
