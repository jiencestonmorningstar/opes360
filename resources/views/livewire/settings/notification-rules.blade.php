<div class="px-5 pb-8 lg:px-6 lg:pt-6">
    <div class="mx-auto max-w-3xl">

        <a href="{{ route('settings') }}" wire:navigate
           class="focusable -ml-1 inline-flex items-center gap-1.5 rounded-lg p-1 text-[14px] font-semibold text-muted hover:text-ink">
            <x-icon name="chevron-left" class="size-[16px]" stroke-width="2.2" />
            Settings
        </a>

        <h1 class="mt-3 text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Notification rules</h1>
        <p class="mt-1 text-[14.5px] leading-relaxed text-muted">
            When something happens in the business, decide who hears about it and how. Everyone can still
            switch off the categories they do not want — except the ones marked as needing a decision.
        </p>

        @if (session('status'))
            <div class="mt-5 rounded-xl bg-tint-green px-4 py-3 text-[14px] font-semibold text-positive">
                {{ session('status') }}
            </div>
        @endif

        {{-- The form --}}
        <div class="card mt-5 p-5">
            <p class="text-[15px] font-bold text-ink">{{ $editingId ? 'Edit this rule' : 'Add a rule' }}</p>

            <div class="mt-4 space-y-4">
                <div>
                    <label for="nr-name" class="block text-[13px] font-semibold text-ink">What is this rule for?</label>
                    <input id="nr-name" type="text" wire:model="name" placeholder="Tell finance about big expenses"
                           class="mt-1.5 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14px] text-ink" />
                    @error('name') <p class="mt-1 text-[12.5px] text-negative">{{ $message }}</p> @enderror
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="nr-event" class="block text-[13px] font-semibold text-ink">When this happens</label>
                        <select id="nr-event" wire:model="event"
                                class="mt-1.5 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14px] text-ink">
                            @foreach ($eventOptions as $module => $events)
                                <optgroup label="{{ ucfirst($module) }}">
                                    @foreach ($events as $name)
                                        <option value="{{ $name }}">{{ $name }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        @error('event') <p class="mt-1 text-[12.5px] text-negative">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="nr-recipient" class="block text-[13px] font-semibold text-ink">Tell</label>
                        <select id="nr-recipient" wire:model="recipientMode"
                                class="mt-1.5 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14px] text-ink">
                            @foreach ($recipientModes as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                {{-- Roles, people and permissions are named, never resolved to
                     an id here. A rule saying "the Finance manager" has to mean
                     whoever that is on the day it fires. --}}
                <div>
                    <label for="nr-recipient-value" class="block text-[13px] font-semibold text-ink">
                        Which one? <span class="font-normal text-muted">(a role slug, a permission, or a person's id — leave blank for the owner or the raiser)</span>
                    </label>
                    <input id="nr-recipient-value" type="text" wire:model="recipientValue" placeholder="accountant"
                           class="mt-1.5 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14px] text-ink" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="nr-category" class="block text-[13px] font-semibold text-ink">Category</label>
                        <select id="nr-category" wire:model="category"
                                class="mt-1.5 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14px] text-ink">
                            @foreach ($categories as $key => $definition)
                                <option value="{{ $key }}">{{ $definition['label'] }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-[12.5px] text-muted">This is what a person switches off.</p>
                    </div>

                    <div>
                        <label for="nr-severity" class="block text-[13px] font-semibold text-ink">How loud</label>
                        <select id="nr-severity" wire:model="severity"
                                class="mt-1.5 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14px] text-ink">
                            @foreach ($severities as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div>
                    <label for="nr-title" class="block text-[13px] font-semibold text-ink">The message</label>
                    <input id="nr-title" type="text" wire:model="title" placeholder="An expense over 500,000 was recorded"
                           class="mt-1.5 h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14px] text-ink" />
                    @error('title') <p class="mt-1 text-[12.5px] text-negative">{{ $message }}</p> @enderror

                    <textarea wire:model="body" rows="2" placeholder="A little more detail, if it helps."
                              class="mt-2 w-full rounded-xl border border-border bg-surface px-3 py-2.5 text-[14px] text-ink"></textarea>
                </div>

                <div>
                    <p class="text-[13px] font-semibold text-ink">Reach them by</p>
                    <div class="mt-2 space-y-2">
                        @foreach ($channelOptions as $key => $channel)
                            <label class="flex items-start gap-2.5 {{ $channel['available'] ? '' : 'opacity-60' }}">
                                <input type="checkbox" wire:model="channels" value="{{ $key }}"
                                       @disabled(! $channel['available'])
                                       class="mt-0.5 size-[18px] rounded border-border text-fill-brand" />
                                <span class="min-w-0">
                                    <span class="block text-[14px] font-semibold text-ink">{{ $channel['label'] }}</span>
                                    <span class="block text-[12.5px] text-muted">{{ $channel['description'] }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('channels') <p class="mt-1 text-[12.5px] text-negative">{{ $message }}</p> @enderror
                </div>

                {{-- The single most useful setting on this page. A field touched
                     five times in a minute is five events and one piece of news,
                     and five alerts is how a category gets muted for good. --}}
                <div>
                    <label for="nr-dedupe" class="block text-[13px] font-semibold text-ink">
                        Do not repeat the same message about the same record for
                    </label>
                    <div class="mt-1.5 flex items-center gap-2">
                        <input id="nr-dedupe" type="number" min="0" max="10080" wire:model="dedupeMinutes"
                               class="h-11 w-28 rounded-xl border border-border bg-surface px-3 text-[14px] text-ink" />
                        <span class="text-[14px] text-muted">minutes (0 to always send)</span>
                    </div>
                </div>
            </div>

            <div class="mt-5 flex items-center gap-2">
                <button type="button" wire:click="save"
                        class="tap focusable flex h-11 items-center rounded-xl bg-fill-brand px-5 text-[14px] font-semibold text-white">
                    {{ $editingId ? 'Save changes' : 'Add rule' }}
                </button>
                @if ($editingId)
                    <button type="button" wire:click="cancelEditing"
                            class="focusable h-11 rounded-xl px-4 text-[14px] font-semibold text-muted hover:text-ink">Cancel</button>
                @endif
            </div>
        </div>

        {{-- The rules --}}
        <h2 class="mt-8 text-[17px] font-bold text-ink">Your rules</h2>

        <div class="card mt-3 divide-y divide-border p-0">
            @forelse ($rules as $rule)
                <div class="flex items-start gap-3 px-4 py-3.5" wire:key="rule-{{ $rule->id }}">
                    <span class="min-w-0 flex-1">
                        <span class="block text-[14.5px] font-semibold text-ink">{{ $rule->name }}</span>
                        <span class="block text-[12.5px] text-muted">
                            {{ $rule->event }} · {{ $categories[$rule->category]['label'] ?? $rule->category }}
                            · {{ collect($rule->channels)->map(fn ($c) => $channelOptions[$c]['label'] ?? $c)->implode(', ') }}
                        </span>
                        <span class="mt-0.5 block text-[11.5px] text-faint">
                            {{ $rule->last_fired_at ? 'Last fired '.$rule->last_fired_at->diffForHumans() : 'Has not fired yet' }}
                        </span>
                    </span>

                    <span class="flex shrink-0 items-center gap-2">
                        <span class="rounded-full px-2 py-0.5 text-[11.5px] font-semibold {{ $rule->is_active ? 'bg-tint-green text-positive' : 'bg-surface-2 text-muted' }}">
                            {{ $rule->is_active ? 'On' : 'Paused' }}
                        </span>
                        <button type="button" wire:click="toggle('{{ $rule->id }}')"
                                class="focusable text-[12.5px] font-semibold text-muted hover:text-ink">
                            {{ $rule->is_active ? 'Pause' : 'Resume' }}
                        </button>
                        <button type="button" wire:click="edit('{{ $rule->id }}')"
                                class="focusable text-[12.5px] font-semibold text-brand hover:underline">Edit</button>
                        <button type="button" wire:click="delete('{{ $rule->id }}')"
                                wire:confirm="Remove this rule?"
                                class="focusable text-[12.5px] font-semibold text-negative hover:underline">Remove</button>
                    </span>
                </div>
            @empty
                <p class="px-4 py-8 text-center text-[13.5px] text-muted">No rules yet.</p>
            @endforelse
        </div>

        {{-- The log. This is the half of the screen that answers "nobody told me". --}}
        <h2 class="mt-8 text-[17px] font-bold text-ink">Recently sent</h2>
        <p class="mt-1 text-[13.5px] leading-relaxed text-muted">
            Including the ones we deliberately held back or did not send, and why.
        </p>

        <div class="card mt-3 divide-y divide-border p-0">
            @forelse ($deliveries as $delivery)
                <div class="flex items-start gap-3 px-4 py-3" wire:key="d-{{ $delivery->id }}">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[13.5px] font-semibold text-ink">{{ $delivery->title }}</span>
                        <span class="block text-[12.5px] text-muted">
                            {{ $delivery->user?->name ?? 'Someone who has left' }}
                            · {{ $channelOptions[$delivery->channel]['label'] ?? $delivery->channel }}
                            · {{ $delivery->created_at->diffForHumans() }}
                        </span>
                    </span>
                    <span class="shrink-0 text-right text-[12px] font-semibold
                        {{ $delivery->status === 'sent' ? 'text-positive' : ($delivery->status === 'failed' ? 'text-negative' : 'text-muted') }}">
                        {{ $delivery->outcome() }}
                    </span>
                </div>
            @empty
                <p class="px-4 py-8 text-center text-[13.5px] text-muted">Nothing sent yet.</p>
            @endforelse
        </div>
    </div>
</div>
