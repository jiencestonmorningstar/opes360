@php
    use App\Services\LogoComposer;
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Logo</h1>
            <p class="mt-1 text-[14.5px] text-muted">Clean vector output, ready for print at any size.</p>
        </div>

        <a href="{{ route('logo.download', ['palette' => $palette, 'mark' => $mark, 'layout' => $layout, 'tagline' => $tagline]) }}"
           class="tap focusable hidden shrink-0 items-center gap-2 rounded-full border border-border bg-surface px-5 text-[14px] font-semibold text-ink-2 hover:bg-surface-2 min-[560px]:flex">
            Download SVG
        </a>
    </div>

    @include('partials.business-tabs')

    @if (session('logoStatus'))
        <div class="mt-4 rounded-xl bg-tint-green px-4 py-3 text-[13.5px] font-semibold text-positive">
            {{ session('logoStatus') }}
        </div>
    @endif

    <div class="mt-4 grid gap-4 lg:grid-cols-3">

        <div class="space-y-4">
            <x-ui.panel title="Style">
                <span class="mb-1.5 block text-[13px] font-semibold text-ink-2">Colour</span>
                <div class="grid grid-cols-4 gap-2">
                    @foreach (LogoComposer::PALETTES as $key => [$primary, $secondary])
                        <button type="button" wire:click="$set('palette', '{{ $key }}')"
                                aria-label="{{ ucfirst($key) }} palette"
                                class="focusable h-11 rounded-xl border-2 transition-colors {{ $palette === $key ? 'border-brand' : 'border-transparent' }}"
                                style="background: linear-gradient(135deg, {{ $primary }}, {{ $secondary }})"></button>
                    @endforeach
                </div>

                <span class="mb-1.5 mt-4 block text-[13px] font-semibold text-ink-2">Mark</span>
                <div class="grid grid-cols-4 gap-2">
                    @foreach (LogoComposer::MARKS as $option)
                        <button type="button" wire:click="$set('mark', '{{ $option }}')"
                                class="focusable h-10 rounded-xl text-[12px] font-semibold capitalize transition-colors
                                       {{ $mark === $option ? 'bg-tint-blue text-brand ring-1 ring-brand/40' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                            {{ $option }}
                        </button>
                    @endforeach
                </div>

                <span class="mb-1.5 mt-4 block text-[13px] font-semibold text-ink-2">Layout</span>
                <div class="grid grid-cols-3 gap-2">
                    @foreach (LogoComposer::LAYOUTS as $option)
                        <button type="button" wire:click="$set('layout', '{{ $option }}')"
                                class="focusable h-10 rounded-xl text-[12.5px] font-semibold capitalize transition-colors
                                       {{ $layout === $option ? 'bg-tint-blue text-brand ring-1 ring-brand/40' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                            {{ $option }}
                        </button>
                    @endforeach
                </div>

                <label class="mt-4 block">
                    <span class="mb-1.5 block text-[13px] font-semibold text-ink-2">Tagline</span>
                    <input type="text" wire:model.live.debounce.400ms="tagline"
                           class="h-11 w-full rounded-xl border border-border bg-surface px-3.5 text-[14px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                </label>
            </x-ui.panel>

            <button type="button" wire:click="save" wire:loading.attr="disabled"
                    class="focusable flex h-12 w-full items-center justify-center rounded-xl bg-fill-brand text-[15px] font-semibold text-white hover:opacity-90">
                <span wire:loading.remove wire:target="save">Use this logo</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
        </div>

        <div class="space-y-4 lg:col-span-2">
            <x-ui.panel title="Preview">
                {{-- White plate in both themes: a logo is judged on paper. --}}
                <div class="flex items-center justify-center rounded-xl bg-white p-8">
                    <div class="w-full max-w-[420px]">{!! $preview !!}</div>
                </div>
            </x-ui.panel>

            <x-ui.panel title="Suggestions">
                <p class="mb-3 text-[12.5px] text-muted">
                    Tuned to {{ $company->industry ?? 'your industry' }}. Tap one to load it.
                </p>
                <div class="grid grid-cols-2 gap-3 min-[560px]:grid-cols-3">
                    @foreach ($suggestions as $option)
                        <button type="button"
                                wire:click="applySuggestion('{{ $option['palette'] }}', '{{ $option['mark'] }}', '{{ $option['layout'] }}')"
                                class="focusable flex items-center justify-center rounded-xl border border-border bg-white p-3 hover:border-brand">
                            <div class="w-full">{!! $option['svg'] !!}</div>
                        </button>
                    @endforeach
                </div>
            </x-ui.panel>
        </div>
    </div>
</div>
