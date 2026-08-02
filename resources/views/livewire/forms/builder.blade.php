<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <a href="{{ route('forms') }}" class="focusable inline-flex items-center gap-1 text-[13.5px] font-semibold text-brand hover:underline">
        <x-icon name="chevron-left" class="size-[16px]" stroke-width="2.2" />
        All forms
    </a>

    {{-- Title + status --}}
    <div class="card mt-4 p-5">
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0 flex-1">
                <input type="text" wire:model.blur="title" placeholder="Untitled form"
                       class="w-full border-0 bg-transparent p-0 text-[22px] font-bold tracking-[-0.02em] text-ink placeholder:text-faint focus:outline-none focus:ring-0">
                <input type="text" wire:model.blur="description" placeholder="Add a description for the people filling this in…"
                       class="mt-1 w-full border-0 bg-transparent p-0 text-[14px] text-muted placeholder:text-faint focus:outline-none focus:ring-0">
            </div>
            <x-ui.status-badge :label="$form->state()['label']" :tone="$form->state()['tone']" />
        </div>

        @error('fields')
            <div class="mt-3 rounded-xl bg-tint-orange px-4 py-3 text-[13.5px] font-medium text-warning">{{ $message }}</div>
        @enderror

        <div class="mt-4 flex flex-wrap items-center gap-2.5">
            @if (! $form->isOpen())
                <button type="button" wire:click="setStatus('open')"
                        class="tap focusable flex h-11 items-center gap-1.5 rounded-xl bg-fill-brand px-4 text-[13.5px] font-semibold text-white transition-opacity hover:opacity-90">
                    <x-icon name="check-circle" class="size-[17px]" stroke-width="2" />
                    Open for responses
                </button>
            @else
                <button type="button" wire:click="setStatus('closed')"
                        class="tap focusable flex h-11 items-center rounded-xl border border-border bg-surface px-4 text-[13.5px] font-semibold text-ink-2 hover:bg-surface-2">
                    Stop accepting responses
                </button>
            @endif

            @can('responses', $form)
                <a href="{{ route('forms.responses', $form) }}"
                   class="focusable flex h-11 items-center rounded-xl border border-border bg-surface px-4 text-[13.5px] font-semibold text-ink-2 hover:bg-surface-2">
                    Responses
                </a>
            @endcan
        </div>

        {{-- Share link + embed snippet, only meaningful once open --}}
        @if ($form->isOpen())
            <div class="mt-4 rounded-xl bg-surface-2 p-3.5" x-data="{ copied: false }">
                <p class="text-[11.5px] font-medium uppercase tracking-wide text-faint">Share link</p>
                <div class="mt-1.5 flex items-center gap-2">
                    <span class="tnum min-w-0 flex-1 truncate text-[13.5px] font-semibold text-ink">{{ $form->publicUrl() }}</span>
                    <button type="button"
                            @click="navigator.clipboard?.writeText('{{ $form->publicUrl() }}').then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
                            class="focusable shrink-0 rounded-lg border border-border bg-surface px-3 py-1.5 text-[12.5px] font-semibold text-ink-2 hover:bg-surface">
                        <span x-show="!copied">Copy</span>
                        <span x-show="copied" x-cloak class="text-positive">Copied</span>
                    </button>
                </div>
            </div>

            @php
                $embedSnippet = '<iframe src="'.route('form.embed', $form->share_token).'" width="100%" height="760" style="border:0" title="'.e($form->title).'"></iframe>';
            @endphp
            <div class="mt-3 rounded-xl bg-surface-2 p-3.5" x-data="{ copied: false }">
                <p class="text-[11.5px] font-medium uppercase tracking-wide text-faint">Embed on your website</p>
                <div class="mt-1.5 flex items-center gap-2">
                    <code class="tnum min-w-0 flex-1 truncate text-[12px] text-muted">{{ $embedSnippet }}</code>
                    <button type="button"
                            @click="navigator.clipboard?.writeText({{ Js::from($embedSnippet) }}).then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
                            class="focusable shrink-0 rounded-lg border border-border bg-surface px-3 py-1.5 text-[12.5px] font-semibold text-ink-2 hover:bg-surface">
                        <span x-show="!copied">Copy</span>
                        <span x-show="copied" x-cloak class="text-positive">Copied</span>
                    </button>
                </div>
                <p class="mt-1.5 text-[12px] leading-snug text-faint">Paste into any website's HTML — the form fills and submits right on the page, like a Google Form.</p>
            </div>
        @endif
    </div>

    {{-- Fields --}}
    <div class="mt-4 space-y-3">
        @foreach ($fields as $i => $field)
            <div wire:key="field-{{ $field['id'] }}" class="card p-5">
                <div class="flex items-start gap-3">
                    <div class="min-w-0 flex-1">
                        <input type="text" wire:model.blur="fields.{{ $i }}.label" placeholder="Question"
                               class="w-full border-0 border-b border-border bg-transparent px-0 pb-2 text-[15.5px] font-semibold text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-0">
                        <input type="text" wire:model.blur="fields.{{ $i }}.help" placeholder="Help text (optional)"
                               class="mt-2 w-full border-0 bg-transparent px-0 text-[13px] text-muted placeholder:text-faint focus:outline-none focus:ring-0">
                    </div>

                    <span class="shrink-0 rounded-lg bg-surface-2 px-2.5 py-1.5 text-[12px] font-semibold text-ink-2">
                        {{ $types[$field['type']]['label'] ?? $field['type'] }}
                    </span>
                </div>

                {{-- Options for choice-type fields --}}
                @if (($types[$field['type']]['options'] ?? false))
                    <div class="mt-3.5 space-y-2">
                        @foreach ($field['options'] as $j => $option)
                            <div wire:key="opt-{{ $field['id'] }}-{{ $j }}" class="flex items-center gap-2.5">
                                <span class="size-[16px] shrink-0 rounded-{{ $field['type'] === 'checkboxes' ? 'md' : 'full' }} border-2 border-border-strong"></span>
                                <input type="text" wire:model.blur="fields.{{ $i }}.options.{{ $j }}"
                                       class="h-9 min-w-0 flex-1 rounded-lg border border-border bg-surface px-3 text-[14px] text-ink focus:border-brand focus:outline-none focus:ring-1 focus:ring-brand/20">
                                @if (count($field['options']) > 1)
                                    <button type="button" wire:click="removeOption({{ $i }}, {{ $j }})"
                                            class="focusable shrink-0 text-[12.5px] font-semibold text-faint hover:text-warning">Remove</button>
                                @endif
                            </div>
                        @endforeach
                        <button type="button" wire:click="addOption({{ $i }})"
                                class="focusable text-[13px] font-semibold text-brand hover:underline">Add option</button>
                    </div>
                @endif

                {{-- Field controls --}}
                <div class="mt-4 flex items-center justify-between border-t border-border pt-3.5">
                    <label class="flex items-center gap-2 text-[13.5px] text-ink-2">
                        <input type="checkbox" wire:model.live="fields.{{ $i }}.required"
                               class="size-[20px] rounded border-border-strong text-brand focus:ring-brand/30">
                        Required
                    </label>

                    <div class="flex items-center gap-1">
                        <button type="button" wire:click="moveField({{ $i }}, -1)" @disabled($i === 0)
                                class="focusable rounded-lg p-2 text-faint hover:bg-surface-2 hover:text-ink-2 disabled:opacity-30">
                            <x-icon name="arrow-up" class="size-[16px]" stroke-width="2.2" />
                        </button>
                        <button type="button" wire:click="moveField({{ $i }}, 1)" @disabled($i === count($fields) - 1)
                                class="focusable rounded-lg p-2 text-faint hover:bg-surface-2 hover:text-ink-2 disabled:opacity-30">
                            <x-icon name="chevron-down" class="size-[16px]" stroke-width="2.2" />
                        </button>
                        <button type="button" wire:click="removeField({{ $i }})"
                                class="focusable rounded-lg px-2.5 py-2 text-[12.5px] font-semibold text-faint hover:bg-tint-orange hover:text-warning">
                            Delete
                        </button>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Add a field --}}
    <div class="card mt-4 p-5">
        <p class="text-[13px] font-semibold uppercase tracking-wide text-faint">Add a question</p>
        <div class="mt-3 grid grid-cols-2 gap-2 min-[560px]:grid-cols-3">
            @foreach ($types as $key => $type)
                <button type="button" wire:click="addField('{{ $key }}')" wire:key="add-{{ $key }}"
                        class="focusable flex items-center gap-2.5 rounded-xl border border-border bg-surface px-3.5 py-3 text-left transition-colors hover:border-brand/40 hover:bg-surface-2">
                    <x-icon :name="$type['icon']" class="size-[18px] shrink-0 text-muted" stroke-width="1.9" />
                    <span class="text-[13.5px] font-semibold text-ink-2">{{ $type['label'] }}</span>
                </button>
            @endforeach
        </div>
    </div>
</div>
