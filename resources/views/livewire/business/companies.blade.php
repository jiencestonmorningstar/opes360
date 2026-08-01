@php
    use App\Support\Accent;
    use Illuminate\Support\Facades\Storage;

    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[14.5px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Businesses</h1>
            <p class="mt-1 text-[14.5px] text-muted">Each one keeps its own records, branding and numbering.</p>
        </div>

        @unless ($creating)
            <button type="button" wire:click="startCreating"
                    class="tap focusable flex shrink-0 items-center gap-2 rounded-full bg-fill-brand px-5 text-[14.5px] font-semibold text-white hover:opacity-90">
                <x-icon name="plus" class="size-[18px]" stroke-width="2.4" />
                <span class="hidden min-[420px]:inline">Add Business</span>
                <span class="min-[420px]:hidden">Add</span>
            </button>
        @endunless
    </div>

    @include('partials.business-tabs')

    @error('switch')
        <div class="mt-4 rounded-xl bg-tint-orange px-4 py-3 text-[13.5px] font-semibold text-warning">{{ $message }}</div>
    @enderror

    <div class="mx-auto mt-5 max-w-[720px] space-y-4">

        @if ($creating)
            <x-ui.panel title="New business">
                <x-slot:actions>
                    <button type="button" wire:click="cancelCreating"
                            class="focusable text-[14px] font-semibold text-muted hover:text-ink">Cancel</button>
                </x-slot:actions>

                <div class="space-y-4">
                    <label class="block">
                        <span class="{{ $labelClass }}">Business name</span>
                        <input type="text" wire:model="newName" class="{{ $inputClass }}">
                        @error('newName') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                    </label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block">
                            <span class="{{ $labelClass }}">Industry</span>
                            <select wire:model="newIndustry" class="{{ $inputClass }}">
                                <option value="">Choose…</option>
                                @foreach (config('opes.industries') as $industry)
                                    <option value="{{ $industry }}">{{ $industry }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="{{ $labelClass }}">Currency</span>
                            <select wire:model="newCurrency" class="{{ $inputClass }}">
                                @foreach (config('opes.currencies') as $code)
                                    <option value="{{ $code }}">{{ $code }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                </div>

                <button type="button" wire:click="createCompany" wire:loading.attr="disabled"
                        class="focusable mt-5 flex h-12 w-full items-center justify-center rounded-xl bg-fill-brand text-[15px] font-semibold text-white hover:opacity-90">
                    <span wire:loading.remove wire:target="createCompany">Create Business</span>
                    <span wire:loading wire:target="createCompany">Creating…</span>
                </button>
            </x-ui.panel>
        @endif

        <div class="card p-2">
            @foreach ($companies as $index => $item)
                @php $isCurrent = $item->id === $currentId; @endphp
                <div wire:key="co-{{ $item->id }}"
                     class="flex items-center gap-3.5 rounded-xl px-3 py-3 {{ $index > 0 ? 'border-t border-border' : '' }} {{ $isCurrent ? 'bg-tint-blue' : '' }}">
                    @if ($item->logo_path)
                        <img src="{{ Storage::disk('public')->url($item->logo_path) }}" alt=""
                             class="size-[46px] shrink-0 rounded-xl object-cover">
                    @else
                        <span class="flex size-[46px] shrink-0 items-center justify-center rounded-xl {{ Accent::tint(Accent::forKey($item->id)) }} text-[15px] font-bold {{ Accent::text(Accent::forKey($item->id)) }}">
                            {{ $item->initials() }}
                        </span>
                    @endif

                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[15px] font-semibold text-ink">{{ $item->name }}</span>
                        <span class="block truncate text-[13px] text-muted">
                            {{ implode(' · ', array_filter([$item->industry, $item->currency])) }}
                        </span>
                    </span>

                    @if ($isCurrent)
                        <span class="shrink-0 rounded-full bg-fill-brand px-3 py-1 text-[11.5px] font-semibold text-white">Current</span>
                    @else
                        <button type="button" wire:click="switchTo('{{ $item->id }}')"
                                class="focusable shrink-0 rounded-full border border-border bg-surface px-4 py-1.5 text-[13px] font-semibold text-ink-2 hover:bg-surface-2">
                            Switch
                        </button>
                    @endif
                </div>
            @endforeach
        </div>

        <p class="text-center text-[12.5px] text-muted">
            Switching changes which business you're working in. Nothing is shared between them.
        </p>
    </div>
</div>
