@php
    use App\Support\CardCatalog;
    use App\Support\Money;

    $money = fn ($amount) => Money::format((int) $amount, 'XAF', false);

    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';

@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <a href="{{ route('partners.clients') }}"
       class="focusable inline-flex items-center gap-1.5 text-[13.5px] font-medium text-muted hover:text-ink">
        <x-icon name="chevron-left" class="size-[16px]" stroke-width="2.2" />
        Clients
    </a>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">{{ $client->name }}</h1>
            <p class="mt-1 text-[14.5px] text-muted">
                {{ collect([$client->contact_name, $client->industry, $client->city])->filter()->implode(' · ') ?: 'No details yet' }}
            </p>
        </div>

        @if ($client->hasConverted())
            <span class="shrink-0 rounded-full bg-tint-green px-3 py-1.5 text-[12.5px] font-semibold text-positive">
                Signed up {{ $client->converted_at?->format('j M Y') }}
            </span>
        @endif
    </div>

    @if (session('status'))
        <div class="mt-5 rounded-xl bg-tint-green px-4 py-3 text-[13.5px] font-medium text-positive">{{ session('status') }}</div>
    @endif

    @if ($issuedUrl)
        <a href="{{ $issuedUrl }}" target="_blank"
           class="tap focusable mt-4 flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-fill-brand text-[15px] font-semibold text-white hover:opacity-90">
            <x-icon name="printer" class="size-[18px]" stroke-width="2" />
            Open the print sheet
        </a>
    @endif

    {{-- The invite link. Its QR is what goes on the card, so the client's own
         card is the shortest path from "nice card" to "signed up". --}}
    @unless ($client->hasConverted())
        <div class="card mt-5 p-4">
            <div class="flex items-start gap-3">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-tint-purple">
                    <x-icon name="qr-code" class="size-[17px] text-accent-purple" stroke-width="1.9" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-[13.5px] font-semibold text-ink">Their invitation link</p>
                    <p class="mt-1 break-all text-[12.5px] leading-relaxed text-muted">{{ $client->inviteUrl() }}</p>
                    <p class="mt-2 text-[12.5px] leading-relaxed text-faint">
                        Printed as the QR on any card you make for them. If they register through it,
                        the signup is attributed to you.
                    </p>
                </div>
            </div>
        </div>
    @endunless

    <div class="mt-5 grid gap-5 lg:grid-cols-12">
        {{-- Options --}}
        <div class="space-y-4 lg:col-span-5">
            <x-ui.panel title="What to print">
                <div class="grid grid-cols-2 gap-2">
                    @foreach (['card' => 'Business card', 'letterhead' => 'Letterhead'] as $key => $label)
                        <button type="button" wire:click="$set('asset', '{{ $key }}')"
                                aria-pressed="{{ $asset === $key ? 'true' : 'false' }}"
                                class="focusable h-11 rounded-xl text-[13.5px] font-semibold transition-colors
                                       {{ $asset === $key ? 'bg-tint-blue text-brand ring-1 ring-brand/40' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                <div class="mt-4">
                    <label class="{{ $labelClass }}" for="holder-name">Name on the card</label>
                    <input id="holder-name" type="text" wire:model.live.debounce.400ms="holderName" class="{{ $inputClass }}">
                    @error('holderName') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>

                <div class="mt-3">
                    <label class="{{ $labelClass }}" for="holder-title">Their title</label>
                    <input id="holder-title" type="text" wire:model.live.debounce.400ms="holderTitle" class="{{ $inputClass }}">
                </div>
            </x-ui.panel>

            <x-ui.panel title="Design">
                {{-- The client's own trade is preselected and starred. Each tile
                     embeds the real print renderer, so the tile IS the card —
                     which is also why only one sector's worth is on the page at
                     a time rather than all ninety-eight. --}}
                <div class="mb-2.5 flex flex-wrap gap-1.5">
                    @foreach ($sectors as $option)
                        <button type="button" wire:click="setSector('{{ $option }}')" wire:key="sector-{{ $option }}"
                                class="focusable rounded-full px-3 py-1.5 text-[12px] font-semibold transition-colors
                                       {{ $sector === $option ? 'bg-fill-brand text-white' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                            {{ $option === 'universal' ? 'Universal' : $option }}@if ($option === $recommendedSector) ★@endif
                        </button>
                    @endforeach
                </div>

                @if ($recommendedSector !== null && $sector === $recommendedSector)
                    <p class="mb-2.5 text-[12px] font-medium text-brand">
                        ★ Made for {{ $client->industry }}.
                    </p>
                @endif

                <div class="grid grid-cols-2 gap-2">
                    @foreach ($sectorDesigns as $option)
                        <button type="button" wire:click="selectDesign('{{ $option }}')" wire:key="design-{{ $option }}"
                                aria-pressed="{{ $design === $option ? 'true' : 'false' }}"
                                class="focusable rounded-xl border p-2 text-left transition-colors
                                       {{ $design === $option ? 'border-brand bg-tint-blue ring-1 ring-brand/40' : 'border-border bg-surface hover:bg-surface-2' }}">
                            <span class="pointer-events-none relative block w-full overflow-hidden rounded-md border border-border bg-white" style="aspect-ratio: 91 / 61">
                                <iframe src="{{ route('partners.clients.print', ['client' => $client, 'asset' => 'card', 'design' => $option, 'preview' => 1, 'face' => 'front']) }}"
                                        class="absolute inset-0 h-full w-full" scrolling="no" tabindex="-1"
                                        loading="lazy" aria-hidden="true" title=""></iframe>
                            </span>
                            <p class="mt-1.5 truncate text-[12px] font-semibold {{ $design === $option ? 'text-brand' : 'text-ink-2' }}">
                                {{ CardCatalog::design($option)['label'] ?? ucfirst($option) }}
                            </p>
                        </button>
                    @endforeach
                </div>
            </x-ui.panel>

            @can('partners.issue')
                @if ($confirming)
                    <div class="card border-brand p-5 ring-1 ring-brand/40">
                        <h2 class="text-[16px] font-bold tracking-[-0.02em] text-ink">Issue this {{ $asset }}?</h2>
                        <p class="mt-2 text-[13.5px] leading-relaxed text-muted">
                            {{ $money($fee) }} is added to your account. What you charge {{ $client->name }} is yours
                            to decide.
                        </p>
                        <div class="mt-5 flex flex-col gap-3 sm:flex-row-reverse">
                            <button type="button" wire:click="issue"
                                    class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white hover:opacity-90">
                                Issue and charge {{ $money($fee) }}
                            </button>
                            <button type="button" wire:click="cancel"
                                    class="tap focusable flex h-12 items-center justify-center rounded-xl border border-border bg-surface px-6 text-[15px] font-semibold text-ink">
                                Not yet
                            </button>
                        </div>
                    </div>
                @else
                    <button type="button" wire:click="startIssue"
                            class="tap focusable flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-fill-brand text-[15px] font-semibold text-white hover:opacity-90">
                        <x-icon name="printer" class="size-[18px]" stroke-width="2" />
                        Issue this {{ $asset }} · {{ $money($fee) }}
                    </button>
                @endif
            @endcan
        </div>

        {{-- Preview + history --}}
        <div class="space-y-4 lg:col-span-7">
            <x-ui.panel title="Preview">
                <div class="relative mx-auto w-full overflow-hidden rounded-lg"
                     style="aspect-ratio: {{ $asset === 'card' ? '712 / 247' : '210 / 297' }}; max-width: {{ $asset === 'card' ? '760px' : '520px' }}">
                    <iframe src="{{ $previewUrl }}" class="absolute inset-0 h-full w-full"
                            scrolling="no" title="Stationery preview"></iframe>
                </div>
                <p class="mt-3 text-[12.5px] text-muted">
                    Exactly what prints. The QR opens {{ $client->name }}'s invitation link.
                </p>
            </x-ui.panel>

            <x-ui.panel title="Already issued">
                @forelse ($issuances as $issuance)
                    <div class="flex items-center gap-3 border-border py-2.5 {{ $loop->first ? '' : 'border-t' }}">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-tint-slate">
                            <x-icon name="printer" class="size-[16px] text-accent-slate" stroke-width="2" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-[14px] font-semibold text-ink">
                                {{ CardCatalog::design($issuance->design)['label'] ?? ucfirst($issuance->design) }}
                            </p>
                            <p class="text-[12px] text-faint">
                                {{ $issuance->created_at?->format('j M Y') }} · {{ ucfirst($issuance->asset) }}
                            </p>
                        </div>
                        <span class="tnum shrink-0 text-[13.5px] font-semibold {{ $issuance->isBilled() ? 'text-ink-2' : 'text-faint line-through' }}">
                            {{ $money($issuance->fee) }}
                        </span>
                    </div>
                @empty
                    <p class="py-4 text-center text-[13.5px] text-muted">Nothing printed for this client yet.</p>
                @endforelse
            </x-ui.panel>
        </div>
    </div>
</div>
