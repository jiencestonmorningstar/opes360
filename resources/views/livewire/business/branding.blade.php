@php
    use App\Support\BrandDefaults;

    $l = $preview['light'];
    $r = $preview['root'];

    /*
     * The preview cannot use the app's own utility classes: those read the
     * live :root variables, which are the *saved* palette, not the one being
     * tried. So it paints from inline styles taken from the derived map. That
     * is the one place in this codebase where inline colour is the correct
     * answer rather than a shortcut.
     */
    $card = 'border-radius:'.$r['--radius-card'].';background:'.$l['--color-surface'].';border:1px solid '.$l['--color-border'].';';
    $pad = 'padding:calc('.$r['--spacing'].' * 4);';
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Branding</h1>
            <p class="mt-1 text-[14.5px] text-muted">Make the platform look like your business.</p>
        </div>

        <a href="{{ route('logo') }}" wire:navigate
           class="tap focusable flex shrink-0 items-center gap-2 rounded-full border border-border bg-surface px-5 text-[14.5px] font-semibold text-ink transition-colors hover:bg-surface-2">
            <x-icon name="image" class="size-[17px]" stroke-width="2" />
            <span class="sr-only min-[420px]:not-sr-only">Logo</span>
        </a>
    </div>

    @if (session('status'))
        <div class="mt-5 rounded-xl bg-tint-green px-4 py-3 text-[14px] font-semibold text-positive">
            {{ session('status') }}
        </div>
    @endif

    <div class="mt-5 grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)]">

        {{-- ── Controls ──────────────────────────────────────────────── --}}
        <form wire:submit="save" class="space-y-5">

            <x-ui.panel title="Start from a preset">
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    @foreach ($this->presets() as $preset)
                        <button type="button"
                                wire:click="applyPreset('{{ $preset['primary'] }}', '{{ $preset['secondary'] }}')"
                                class="focusable flex items-center gap-2.5 rounded-xl border border-border px-3 py-2.5 text-left transition-colors hover:bg-surface-2">
                            <span class="flex shrink-0 -space-x-1.5">
                                <span class="size-[18px] rounded-full border-2 border-surface" style="background:{{ $preset['primary'] }}"></span>
                                <span class="size-[18px] rounded-full border-2 border-surface" style="background:{{ $preset['secondary'] }}"></span>
                            </span>
                            <span class="truncate text-[13px] font-semibold text-ink">{{ $preset['name'] }}</span>
                        </button>
                    @endforeach
                </div>
            </x-ui.panel>

            <x-ui.panel title="Your colours">
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ([['primary', 'Main colour', 'Buttons, links, the active menu item.'], ['secondary', 'Second colour', 'Charts, quick actions, accents.']] as [$field, $label, $hint])
                        <div>
                            <label for="{{ $field }}" class="block text-[13.5px] font-semibold text-ink-2">{{ $label }}</label>
                            <div class="mt-1.5 flex items-center gap-2">
                                <input type="color" wire:model.live="{{ $field }}" aria-label="{{ $label }} picker"
                                       class="focusable size-12 shrink-0 cursor-pointer rounded-xl border border-border bg-surface p-1">
                                <input id="{{ $field }}" type="text" wire:model.live.debounce.400ms="{{ $field }}"
                                       spellcheck="false" autocapitalize="off"
                                       class="control w-full min-w-0 border border-border bg-surface px-4 font-mono text-[15px] uppercase text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                            </div>
                            <p class="mt-1.5 text-[12.5px] text-faint">{{ $hint }}</p>
                            @error($field) <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>

                <p class="mt-4 text-[12.5px] leading-relaxed text-faint">
                    Your exact colour is kept for your logo and large brand shapes. Text and buttons are
                    derived from it so they stay readable — a colour that looks right on a swatch is often
                    unreadable as small text, and the difference is too fine to see by eye.
                </p>
            </x-ui.panel>

            <x-ui.panel title="Surface and shape">
                @php
                    $groups = [
                        ['neutral', 'Background tone', ['cool' => 'Cool grey', 'warm' => 'Warm', 'true-black' => 'Neutral']],
                        ['radius', 'Corners', ['sharp' => 'Sharp', 'soft' => 'Soft', 'rounded' => 'Rounded', 'pill' => 'Pill']],
                        ['density', 'Spacing', ['compact' => 'Compact', 'comfortable' => 'Comfortable']],
                        ['skin', 'Surfaces', ['solid' => 'Solid', 'glass' => 'Liquid glass']],
                    ];
                @endphp

                <div class="space-y-4">
                    @foreach ($groups as [$field, $label, $options])
                        <div>
                            <span class="block text-[13.5px] font-semibold text-ink-2">{{ $label }}</span>
                            <div class="mt-2 flex flex-wrap gap-2" role="group" aria-label="{{ $label }}">
                                @foreach ($options as $value => $text)
                                    <button type="button" wire:click="$set('{{ $field }}', '{{ $value }}')"
                                            aria-pressed="{{ $this->{$field} === $value ? 'true' : 'false' }}"
                                            class="focusable flex h-10 items-center rounded-full px-4 text-[13.5px] font-semibold transition-colors
                                                   {{ $this->{$field} === $value ? 'bg-fill-brand text-white' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                                        {{ $text }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endforeach

                    @if ($skin === 'glass')
                        <div>
                            <label for="glass_strength" class="block text-[13.5px] font-semibold text-ink-2">
                                Glass intensity
                            </label>
                            <input id="glass_strength" type="range" min="0" max="1" step="0.05"
                                   wire:model.live="glass_strength"
                                   class="focusable mt-2 w-full accent-[var(--color-fill-brand)]">
                            <p class="mt-1.5 text-[12.5px] leading-relaxed text-faint">
                                Glass is applied to the menus and bars only. Cards showing figures stay solid, so a
                                total is never harder to read because of what is behind it.
                            </p>
                        </div>
                    @endif
                </div>
            </x-ui.panel>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" wire:loading.attr="disabled" wire:target="save"
                        class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
                    <span wire:loading.remove wire:target="save">Save branding</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </button>

                <button type="button" wire:click="resetToDefault"
                        wire:confirm="Reset branding to the Opes360 default?"
                        class="tap focusable flex h-12 items-center rounded-xl border border-border px-5 text-[15px] font-semibold text-ink-2 transition-colors hover:bg-surface-2">
                    Reset
                </button>
            </div>
        </form>

        {{-- ── Live preview ──────────────────────────────────────────── --}}
        <div class="lg:sticky lg:top-6 lg:self-start">
            <x-ui.panel title="Preview">
                <div style="background:{{ $l['--color-canvas'] }};border-radius:{{ $r['--radius-card'] }};padding:calc({{ $r['--spacing'] }} * 4);">

                    {{-- Chrome, which is where glass applies --}}
                    <div style="{{ $skin === 'glass'
                            ? 'background:rgba(255,255,255,.55);backdrop-filter:blur('.$r['--glass-blur'].');border:1px solid rgba(255,255,255,.6);'
                            : 'background:'.$l['--color-surface'].';border:1px solid '.$l['--color-border'].';' }}
                                border-radius:{{ $r['--radius-lg'] }};padding:calc({{ $r['--spacing'] }} * 2.5);display:flex;align-items:center;gap:8px;">
                        <span style="width:16px;height:16px;border-radius:5px;background:{{ $l['--color-fill-brand'] }};display:block;"></span>
                        <span style="font-size:13px;font-weight:700;color:{{ $l['--color-ink'] }}">{{ auth()->user()->currentCompany?->name ?? 'Your business' }}</span>
                    </div>

                    {{-- Data cards, which stay solid whatever the skin --}}
                    <div style="display:grid;gap:calc({{ $r['--spacing'] }} * 2);margin-top:calc({{ $r['--spacing'] }} * 3);">
                        @foreach ([['Outstanding', '4 820 000', 'brand'], ['Overdue', '915 000', 'negative']] as [$label, $value, $role])
                            <div style="{{ $card }}{{ $pad }}">
                                <div style="font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:{{ $l['--color-faint'] }}">{{ $label }}</div>
                                <div style="font-size:21px;font-weight:800;letter-spacing:-.02em;color:{{ $l['--color-'.$role] }};font-variant-numeric:tabular-nums;margin-top:2px;">{{ $value }}</div>
                                <div style="font-size:11.5px;color:{{ $l['--color-muted'] }};margin-top:1px;">XAF</div>
                            </div>
                        @endforeach
                    </div>

                    {{-- Chips, so the accent families are visible --}}
                    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:calc({{ $r['--spacing'] }} * 3);">
                        @foreach ([['Paid', 'positive', 'tint-green'], ['Draft', 'accent-slate', 'tint-slate'], ['Sent', 'brand', 'tint-brand'], ['Overdue', 'negative', 'tint-red']] as [$text, $ink, $tint])
                            <span style="background:{{ $l['--color-'.$tint] }};color:{{ $l['--color-'.$ink] }};border-radius:999px;padding:5px 11px;font-size:11.5px;font-weight:700;">{{ $text }}</span>
                        @endforeach
                    </div>

                    <button type="button" disabled
                            style="margin-top:calc({{ $r['--spacing'] }} * 3);width:100%;height:44px;border:0;border-radius:{{ $r['--radius-xl'] }};background:{{ $l['--color-fill-brand'] }};color:#fff;font-size:14.5px;font-weight:700;font-family:inherit;">
                        Record payment
                    </button>
                </div>
            </x-ui.panel>

            {{-- ── The guarantee, in numbers ──────────────────────────── --}}
            <x-ui.panel title="Readability" class="mt-5">
                <p class="text-[12.5px] leading-relaxed text-faint">
                    Every colour below is checked against the WCAG AA standard of 4.5:1. These are the
                    derived values, not what you typed — which is why they always pass.
                </p>

                <div class="mt-3 space-y-1.5">
                    @foreach ($report as $row)
                        <div class="flex items-center gap-3 rounded-lg border border-border px-3 py-2">
                            <span class="size-6 shrink-0 rounded-md border border-border" style="background:{{ $row['colour'] }}"></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[13px] font-semibold text-ink">{{ $row['label'] }}</span>
                                <span class="block truncate text-[11.5px] text-faint">{{ $row['where'] }}</span>
                            </span>
                            <span class="shrink-0 rounded-full px-2.5 py-1 text-[11.5px] font-bold tabular-nums
                                         {{ $row['ratio'] >= 4.5 ? 'bg-tint-green text-positive' : 'bg-tint-red text-negative' }}">
                                {{ number_format($row['ratio'], 2) }}:1
                            </span>
                        </div>
                    @endforeach
                </div>
            </x-ui.panel>
        </div>
    </div>
</div>
