@php
    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[14.5px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';

    // Same composition as print/paper.blade.php, so the miniature matches the sheet.
    $letterheadPhone = data_get($company->phones, 0);
    $letterheadAddress = collect([
        $company->address_line1,
        $company->city,
        $company->country,
    ])->filter()->implode(' · ');
@endphp

<div class="px-5 pb-32 lg:px-6 lg:pt-6 lg:pb-8" x-data="{ pane: 'form' }">

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

    {{-- On a phone there is no room for the form and the sheet side by side,
         so one control flips between them; from `lg` both are always shown. --}}
    <div class="mt-5 grid grid-cols-2 gap-1 rounded-xl bg-surface-2 p-1 lg:hidden">
        <button type="button" @click="pane = 'form'"
                class="focusable h-10 rounded-lg text-[13.5px] font-semibold transition-colors"
                :class="pane === 'form' ? 'bg-surface text-ink shadow-card' : 'text-muted'">
            Edit
        </button>
        <button type="button" @click="pane = 'preview'"
                class="focusable h-10 rounded-lg text-[13.5px] font-semibold transition-colors"
                :class="pane === 'preview' ? 'bg-surface text-ink shadow-card' : 'text-muted'">
            Preview
        </button>
    </div>

    <div class="mt-4 grid gap-4 lg:mt-5 lg:grid-cols-5">

        {{-- Fields --}}
        <div class="space-y-4 lg:col-span-2" :class="pane === 'form' ? '' : 'hidden lg:block'">
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
             sentences the answers produce have to be readable before saving.
             A miniature of print/paper.blade.php — same letterhead, title line
             and body, scaled to the screen. --}}
        <div class="lg:col-span-3" :class="pane === 'preview' ? '' : 'hidden lg:block'" data-preview="paper">
            <div class="card overflow-hidden lg:sticky lg:top-6">
                <div class="flex items-center justify-between border-b border-border px-5 py-3.5">
                    <span class="text-[13.5px] font-semibold text-ink-2">Preview</span>
                    <span class="text-[12px] text-faint">Updates as you type</span>
                </div>

                <div class="max-h-[70vh] overflow-y-auto p-5 lg:p-6">
                    {{-- Letterhead --}}
                    <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1 border-b-2 border-ink pb-3">
                        <div class="min-w-0">
                            <p class="text-[16px] font-extrabold leading-tight tracking-[-0.02em] text-ink">{{ $company->name }}</p>
                            @if ($company->motto)
                                <p class="mt-0.5 text-[11px] text-muted">{{ $company->motto }}</p>
                            @endif
                        </div>
                        <div class="min-w-0 text-[10.5px] leading-relaxed text-muted sm:text-right">
                            @if ($letterheadAddress) <p>{{ $letterheadAddress }}</p> @endif
                            @if ($letterheadPhone) <p>{{ $letterheadPhone }}</p> @endif
                            @if ($company->email) <p class="break-words">{{ $company->email }}</p> @endif
                        </div>
                    </div>

                    {{-- Title line. Drafts in the editor never carry a reference
                         yet — issuing is what assigns one. --}}
                    <div class="mt-3 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                        <span class="min-w-0 break-words text-[13.5px] font-extrabold tracking-[-0.01em] text-ink">{{ $title }}</span>
                        <span class="text-[10px] font-bold uppercase tracking-[0.08em] text-warning">Draft — not issued</span>
                    </div>

                    <div class="prose-paper mt-4">
                        {!! $bodyHtml !!}
                    </div>
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
