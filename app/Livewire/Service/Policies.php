<?php

namespace App\Livewire\Service;

use App\Models\ServiceSlaPolicy;
use App\Models\ServiceTicket;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * What the business has promised, and when it is open to keep it.
 *
 * Gated on `service.manage-sla` at every entry point, including the render —
 * whoever can edit a policy can widen every promise the business has made
 * until nothing ever breaches again, which makes the whole measure
 * decorative. A manager being measured on the board must not be able to move
 * the board.
 */
class Policies extends Component
{
    /** The weekday keys BusinessHours reads, in the order a human expects. */
    public const DAYS = [
        'mon' => 'Monday',
        'tue' => 'Tuesday',
        'wed' => 'Wednesday',
        'thu' => 'Thursday',
        'fri' => 'Friday',
        'sat' => 'Saturday',
        'sun' => 'Sunday',
    ];

    #[Url]
    public ?string $policyId = null;

    // ── Policy form ─────────────────────────────────────────────────────
    public bool $creating = false;

    public string $name = '';

    public string $clock = 'business';

    public string $timezone = 'UTC';

    public bool $isDefault = false;

    // ── Calendar ────────────────────────────────────────────────────────
    /** @var array<string, array<int, array{0: string, 1: string}>> */
    public array $hours = [];

    /** One date per line, the way somebody with a wall calendar writes them. */
    public string $holidays = '';

    // ── Targets ─────────────────────────────────────────────────────────
    /** @var array<string, array{response: string, resolution: string}> */
    public array $targets = [];

    public function mount(): void
    {
        Gate::authorize('service.manage-sla');

        $this->policyId ??= ServiceSlaPolicy::query()->orderByDesc('is_default')->value('id');
        $this->load();
    }

    public function select(string $id): void
    {
        $this->policyId = $id;
        $this->creating = false;
        $this->load();
    }

    public function startNew(): void
    {
        Gate::authorize('service.manage-sla');

        $this->creating = true;
        $this->name = '';
        $this->clock = 'business';
        $this->timezone = 'UTC';
        $this->isDefault = false;
    }

    public function create(): void
    {
        Gate::authorize('service.manage-sla');

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'timezone' => ['required', 'string', 'max:64'],
            'clock' => ['required', 'in:business,calendar'],
        ]);

        $policy = ServiceSlaPolicy::create([
            'name' => $this->name,
            'clock' => $this->clock,
            'timezone' => $this->timezone,
            'is_default' => $this->isDefault,
            'is_active' => true,
            // A policy with no windows at all would be a promise nobody can
            // keep, so a new one starts on the ordinary working week and is
            // edited from there.
            'business_hours' => [
                'mon' => [['08:00', '17:00']],
                'tue' => [['08:00', '17:00']],
                'wed' => [['08:00', '17:00']],
                'thu' => [['08:00', '17:00']],
                'fri' => [['08:00', '17:00']],
            ],
            'holidays' => [],
        ]);

        $this->policyId = $policy->id;
        $this->creating = false;
        $this->load();
        $this->dispatch('toast', message: 'Policy added.');
    }

    public function saveCalendar(): void
    {
        Gate::authorize('service.manage-sla');

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'timezone' => ['required', 'string', 'max:64'],
            'clock' => ['required', 'in:business,calendar'],
        ]);

        $policy = $this->policy();

        if ($policy === null) {
            return;
        }

        $windows = [];

        foreach (self::DAYS as $day => $label) {
            $open = trim((string) ($this->hours[$day][0] ?? ''));
            $close = trim((string) ($this->hours[$day][1] ?? ''));

            if ($open !== '' && $close !== '') {
                $windows[$day] = [[$open, $close]];
            }
        }

        $policy->update([
            'name' => $this->name,
            'clock' => $this->clock,
            'timezone' => $this->timezone,
            'is_default' => $this->isDefault,
            'business_hours' => $windows,
            'holidays' => collect(preg_split('/\r\n|\r|\n/', $this->holidays))
                ->map(fn (string $line) => trim($line))
                ->filter()
                ->values()
                ->all(),
        ]);

        $this->load();

        // Said out loud rather than left to be discovered: tickets already
        // raised keep the deadlines that were worked out under the old
        // calendar. Nothing recomputes them.
        $this->dispatch('toast', message: 'Saved. Tickets already open keep the deadlines they were given.');
    }

    public function saveTargets(): void
    {
        Gate::authorize('service.manage-sla');

        $policy = $this->policy();

        if ($policy === null) {
            return;
        }

        foreach (array_keys(ServiceTicket::PRIORITIES) as $priority) {
            $response = trim((string) ($this->targets[$priority]['response'] ?? ''));
            $resolution = trim((string) ($this->targets[$priority]['resolution'] ?? ''));

            $target = $policy->targets()->firstOrNew(['priority' => $priority]);

            $target->fill([
                'company_id' => $policy->company_id,
                'sla_policy_id' => $policy->id,
                // Blank means "we promised nothing about this", not zero.
                // Storing zero would put a breach on the board the moment the
                // ticket was raised, for a promise nobody made.
                'response_minutes' => $response === '' ? null : (int) $response,
                'resolution_minutes' => $resolution === '' ? null : (int) $resolution,
            ])->save();
        }

        $this->load();
        $this->dispatch('toast', message: 'Targets saved.');
    }

    public function render(): View
    {
        Gate::authorize('service.manage-sla');

        return view('livewire.service.policies', [
            'policies' => ServiceSlaPolicy::query()->withCount('tickets')->orderBy('name')->get(),
            'policy' => $this->policy(),
            'priorities' => ServiceTicket::PRIORITIES,
            'days' => self::DAYS,
        ])->layout('components.layouts.app', ['title' => 'Service promises', 'active' => 'service']);
    }

    protected function policy(): ?ServiceSlaPolicy
    {
        return $this->policyId === null
            ? null
            : ServiceSlaPolicy::with('targets')->find($this->policyId);
    }

    /** Pull the chosen policy into the form fields. */
    protected function load(): void
    {
        $policy = $this->policy();

        if ($policy === null) {
            return;
        }

        $this->name = (string) $policy->name;
        $this->clock = (string) ($policy->clock ?: 'business');
        $this->timezone = (string) ($policy->timezone ?: 'UTC');
        $this->isDefault = (bool) $policy->is_default;
        $this->holidays = implode("\n", $policy->holidays ?? []);

        $this->hours = [];

        foreach (array_keys(self::DAYS) as $day) {
            // Only the first window of the day is editable here. A lunch
            // break is two windows and the backend handles it; splitting one
            // on this screen is a job for whoever asks for it.
            $window = ($policy->business_hours[$day] ?? [])[0] ?? null;

            $this->hours[$day] = [$window[0] ?? '', $window[1] ?? ''];
        }

        $this->targets = [];

        foreach (array_keys(ServiceTicket::PRIORITIES) as $priority) {
            $target = $policy->targets->firstWhere('priority', $priority);

            $this->targets[$priority] = [
                'response' => $target?->response_minutes === null ? '' : (string) $target->response_minutes,
                'resolution' => $target?->resolution_minutes === null ? '' : (string) $target->resolution_minutes,
            ];
        }
    }
}
