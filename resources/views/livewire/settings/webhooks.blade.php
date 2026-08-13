<div class="px-5 pb-8 lg:px-6 lg:pt-6">
    <div class="mx-auto max-w-3xl">

        <a href="{{ route('settings') }}" wire:navigate
           class="focusable -ml-1 inline-flex items-center gap-1.5 rounded-lg p-1 text-[14px] font-semibold text-muted hover:text-ink">
            <x-icon name="chevron-left" class="size-[16px]" stroke-width="2.2" />
            Settings
        </a>

        <h1 class="mt-3 text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Webhooks</h1>
        <p class="mt-1 text-[14.5px] leading-relaxed text-muted">
            Instead of another program asking us every minute whether anything happened, we can tell it
            the moment something does. Give us an address and pick what it should hear about.
        </p>

        @if (session('status'))
            <div class="mt-5 rounded-xl bg-tint-green px-4 py-3 text-[14px] font-semibold text-positive">
                {{ session('status') }}
            </div>
        @endif

        {{-- Shown once. We hold this secret in the clear because the receiving
             end needs the same bytes to check the signature — but a secret
             sitting re-readable on a settings page is one that gets pasted
             into a chat window. --}}
        @if ($plainTextSecret)
            <div class="card mt-5 border-brand p-5 ring-1 ring-brand">
                <p class="text-[14.5px] font-bold text-ink">Copy this signing secret now</p>
                <p class="mt-1 text-[13px] leading-relaxed text-muted">
                    Your server uses it to check that a delivery really came from us. We will not show it again.
                </p>

                <div class="mt-3 flex items-center gap-2">
                    <code class="min-w-0 flex-1 overflow-x-auto rounded-lg border border-border bg-surface-2 px-3 py-2.5 text-[12.5px] text-ink">{{ $plainTextSecret }}</code>
                    <button type="button"
                            x-data="{ copied: false }"
                            @click="navigator.clipboard.writeText(@js($plainTextSecret)); copied = true; setTimeout(() => copied = false, 2000)"
                            class="tap focusable flex h-11 shrink-0 items-center rounded-xl bg-fill-brand px-4 text-[13.5px] font-semibold text-white">
                        <span x-show="!copied">Copy</span>
                        <span x-show="copied" x-cloak>Copied</span>
                    </button>
                </div>

                <button type="button" wire:click="dismissSecret"
                        class="focusable mt-3 text-[13px] font-semibold text-muted hover:text-ink">
                    I have copied it
                </button>
            </div>
        @endif

        <div class="card mt-5 p-5">
            <h2 class="text-[15.5px] font-bold text-ink">
                {{ $editingId ? 'Edit this endpoint' : 'Add an endpoint' }}
            </h2>

            <form wire:submit="save" class="mt-4 space-y-4">
                <div>
                    <label for="webhook-url" class="block text-[13.5px] font-semibold text-ink-2">Where do we send it?</label>
                    <input id="webhook-url" type="url" wire:model="url" autocomplete="off"
                           placeholder="https://example.com/hooks/opes360"
                           class="control mt-1.5 w-full border border-border bg-surface px-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    <p class="mt-1 text-[12.5px] text-muted">Must be https — a delivery carries your business's figures across the internet.</p>
                    @error('url') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="webhook-description" class="block text-[13.5px] font-semibold text-ink-2">What is it for?</label>
                    <input id="webhook-description" type="text" wire:model="description" autocomplete="off"
                           placeholder="Stock system at the warehouse"
                           class="control mt-1.5 w-full border border-border bg-surface px-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    @error('description') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>

                <div>
                    <span class="block text-[13.5px] font-semibold text-ink-2">What should it hear about?</span>

                    <div class="mt-2.5 space-y-2">
                        @foreach ($catalogue as $event => $describes)
                            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-border bg-surface p-3.5">
                                <input type="checkbox" wire:model="events" value="{{ $event }}"
                                       class="mt-0.5 size-4 shrink-0 rounded border-border-strong text-brand focus:ring-brand/30">
                                <span class="min-w-0">
                                    <span class="block text-[14px] font-semibold text-ink">{{ $event }}</span>
                                    <span class="block text-[12.5px] text-muted">{{ $describes }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('events') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>

                <div class="flex gap-3">
                    <button type="submit" wire:loading.attr="disabled" wire:target="save"
                            class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
                        <span wire:loading.remove wire:target="save">{{ $editingId ? 'Save changes' : 'Add endpoint' }}</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </button>

                    @if ($editingId)
                        <button type="button" wire:click="cancelEditing"
                                class="focusable flex h-12 items-center rounded-xl bg-surface-2 px-5 text-[15px] font-semibold text-ink-2 hover:bg-tint-blue hover:text-brand">
                            Cancel
                        </button>
                    @endif
                </div>
            </form>
        </div>

        <div class="card mt-5 p-5">
            <h2 class="text-[15.5px] font-bold text-ink">Your endpoints</h2>

            @forelse ($endpoints as $endpoint)
                <div wire:key="endpoint-{{ $endpoint->id }}"
                     class="mt-3 rounded-xl border border-border p-3.5">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <p class="truncate text-[14px] font-semibold text-ink">{{ $endpoint->url }}</p>
                            @if ($endpoint->description)
                                <p class="mt-0.5 truncate text-[12.5px] text-muted">{{ $endpoint->description }}</p>
                            @endif
                            <p class="mt-1 text-[12.5px] text-muted">{{ implode(', ', $endpoint->events ?? []) }}</p>
                            <p class="mt-1 text-[11.5px] text-faint">
                                {{ $endpoint->deliveries_count }} {{ Str::plural('delivery', $endpoint->deliveries_count) }} sent
                                @if ($endpoint->consecutive_failures > 0)
                                    &middot; {{ $endpoint->consecutive_failures }} failing in a row
                                @endif
                            </p>
                        </div>

                        <span class="shrink-0 rounded-lg px-2.5 py-1 text-[12px] font-semibold
                            {{ $endpoint->isDeliverable() ? 'bg-tint-green text-positive' : 'bg-tint-red text-negative' }}">
                            {{ $endpoint->isDeliverable() ? 'Active' : 'Off' }}
                        </span>
                    </div>

                    {{-- Why it stopped, in the one place the person who needs to
                         know will look. --}}
                    @if ($endpoint->disabled_reason)
                        <p class="mt-2.5 rounded-lg bg-tint-red px-3 py-2 text-[12.5px] leading-relaxed text-negative">
                            {{ $endpoint->disabled_reason }}
                        </p>
                    @endif

                    <div class="mt-3 flex flex-wrap gap-3">
                        <button type="button" wire:click="edit('{{ $endpoint->id }}')"
                                class="focusable rounded-lg text-[13px] font-semibold text-brand hover:opacity-80">Edit</button>
                        <button type="button" wire:click="toggle('{{ $endpoint->id }}')"
                                class="focusable rounded-lg text-[13px] font-semibold text-ink-2 hover:text-ink">
                            {{ $endpoint->isDeliverable() ? 'Pause' : 'Resume' }}
                        </button>
                        <button type="button" wire:click="delete('{{ $endpoint->id }}')"
                                wire:confirm="Remove this endpoint? Nothing more will be sent to it."
                                class="focusable rounded-lg text-[13px] font-semibold text-negative hover:opacity-80">Remove</button>
                    </div>
                </div>
            @empty
                <p class="mt-3 text-[13.5px] text-muted">
                    Nothing is subscribed yet. Anything you add will be listed here.
                </p>
            @endforelse
        </div>

        <div class="card mt-5 p-5">
            <h2 class="text-[15.5px] font-bold text-ink">Recent deliveries</h2>
            <p class="mt-1 text-[12.5px] leading-relaxed text-muted">
                A failed delivery is retried five times over about nine hours. After that you can send it again yourself.
            </p>

            @forelse ($deliveries as $delivery)
                <div wire:key="delivery-{{ $delivery->id }}"
                     class="mt-3 flex items-start justify-between gap-4 rounded-xl border border-border p-3.5">
                    <div class="min-w-0">
                        <p class="truncate text-[14px] font-semibold text-ink">{{ $delivery->event }}</p>
                        <p class="mt-0.5 truncate text-[12.5px] text-muted">{{ $delivery->endpoint?->url }}</p>
                        <p class="mt-1 text-[11.5px] text-faint">
                            {{ $delivery->created_at?->diffForHumans() }}
                            &middot; {{ $delivery->attempts }} {{ Str::plural('attempt', $delivery->attempts) }}
                            @if ($delivery->response_status)
                                &middot; answered {{ $delivery->response_status }}
                            @endif
                        </p>
                        @if ($delivery->last_error)
                            <p class="mt-1 text-[12px] leading-relaxed text-negative">{{ $delivery->last_error }}</p>
                        @endif
                    </div>

                    <div class="flex shrink-0 flex-col items-end gap-2">
                        <span class="rounded-lg px-2.5 py-1 text-[12px] font-semibold
                            @class([
                                'bg-tint-green text-positive' => $delivery->status === 'delivered',
                                'bg-tint-blue text-brand' => $delivery->status === 'pending',
                                'bg-tint-red text-negative' => $delivery->status === 'failed',
                            ])">
                            {{ ucfirst($delivery->status) }}
                        </span>

                        @if ($delivery->status !== 'delivered')
                            <button type="button" wire:click="redeliver('{{ $delivery->id }}')"
                                    class="focusable rounded-lg text-[13px] font-semibold text-brand hover:opacity-80">
                                Send again
                            </button>
                        @endif
                    </div>
                </div>
            @empty
                <p class="mt-3 text-[13.5px] text-muted">Nothing has been sent yet.</p>
            @endforelse
        </div>

        <p class="mt-5 text-[13px] leading-relaxed text-muted">
            Every delivery carries an
            <code class="rounded bg-surface-2 px-1.5 py-0.5 text-[12px] text-ink-2">Opes-Signature</code>
            header your server should check against the secret before trusting the body. The
            API documentation has a worked example.
        </p>
    </div>
</div>
