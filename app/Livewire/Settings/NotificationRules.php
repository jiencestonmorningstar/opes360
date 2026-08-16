<?php

namespace App\Livewire\Settings;

use App\Models\NotificationDelivery;
use App\Models\NotificationRule;
use App\Support\DomainEvents;
use App\Support\NotificationCategories;
use App\Support\NotificationChannels;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Who gets told what, and the log of whether they did.
 *
 * The log is half of why this screen exists. The other half — writing a rule —
 * could have waited; the log could not. The question this feature generates is
 * "nobody told me", and the only useful answer distinguishes four different
 * things: no rule matched, the rule reached nobody, the recipient had it muted,
 * or it genuinely failed to send. Each has a different fix, and without the log
 * an administrator cannot tell them apart.
 *
 * Behind `workflows.manage` — the Owner and the Administrator. Anybody who can
 * write a rule can point every approval alert in the business at themselves,
 * or away from the person who was supposed to sign. That is not a preference.
 */
class NotificationRules extends Component
{
    use AuthorizesRequests;

    public string $name = '';

    public string $event = 'workflow.step.assigned';

    public string $category = 'approvals';

    public string $severity = 'normal';

    public string $title = '';

    public string $body = '';

    public string $url = '';

    public string $recipientMode = 'role';

    public string $recipientValue = '';

    /** @var array<int, string> */
    public array $channels = ['in_app'];

    public int $dedupeMinutes = 60;

    public ?string $editingId = null;

    public function mount(): void
    {
        $this->authorize('workflows.manage');
    }

    public function save(): void
    {
        $this->authorize('workflows.manage');

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'event' => ['required', Rule::in(DomainEvents::all())],
            'category' => ['required', Rule::in(NotificationCategories::keys())],
            'severity' => ['required', Rule::in(array_keys(NotificationCategories::SEVERITIES))],
            'title' => ['required', 'string', 'max:160'],
            'body' => ['nullable', 'string', 'max:500'],
            'url' => ['nullable', 'string', 'max:200'],
            'recipientMode' => ['required', Rule::in(array_keys(NotificationRule::RECIPIENT_MODES))],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => [Rule::in(array_keys(NotificationChannels::all()))],
            'dedupeMinutes' => ['integer', 'min:0', 'max:10080'],
        ], [
            'title.required' => 'What should the message say?',
            'channels.required' => 'Choose at least one way to reach them.',
        ]);

        $attributes = [
            'name' => $this->name,
            'event' => $this->event,
            'category' => $this->category,
            'severity' => $this->severity,
            'title' => $this->title,
            'body' => $this->body ?: null,
            'url' => $this->url ?: null,
            'recipients' => [array_filter([
                'mode' => $this->recipientMode,
                'value' => $this->recipientValue ?: null,
            ], fn ($v) => $v !== null)],
            'channels' => array_values(array_unique($this->channels)),
            'dedupe_minutes' => $this->dedupeMinutes,
        ];

        if ($this->editingId !== null) {
            // Scoped by the tenant global scope, so an id from another
            // business simply does not resolve.
            NotificationRule::findOrFail($this->editingId)->forceFill($attributes)->save();

            session()->flash('status', 'That rule has been updated.');
            $this->cancelEditing();

            return;
        }

        NotificationRule::create($attributes + [
            'is_active' => true,
            'created_by' => auth()->id(),
        ]);

        session()->flash('status', 'That rule is now live.');
        $this->resetForm();
    }

    public function edit(string $id): void
    {
        $this->authorize('workflows.manage');

        $rule = NotificationRule::findOrFail($id);
        $recipient = ($rule->recipients[0] ?? []);

        $this->editingId = $rule->id;
        $this->name = $rule->name;
        $this->event = $rule->event;
        $this->category = $rule->category;
        $this->severity = $rule->severity;
        $this->title = $rule->title;
        $this->body = (string) $rule->body;
        $this->url = (string) $rule->url;
        $this->recipientMode = $recipient['mode'] ?? 'role';
        $this->recipientValue = (string) ($recipient['value'] ?? '');
        $this->channels = $rule->channels ?? ['in_app'];
        $this->dedupeMinutes = (int) $rule->dedupe_minutes;
    }

    public function cancelEditing(): void
    {
        $this->editingId = null;
        $this->resetForm();
    }

    /**
     * Pause rather than delete.
     *
     * A rule that stopped firing is the thing people need to look at, and a
     * deleted one leaves nothing to look at.
     */
    public function toggle(string $id): void
    {
        $this->authorize('workflows.manage');

        $rule = NotificationRule::findOrFail($id);
        $rule->forceFill(['is_active' => ! $rule->is_active])->save();
    }

    public function delete(string $id): void
    {
        $this->authorize('workflows.manage');

        NotificationRule::findOrFail($id)->delete();

        session()->flash('status', 'That rule has been removed.');
    }

    protected function resetForm(): void
    {
        $this->reset(['name', 'title', 'body', 'url', 'recipientValue']);
    }

    public function render(): View
    {
        return view('livewire.settings.notification-rules', [
            'rules' => NotificationRule::query()->latest()->get(),
            'deliveries' => NotificationDelivery::query()
                ->with('user')
                ->latest()
                ->limit(50)
                ->get(),
            'eventOptions' => DomainEvents::CATALOGUE,
            'categories' => NotificationCategories::all(),
            'severities' => NotificationCategories::SEVERITIES,
            'channelOptions' => NotificationChannels::all(),
            'recipientModes' => NotificationRule::RECIPIENT_MODES,
        ]);
    }
}
