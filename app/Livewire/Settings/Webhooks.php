<?php

namespace App\Livewire\Settings;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Rules\PublicHttpsUrl;
use App\Support\WebhookEvents;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Where a business points its webhooks, and sees whether they arrived.
 *
 * The delivery log is half of why this screen exists. The other half — adding
 * an endpoint — could plausibly have stayed API-only, but the log could not:
 * the question this feature generates is "your system says it sent it, mine
 * never got it", and answering that by asking the business to make an API call
 * is answering it by not answering it. What arrived, what it answered, and
 * what we are about to try next all have to be visible to the person who is
 * actually on the phone to their integrator.
 *
 * Behind `webhooks.manage`, which is the Owner and the Administrator. Anybody
 * who can add an endpoint can subscribe to `payment.recorded` and take a copy
 * of every sale the business makes; that is not a settings toggle.
 *
 * The secret is shown once, on creation, exactly as an API token is. Unlike a
 * token we could show it again — we store it in the clear, because the
 * receiving end needs the same bytes to check the signature — but a secret
 * that is idly re-readable on a settings page is a secret that ends up pasted
 * into a chat window. Losing it means replacing it, which is a small cost paid
 * rarely.
 */
class Webhooks extends Component
{
    use AuthorizesRequests;

    public string $url = '';

    public string $description = '';

    /** @var array<int, string> */
    public array $events = [WebhookEvents::DOCUMENT_ISSUED];

    /** Shown once, immediately after creating, then never again. */
    public ?string $plainTextSecret = null;

    /** The endpoint being edited, if any. */
    public ?string $editingId = null;

    public function mount(): void
    {
        $this->authorize('webhooks.manage');
    }

    public function save(): void
    {
        $this->authorize('webhooks.manage');

        $this->validate([
            'url' => ['required', 'url:https', 'max:500', new PublicHttpsUrl],
            'description' => ['nullable', 'string', 'max:180'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(WebhookEvents::all())],
        ], [
            'url.required' => 'Where should we send these?',
            'url.url' => 'That does not look like an https address.',
            'events.required' => 'Choose at least one thing to be told about.',
        ]);

        $attributes = [
            'url' => $this->url,
            'description' => $this->description ?: null,
            'events' => array_values(array_unique($this->events)),
        ];

        if ($this->editingId !== null) {
            // Scoped by the tenant global scope, so an id from another
            // business simply does not resolve.
            WebhookEndpoint::findOrFail($this->editingId)->forceFill($attributes)->save();

            session()->flash('status', 'That endpoint has been updated.');
            $this->cancelEditing();

            return;
        }

        $endpoint = WebhookEndpoint::create($attributes + [
            'secret' => WebhookEndpoint::newSecret(),
            'is_active' => true,
        ]);

        $this->plainTextSecret = $endpoint->secret;
        $this->resetForm();
    }

    public function edit(string $id): void
    {
        $this->authorize('webhooks.manage');

        $endpoint = WebhookEndpoint::findOrFail($id);

        $this->editingId = $endpoint->id;
        $this->url = $endpoint->url;
        $this->description = (string) $endpoint->description;
        $this->events = $endpoint->events ?? [];
    }

    public function cancelEditing(): void
    {
        $this->editingId = null;
        $this->resetForm();
    }

    /**
     * Pause or resume one.
     *
     * Resuming clears the failure count as well as the flag: an endpoint that
     * had been switched off automatically and has since been fixed would
     * otherwise switch itself off again on its very next hiccup, which reads
     * as the fix not having worked.
     */
    public function toggle(string $id): void
    {
        $this->authorize('webhooks.manage');

        $endpoint = WebhookEndpoint::findOrFail($id);

        if ($endpoint->isDeliverable()) {
            $endpoint->forceFill(['is_active' => false])->save();
            session()->flash('status', 'That endpoint is paused. Nothing will be sent to it.');

            return;
        }

        $endpoint->reactivate();
        session()->flash('status', 'That endpoint is active again.');
    }

    public function delete(string $id): void
    {
        $this->authorize('webhooks.manage');

        WebhookEndpoint::findOrFail($id)->delete();

        session()->flash('status', 'That endpoint has been removed.');
    }

    /** Try a failed delivery again, with the body it originally carried. */
    public function redeliver(string $id): void
    {
        $this->authorize('webhooks.manage');

        $delivery = WebhookDelivery::findOrFail($id);

        if ($delivery->status === WebhookDelivery::DELIVERED) {
            return;
        }

        $delivery->forceFill([
            'attempts' => 0,
            'status' => WebhookDelivery::PENDING,
            'last_error' => null,
            'response_status' => null,
            'response_body' => null,
            'next_attempt_at' => now(),
        ])->save();

        DeliverWebhook::dispatch($delivery->id);

        session()->flash('status', 'That delivery has been queued again.');
    }

    public function dismissSecret(): void
    {
        $this->plainTextSecret = null;
    }

    protected function resetForm(): void
    {
        $this->url = '';
        $this->description = '';
        $this->events = [WebhookEvents::DOCUMENT_ISSUED];
    }

    public function render(): View
    {
        return view('livewire.settings.webhooks', [
            'endpoints' => WebhookEndpoint::query()->withCount('deliveries')->latest()->get(),
            // Enough to see whether the last few went out, not a paginated
            // audit log — the API is where somebody trawls the history.
            'deliveries' => WebhookDelivery::query()->with('endpoint')->latest()->limit(25)->get(),
            'catalogue' => WebhookEvents::CATALOGUE,
        ])->layout('components.layouts.app', ['title' => 'Webhooks', 'active' => 'settings']);
    }
}
