<?php

namespace App\Livewire\Leads;

use App\Models\CrmActivity;
use App\Models\Deal;
use App\Models\Lead;
use App\Services\LeadFunnel;
use App\Support\SalesForecast;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * The leads register: who might become a customer, what somebody plans to do
 * about it, and what the pipeline is likely worth.
 *
 * Three tabs on one screen, in the Movements style, because they are three
 * views of the same day's work — the leads to chase, the calls due, and the
 * number the chasing adds up to. Terminal moves go through LeadFunnel, the
 * same rule as the deals board: two places deciding what "converted" means is
 * how a lead ends up converted with no contact to show for it.
 */
class Index extends Component
{
    use AuthorizesRequests;

    /** How many leads the register shows at once; the count says the rest exist. */
    public const LIST_LIMIT = 100;

    #[Url]
    public string $tab = 'leads'; // leads|activity|forecast

    #[Url(as: 'q')]
    public string $search = '';

    /** Closed leads hidden by default: a funnel is about work in progress. */
    #[Url]
    public bool $showClosed = false;

    // ── Add-lead form ───────────────────────────────────────────────────
    public bool $adding = false;

    public string $leadName = '';

    public string $leadCompany = '';

    public string $leadPhone = '';

    public string $leadEmail = '';

    public string $leadSource = '';

    public string $leadNotes = '';

    // ── Convert form ────────────────────────────────────────────────────
    public ?string $converting = null;

    public bool $withDeal = false;

    public string $dealTitle = '';

    public string $dealValue = '';

    public string $dealCloseOn = '';

    // ── Lose form ───────────────────────────────────────────────────────
    public ?string $losing = null;

    public string $lostReason = '';

    // ── Activity form ───────────────────────────────────────────────────
    public string $actSubject = '';

    public string $actKind = 'call';

    public string $actSummary = '';

    public string $actDueAt = '';

    public function addLead(): void
    {
        $this->authorize('create', Lead::class);

        $this->validate([
            'leadName' => ['required', 'string', 'max:160'],
            'leadCompany' => ['nullable', 'string', 'max:160'],
            'leadPhone' => ['nullable', 'string', 'max:40'],
            'leadEmail' => ['nullable', 'email', 'max:160'],
            'leadSource' => ['nullable', 'string', 'max:120'],
            'leadNotes' => ['nullable', 'string', 'max:2000'],
        ]);

        app(LeadFunnel::class)->create([
            'name' => $this->leadName,
            'company_name' => $this->leadCompany ?: null,
            'phone' => $this->leadPhone ?: null,
            'email' => $this->leadEmail ?: null,
            'source' => $this->leadSource ?: null,
            'notes' => $this->leadNotes ?: null,
        ], auth()->user());

        $this->reset('adding', 'leadName', 'leadCompany', 'leadPhone', 'leadEmail', 'leadSource', 'leadNotes');
        $this->dispatch('toast', message: 'Lead added.');
    }

    public function move(string $id, string $status): void
    {
        $lead = Lead::findOrFail($id);

        $this->authorize('update', $lead);

        try {
            app(LeadFunnel::class)->moveTo($lead, $status);
        } catch (InvalidArgumentException $e) {
            $this->addError('leads', $e->getMessage());
        }
    }

    public function startConvert(string $id): void
    {
        $lead = Lead::findOrFail($id);

        $this->authorize('update', $lead);

        $this->converting = $lead->id;
        $this->losing = null;
        $this->withDeal = false;
        $this->dealTitle = $lead->name;
        $this->dealValue = '';
        $this->dealCloseOn = '';
    }

    public function convert(): void
    {
        $lead = Lead::findOrFail($this->converting);

        $this->authorize('update', $lead);

        if ($this->withDeal) {
            $this->validate([
                'dealTitle' => ['required', 'string', 'max:160'],
                'dealValue' => ['required', 'numeric', 'min:0'],
                'dealCloseOn' => ['nullable', 'date'],
            ]);
        }

        try {
            app(LeadFunnel::class)->convert($lead, auth()->user(), $this->withDeal ? [
                'title' => $this->dealTitle,
                'value' => $this->dealValue,
                'expected_close_on' => $this->dealCloseOn ?: null,
            ] : []);
        } catch (RuntimeException $e) {
            $this->addError('converting', $e->getMessage());

            return;
        }

        $this->converting = null;
        $this->dispatch('toast', message: "{$lead->name} is now a customer.");
    }

    public function startLose(string $id): void
    {
        $lead = Lead::findOrFail($id);

        $this->authorize('update', $lead);

        $this->losing = $lead->id;
        $this->converting = null;
        $this->lostReason = '';
    }

    public function lose(): void
    {
        $lead = Lead::findOrFail($this->losing);

        $this->authorize('update', $lead);

        // The reason is the point: it is the only data a dead lead produces.
        $this->validate(['lostReason' => ['required', 'string', 'max:255']]);

        try {
            app(LeadFunnel::class)->lose($lead, $this->lostReason);
        } catch (RuntimeException $e) {
            $this->addError('losing', $e->getMessage());

            return;
        }

        $this->losing = null;
        $this->dispatch('toast', message: 'Lead marked as lost.');
    }

    public function logActivity(): void
    {
        $this->authorize('create', CrmActivity::class);

        $this->validate([
            'actSubject' => ['required', 'string'],
            'actKind' => ['required', 'string', 'in:'.implode(',', array_keys(CrmActivity::KINDS))],
            'actSummary' => ['required', 'string', 'max:255'],
            'actDueAt' => ['nullable', 'date'],
        ]);

        // "deal:{id}" or "lead:{id}" — resolved through the scoped models, so
        // an id from another company simply does not exist here.
        [$type, $id] = array_pad(explode(':', $this->actSubject, 2), 2, '');

        $subject = match ($type) {
            'deal' => Deal::findOrFail($id),
            'lead' => Lead::findOrFail($id),
            default => throw new InvalidArgumentException('Pick a deal or a lead.'),
        };

        CrmActivity::log(
            $subject,
            $this->actKind,
            $this->actSummary,
            auth()->user(),
            dueAt: $this->actDueAt !== '' ? Carbon::parse($this->actDueAt) : null,
        );

        $this->reset('actSummary', 'actDueAt');
        $this->dispatch('toast', message: 'Logged.');
    }

    public function completeActivity(string $id): void
    {
        $activity = CrmActivity::findOrFail($id);

        $this->authorize('update', $activity);

        $activity->complete();
        $this->dispatch('toast', message: 'Marked as done.');
    }

    public function render(): View
    {
        $this->authorize('viewAny', Lead::class);

        $forecast = new SalesForecast;

        /*
         * The register is capped the same way the deals board is: the page
         * shows the most recent hundred, the header count is the real total
         * from a COUNT, and search narrows the query itself. Loading every
         * open lead on every Livewire render is invisible at fifty leads and
         * a multi-second stall at ten thousand.
         */
        $leadsTotal = $this->leadsQuery()->count();

        return view('livewire.leads.index', [
            'leads' => $this->leadsQuery()->limit(self::LIST_LIMIT)->get(),
            'leadsTotal' => $leadsTotal,
            'openCount' => Lead::query()->open()->count(),
            // Overdue first: the whole reason to open the activity tab.
            'overdueActivities' => CrmActivity::query()->overdue()
                ->with(['subject', 'user'])->orderBy('due_at')->get(),
            'plannedActivities' => CrmActivity::query()->planned()->whereNotNull('due_at')
                ->where('due_at', '>=', now())
                ->with(['subject', 'user'])->orderBy('due_at')->limit(25)->get(),
            'recentActivities' => CrmActivity::query()->whereNotNull('done_at')
                ->with(['subject', 'user'])->latest('done_at')->limit(10)->get(),
            'untouched' => $forecast->untouchedDeals(7)->with('contact')->get(),
            'forecast' => $forecast->build(),
            'openDeals' => Deal::query()->open()->orderBy('title')->get(['id', 'title']),
            'openLeads' => Lead::query()->open()->orderBy('name')->get(['id', 'name']),
        ])->layout('components.layouts.app', ['title' => 'Leads', 'active' => 'deals']);
    }

    protected function leadsQuery(): Builder
    {
        return Lead::query()
            ->with(['assignee', 'contact', 'deal'])
            ->when(! $this->showClosed, fn (Builder $q) => $q->open())
            ->when($this->search !== '', function (Builder $query) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%';

                $query->where(function (Builder $q) use ($term) {
                    $q->where('name', 'like', $term)
                        ->orWhere('company_name', 'like', $term)
                        ->orWhere('source', 'like', $term);
                });
            })
            ->latest();
    }
}
