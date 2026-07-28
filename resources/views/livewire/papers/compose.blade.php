@php
    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[14.5px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
@endphp

<div class="px-5 pb-32 lg:px-6 lg:pt-6 lg:pb-8">

    <div class="flex items-center gap-3">
        <a href="{{ $paper ? route('papers.show', $paper) : route('papers') }}"
           class="tap focusable -ml-2 flex items-center justify-center rounded-lg text-muted hover:text-ink" aria-label="Back">
            <x-icon name="chevron-left" class="size-[22px]" stroke-width="2.2" />
        </a>
        <div>
            <h1 class="text-[22px] font-bold leading-tight tracking-[-0.02em] text-ink lg:text-[25px]">
                {{ $definition['name'] }}
            </h1>
            <p class="mt-0.5 text-[13.5px] text-muted">{{ $definition['summary'] }}</p>
        </div>
    </div>

    <div class="mt-5 grid gap-4 lg:grid-cols-5">

        {{-- Fields --}}
        <div class="space-y-4 lg:col-span-2">
            <x-ui.panel title="Details">
                <label class="block">
                    <span class="{{ $labelClass }}">Document name</span>
                    <input type="text" wire:model.live.debounce.400ms="title" class="{{ $inputClass }}">
                    @error('title') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                </label>
            </x-ui.panel>

            <x-ui.panel :title="$definition['name']">
                <div class="space-y-4">
                    @foreach ($definition['fields'] as $field)
                        <label class="block" wire:key="f-{{ $field['key'] }}">
                            <span class="{{ $labelClass }}">
                                {{ $field['label'] }}
                                @unless ($field['required'] ?? false)
                                    <span class="font-normal text-faint">(optional)</span>
                                @endunless
                            </span>

                            @if (($field['type'] ?? 'text') === 'textarea')
                                <textarea wire:model.live.debounce.500ms="fields.{{ $field['key'] }}" rows="4"
                                          placeholder="{{ $field['placeholder'] ?? '' }}"
                                          class="w-full rounded-xl border border-border bg-surface px-3.5 py-3 text-[14.5px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"></textarea>
                            @else
                                <input type="{{ $field['type'] ?? 'text' }}"
                                       wire:model.live.debounce.400ms="fields.{{ $field['key'] }}"
                                       placeholder="{{ $field['placeholder'] ?? '' }}"
                                       class="{{ $inputClass }}">
                            @endif

                            @error('fields.'.$field['key'])
                                <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p>
                            @enderror
                        </label>
                    @endforeach
                </div>
            </x-ui.panel>
        </div>

        {{-- Live preview. The point of the screen: these get signed, so the
             sentences the answers produce have to be readable before saving. --}}
        <div class="lg:col-span-3">
            <div class="card overflow-hidden">
                <div class="flex items-center justify-between border-b border-border px-5 py-3.5">
                    <span class="text-[13.5px] font-semibold text-ink-2">Preview</span>
                    <span class="text-[12px] text-faint">Updates as you type</span>
                </div>

                <div class="prose-paper max-h-[70vh] overflow-y-auto px-6 py-6">
                    {!! $bodyHtml !!}
                </div>

                @if ($notice)
                    <p class="border-t border-border bg-tint-orange/60 px-5 py-3 text-[12px] leading-snug text-warning">
                        {{ $notice }}
                    </p>
                @endif
            </div>
        </div>
    </div>

    {{-- Actions --}}
    <div class="card fixed inset-x-0 bottom-0 z-30 rounded-b-none border-x-0 border-b-0 p-5 lg:static lg:mt-4 lg:rounded-[var(--radius-card)] lg:border"
         style="padding-bottom: calc(1.25rem + env(safe-area-inset-bottom))">
        <div class="mx-auto flex max-w-[560px] gap-3 lg:max-w-none">
            <button type="button" wire:click="save(false)" wire:loading.attr="disabled"
                    class="focusable h-12 flex-1 rounded-xl bg-surface-2 text-[14.5px] font-semibold text-ink-2 transition-colors hover:bg-tint-blue hover:text-brand">
                Save Draft
            </button>
            @can('papers.issue')
                <button type="button" wire:click="saveAndIssue" wire:loading.attr="disabled"
                        class="focusable h-12 flex-[1.4] rounded-xl bg-brand text-[14.5px] font-semibold text-white transition-opacity hover:opacity-90">
                    <span wire:loading.remove wire:target="saveAndIssue">Issue Document</span>
                    <span wire:loading wire:target="saveAndIssue">Issuing…</span>
                </button>
            @endcan
        </div>
    </div>
</div>
