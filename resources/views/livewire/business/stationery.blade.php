@php
    use App\Livewire\Business\Stationery;

    $inputClass = 'h-11 w-full rounded-xl border border-border bg-surface px-3.5 text-[14px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
    $phone = data_get($company->phones, 0);
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Stationery</h1>
            <p class="mt-1 text-[14.5px] text-muted">Print-ready assets, built from your business profile.</p>
        </div>

        <a href="{{ route('business') }}"
           class="tap focusable hidden shrink-0 items-center gap-2 rounded-full border border-border bg-surface px-5 text-[14px] font-semibold text-ink-2 hover:bg-surface-2 min-[560px]:flex">
            Edit business
        </a>
    </div>

    @include('partials.business-tabs')

    {{-- Asset switcher --}}
    <div class="no-scrollbar -mx-5 mt-5 flex gap-2 overflow-x-auto px-5 lg:mx-0 lg:px-0" role="tablist">
        @foreach (Stationery::ASSETS as $key => $label)
            <button type="button" wire:click="setAsset('{{ $key }}')" role="tab"
                    aria-selected="{{ $asset === $key ? 'true' : 'false' }}"
                    class="focusable h-10 shrink-0 rounded-full px-4 text-[13.5px] font-semibold transition-colors
                           {{ $asset === $key ? 'bg-brand text-white' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-3">

        {{-- Options --}}
        <div class="space-y-4">
            <x-ui.panel title="Options">
                @if ($asset === 'letterhead')
                    <span class="{{ $labelClass }}">Paper size</span>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach (['a4' => 'A4', 'a3' => 'A3'] as $key => $label)
                            <button type="button" wire:click="$set('size', '{{ $key }}')"
                                    class="focusable h-11 rounded-xl text-[14px] font-semibold transition-colors
                                           {{ $size === $key ? 'bg-tint-blue text-brand ring-1 ring-brand/40' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                    <p class="mt-3 text-[12.5px] text-muted">
                        Exports at full bleed through your browser's print dialog — choose "Save as PDF" for a file.
                    </p>
                @elseif ($asset === 'card')
                    <label class="block">
                        <span class="{{ $labelClass }}">Name on card</span>
                        <input type="text" wire:model.live.debounce.300ms="cardName" class="{{ $inputClass }}">
                    </label>
                    <label class="mt-3 block">
                        <span class="{{ $labelClass }}">Position</span>
                        <input type="text" wire:model.live.debounce.300ms="cardTitle" class="{{ $inputClass }}">
                    </label>
                    <p class="mt-3 text-[12.5px] text-muted">Standard 85 × 55&nbsp;mm. Both sides shown.</p>
                @elseif ($asset === 'stamp')
                    <span class="{{ $labelClass }}">Shape</span>
                    <div class="grid grid-cols-3 gap-2">
                        @foreach (['circular' => 'Circle', 'square' => 'Square', 'oval' => 'Oval'] as $key => $label)
                            <button type="button" wire:click="$set('stampShape', '{{ $key }}')"
                                    class="focusable h-11 rounded-xl text-[13.5px] font-semibold transition-colors
                                           {{ $stampShape === $key ? 'bg-tint-blue text-brand ring-1 ring-brand/40' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                @else
                    <p class="text-[13.5px] leading-relaxed text-muted">
                        Copy the signature below and paste it into Gmail, Outlook or Apple Mail. Formatting and
                        the QR travel with it.
                    </p>
                @endif
            </x-ui.panel>

            @if ($asset !== 'signature')
                <a href="{{ route('stationery.print', ['asset' => $asset, 'size' => $size, 'shape' => $stampShape, 'name' => $cardName, 'title' => $cardTitle]) }}"
                   target="_blank"
                   class="focusable flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-brand text-[15px] font-semibold text-white hover:opacity-90">
                    <x-icon name="printer" class="size-[18px]" stroke-width="2" />
                    Print / Save as PDF
                </a>
            @endif
        </div>

        {{-- Live preview --}}
        <div class="lg:col-span-2">
            <x-ui.panel title="Preview">
                {{-- Previews are white regardless of theme: this is paper. --}}
                <div class="overflow-x-auto rounded-xl bg-slate-100 p-4 dark:bg-black/40">

                    @if ($asset === 'letterhead')
                        <div class="mx-auto bg-white p-8 shadow-sm" style="width: 460px; min-height: {{ $size === 'a3' ? '650px' : '560px' }}">
                            <div class="flex items-start justify-between gap-6 border-b-2 border-slate-900 pb-5">
                                <div>
                                    <p class="text-[19px] font-extrabold tracking-tight text-slate-900">{{ $company->name }}</p>
                                    @if ($company->motto)
                                        <p class="text-[11px] text-slate-500">{{ $company->motto }}</p>
                                    @endif
                                </div>
                                <img src="{{ route('verification.qr', $token->token) }}" alt="" class="size-[54px]">
                            </div>
                            <div class="mt-3 flex justify-between text-[9.5px] text-slate-500">
                                <span>{{ implode(' · ', array_filter([$company->address_line1, $company->city])) }}</span>
                                <span>{{ implode(' · ', array_filter([$phone, $company->email])) }}</span>
                            </div>
                            <div class="mt-10 space-y-2.5">
                                @foreach ([90, 100, 96, 70, 0, 88, 94, 60] as $width)
                                    <div class="h-2 rounded bg-slate-100" style="width: {{ $width }}%"></div>
                                @endforeach
                            </div>
                            <div class="mt-auto pt-10 text-center text-[9px] text-slate-400">
                                {{ $company->website }} · {{ strtoupper($size) }} letterhead
                            </div>
                        </div>

                    @elseif ($asset === 'card')
                        <div class="flex flex-wrap justify-center gap-5">
                            {{-- Front --}}
                            <div class="flex flex-col justify-between bg-white p-5 shadow-sm" style="width: 340px; height: 220px">
                                <div>
                                    <p class="text-[17px] font-extrabold tracking-tight text-slate-900">{{ $company->name }}</p>
                                    @if ($company->motto)
                                        <p class="text-[9.5px] text-slate-500">{{ $company->motto }}</p>
                                    @endif
                                </div>
                                <div class="flex items-end justify-between gap-3">
                                    <div>
                                        <p class="text-[13px] font-bold text-slate-900">{{ $cardName }}</p>
                                        <p class="text-[10px] text-slate-500">{{ $cardTitle }}</p>
                                        <p class="mt-2 text-[9.5px] leading-relaxed text-slate-600">
                                            {{ $phone }}<br>{{ $company->email }}<br>{{ $company->website }}
                                        </p>
                                    </div>
                                    <img src="{{ route('verification.qr', $token->token) }}" alt="" class="size-[62px]">
                                </div>
                            </div>

                            {{-- Back --}}
                            <div class="flex flex-col items-center justify-center bg-slate-900 p-5 text-center shadow-sm" style="width: 340px; height: 220px">
                                <p class="text-[19px] font-extrabold tracking-tight text-white">{{ $company->name }}</p>
                                <p class="mt-1 text-[9.5px] text-slate-300">{{ $company->motto ?? $company->industry }}</p>
                                <div class="mt-3 rounded bg-white p-1.5">
                                    <img src="{{ route('verification.qr', $token->token) }}" alt="" class="size-[54px]">
                                </div>
                                <p class="mt-2 text-[8.5px] text-slate-400">Scan to verify this business</p>
                            </div>
                        </div>

                    @elseif ($asset === 'stamp')
                        @php
                            $shapeClass = match ($stampShape) {
                                'square' => 'rounded-lg',
                                'oval' => 'rounded-[50%]',
                                default => 'rounded-full',
                            };
                            $dims = $stampShape === 'oval' ? 'width: 250px; height: 170px' : 'width: 210px; height: 210px';
                        @endphp
                        <div class="flex justify-center bg-white py-8">
                            <div class="flex flex-col items-center justify-center border-[5px] border-blue-700 text-center {{ $shapeClass }}" style="{{ $dims }}">
                                <p class="px-6 text-[13px] font-extrabold uppercase leading-tight tracking-wide text-blue-700">
                                    {{ $company->name }}
                                </p>
                                <div class="my-1.5 h-px w-16 bg-blue-700"></div>
                                <p class="text-[9px] font-semibold uppercase tracking-widest text-blue-700">
                                    {{ $company->registration_number ?? $company->industry ?? 'Verified' }}
                                </p>
                                <p class="mt-1 text-[8px] uppercase tracking-wider text-blue-700">
                                    {{ $company->city ?? $company->country }}
                                </p>
                            </div>
                        </div>

                    @else
                        {{-- Email signature: real markup the user copies --}}
                        <div class="bg-white p-6">
                            <table cellpadding="0" cellspacing="0" style="font-family: Arial, sans-serif; color:#0f172a">
                                <tr>
                                    <td style="padding-right:16px; border-right:3px solid #2563eb">
                                        <img src="{{ route('verification.qr', $token->token) }}" alt="" width="84" height="84">
                                    </td>
                                    <td style="padding-left:16px">
                                        <div style="font-size:15px; font-weight:700">{{ $cardName }}</div>
                                        <div style="font-size:12px; color:#64748b">{{ $cardTitle }} · {{ $company->name }}</div>
                                        <div style="font-size:11px; color:#334155; padding-top:6px; line-height:1.6">
                                            {{ $phone }}<br>
                                            <a href="mailto:{{ $company->email }}" style="color:#2563eb; text-decoration:none">{{ $company->email }}</a><br>
                                            <a href="{{ $company->website }}" style="color:#2563eb; text-decoration:none">{{ $company->website }}</a>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    @endif
                </div>

                @if ($asset === 'signature')
                    <p class="mt-3 text-[12.5px] text-muted">
                        Select the block above, copy it, and paste into your mail client's signature settings.
                    </p>
                @endif
            </x-ui.panel>
        </div>
    </div>
</div>
