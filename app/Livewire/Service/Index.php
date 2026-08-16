<?php

namespace App\Livewire\Service;

use App\Models\Contact;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Services\Service\SlaBoard;
use App\Services\Service\TicketDesk;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/**
 * The queue, with what is going wrong at the top of it.
 *
 * A desk manager does not open this to browse a list — they open it to find
 * out what has already been missed and what is about to be. So the board
 * comes first and the queue second, rather than an SLA column somebody has to
 * scan four hundred rows to read.
 *
 * Every button here goes through TicketDesk. A screen that wrote `status` or
 * `priority` itself would move a deadline with no event row behind it, and the
 * first anyone would hear of it is a customer arguing about a breach.
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $priority = '';

    #[Url]
    public string $assignee = '';

    // ── Raise a ticket ──────────────────────────────────────────────────
    public bool $raising = false;

    public ?string $contactId = null;

    public string $subject = '';

    public string $description = '';

    public string $newPriority = 'normal';

    public string $channel = 'phone';

    public string $category = '';

    public ?string $newAssignee = null;

    // ── Inline row actions ──────────────────────────────────────────────
    /** The ticket whose assign/priority strip is open, if any. */
    public ?string $acting = null;

    public ?string $actingAssignee = null;

    public string $actingPriority = 'normal';

    public function updated(string $property): void
    {
        // Any change of filter puts the reader back on page one; otherwise a
        // narrower filter can land them on an empty page three.
        if (in_array($property, ['search', 'status', 'priority', 'assignee'], true)) {
            $this->resetPage();
        }
    }

    public function raise(): void
    {
        Gate::authorize('service.create');

        $this->validate([
            'subject' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'contactId' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:80'],
        ]);

        try {
            app(TicketDesk::class)->open([
                'contact_id' => $this->contactId ?: null,
                'subject' => $this->subject,
                'description' => $this->description ?: null,
                'priority' => $this->newPriority,
                'channel' => $this->channel,
                'category' => $this->category ?: null,
                'assignee_id' => $this->newAssignee === null || $this->newAssignee === ''
                    ? null
                    : (int) $this->newAssignee,
            ], auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('subject', $e->getMessage());

            return;
        }

        $this->reset('raising', 'subject', 'description', 'category', 'contactId', 'newAssignee');
        $this->newPriority = 'normal';
        $this->dispatch('toast', message: 'Ticket raised.');
    }

    public function startAction(string $id): void
    {
        $ticket = ServiceTicket::findOrFail($id);

        $this->acting = $ticket->id;
        $this->actingAssignee = $ticket->assignee_id === null ? null : (string) $ticket->assignee_id;
        $this->actingPriority = $ticket->priority;
    }

    public function assign(): void
    {
        Gate::authorize('service.assign');

        $ticket = ServiceTicket::findOrFail($this->acting);

        $to = $this->actingAssignee === null || $this->actingAssignee === ''
            ? null
            : User::find((int) $this->actingAssignee);

        try {
            app(TicketDesk::class)->assign($ticket, $to, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('acting', $e->getMessage());

            return;
        }

        $this->acting = null;
        $this->dispatch('toast', message: 'Ticket assigned.');
    }

    public function reprioritise(): void
    {
        Gate::authorize('service.update');

        $ticket = ServiceTicket::findOrFail($this->acting);

        try {
            app(TicketDesk::class)->changePriority($ticket, $this->actingPriority, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('acting', $e->getMessage());

            return;
        }

        $this->acting = null;
        $this->dispatch('toast', message: 'Priority changed.');
    }

    /**
     * How a row's promise is doing, in words.
     *
     * Deliberately not a countdown. The deadline is an instant the backend
     * already worked out on the policy's working calendar, and a ticking
     * "2h 14m left" would be counting wall-clock hours the business is not
     * open for.
     *
     * @return array{label: string, tone: string}
     */
    public function slaState(ServiceTicket $ticket): array
    {
        if ($ticket->hasBreachedResponse()) {
            return ['label' => 'Response breached', 'tone' => 'bad'];
        }

        if ($ticket->hasBreachedResolution()) {
            return ['label' => 'Resolution breached', 'tone' => 'bad'];
        }

        if ($ticket->isPaused()) {
            return ['label' => 'Clock stopped — waiting on customer', 'tone' => 'warn'];
        }

        if ($ticket->isSettled()) {
            return ['label' => 'Met', 'tone' => 'ok'];
        }

        if ($ticket->first_response_at === null && $ticket->response_due_at !== null) {
            return ['label' => 'Answer by '.$ticket->response_due_at->toDayDateTimeString(), 'tone' => 'ok'];
        }

        if ($ticket->resolution_due_at !== null) {
            return ['label' => 'Fix by '.$ticket->resolution_due_at->toDayDateTimeString(), 'tone' => 'ok'];
        }

        return ['label' => 'No promise attached', 'tone' => 'muted'];
    }

    public function render(): View
    {
        Gate::authorize('service.view');

        $board = app(SlaBoard::class);
        $company = app(CurrentCompany::class)->get();

        return view('livewire.service.index', [
            'summary' => $board->summary(),
            'breaching' => $board->breaching()->load(['contact', 'assignee']),
            // Four working hours, walked through each policy's own calendar —
            // so a Friday afternoon does not sweep Monday's queue onto the
            // warning list.
            'atRisk' => $board->atRisk(240)->load(['contact', 'assignee']),
            'tickets' => $this->query()->paginate(20),
            'people' => $company?->users()->orderBy('name')->get(['users.id', 'users.name']) ?? collect(),
            'customers' => Contact::query()->orderBy('name')->limit(200)->get(['id', 'name']),
            'statuses' => ServiceTicket::STATUSES,
            'priorities' => ServiceTicket::PRIORITIES,
            'channels' => ServiceTicket::CHANNELS,
        ])->layout('components.layouts.app', ['title' => 'Service desk', 'active' => 'service']);
    }

    protected function query(): Builder
    {
        return ServiceTicket::query()
            ->with(['contact', 'assignee', 'policy'])
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->priority !== '', fn (Builder $q) => $q->where('priority', $this->priority))
            ->when($this->assignee === 'unassigned', fn (Builder $q) => $q->whereNull('assignee_id'))
            ->when(
                $this->assignee !== '' && $this->assignee !== 'unassigned',
                fn (Builder $q) => $q->where('assignee_id', (int) $this->assignee)
            )
            ->when($this->search !== '', function (Builder $query) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%';

                $query->where(fn (Builder $q) => $q
                    ->where('subject', 'like', $term)
                    ->orWhere('reference', 'like', $term));
            })
            // The oldest promise first: whatever is closest to being missed is
            // what somebody should pick up next.
            ->orderByRaw('resolution_due_at IS NULL, resolution_due_at')
            ->latest('opened_at');
    }
}
