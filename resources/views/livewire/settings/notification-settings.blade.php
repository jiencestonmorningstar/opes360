<div class="px-5 pb-8 lg:px-6 lg:pt-6">
    <div class="mx-auto max-w-2xl">

        <a href="{{ route('settings') }}" wire:navigate
           class="focusable -ml-1 inline-flex items-center gap-1.5 rounded-lg p-1 text-[14px] font-semibold text-muted hover:text-ink">
            <x-icon name="chevron-left" class="size-[16px]" stroke-width="2.2" />
            Settings
        </a>

        <h1 class="mt-3 text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Your notifications</h1>
        <p class="mt-1 text-[14.5px] leading-relaxed text-muted">
            What you want to hear about, and when. These are your own settings and apply to this business only.
        </p>

        @if (session('status'))
            <div class="mt-5 rounded-xl bg-tint-green px-4 py-3 text-[14px] font-semibold text-positive">
                {{ session('status') }}
            </div>
        @endif

        {{-- Said plainly, because somebody who cannot see why an alert keeps
             arriving assumes the switch is broken and filters the sender —
             which hides the ones that actually need a decision. --}}
        <div class="mt-5 rounded-xl bg-tint-blue px-4 py-3 text-[13.5px] leading-relaxed text-ink">
            Anything waiting on <strong>your decision</strong> always comes through, whatever you set below.
            Everything else respects these settings.
        </div>

        <div class="card mt-5 p-0">
            <div class="hidden border-b border-border px-4 py-2.5 sm:flex">
                <span class="flex-1 text-[12.5px] font-semibold uppercase tracking-wide text-faint">Category</span>
                @foreach ($channels as $key => $channel)
                    <span class="w-24 text-center text-[12.5px] font-semibold uppercase tracking-wide text-faint">{{ $channel['label'] }}</span>
                @endforeach
            </div>

            @foreach ($categories as $key => $definition)
                <div class="flex flex-col gap-2 border-b border-border px-4 py-3 last:border-0 sm:flex-row sm:items-center" wire:key="cat-{{ $key }}">
                    <span class="min-w-0 flex-1">
                        <span class="block text-[14px] font-semibold text-ink">{{ $definition['label'] }}</span>
                        <span class="block text-[12.5px] text-muted">{{ $definition['description'] }}</span>
                    </span>

                    <span class="flex items-center gap-6 sm:gap-0">
                        @foreach ($channels as $channelKey => $channel)
                            <label class="flex items-center gap-2 sm:w-24 sm:justify-center">
                                <input type="checkbox" wire:model="wanted.{{ $key }}.{{ $channelKey }}"
                                       class="size-[18px] rounded border-border text-fill-brand" />
                                <span class="text-[13px] text-muted sm:hidden">{{ $channel['label'] }}</span>
                            </label>
                        @endforeach
                    </span>
                </div>
            @endforeach
        </div>

        @if (count($unavailable))
            <p class="mt-2 text-[12.5px] leading-relaxed text-faint">
                {{ collect($unavailable)->pluck('label')->implode(' and ') }}
                {{ count($unavailable) === 1 ? 'is' : 'are' }} not connected on this account.
            </p>
        @endif

        <div class="card mt-5 p-5">
            <p class="text-[15px] font-bold text-ink">How often</p>

            <div class="mt-3 space-y-2">
                <label class="flex items-start gap-2.5">
                    <input type="radio" wire:model="mode" value="immediate" class="mt-0.5 size-[18px] text-fill-brand" />
                    <span>
                        <span class="block text-[14px] font-semibold text-ink">As they happen</span>
                        <span class="block text-[12.5px] text-muted">Each one arrives on its own.</span>
                    </span>
                </label>
                <label class="flex items-start gap-2.5">
                    <input type="radio" wire:model="mode" value="digest" class="mt-0.5 size-[18px] text-fill-brand" />
                    <span>
                        <span class="block text-[14px] font-semibold text-ink">One summary</span>
                        <span class="block text-[12.5px] text-muted">Held and sent together, so a busy morning is one message instead of twelve.</span>
                    </span>
                </label>
            </div>

            <p class="mt-5 text-[15px] font-bold text-ink">Quiet hours</p>
            <p class="mt-1 text-[13px] leading-relaxed text-muted">
                Nothing is lost — anything that arrives during these hours is held and delivered when they end.
            </p>

            <div class="mt-3 flex items-center gap-3">
                <label class="text-[13px] font-semibold text-ink">From
                    <input type="time" wire:model="quietFrom"
                           class="ml-1.5 h-11 rounded-xl border border-border bg-surface px-3 text-[14px] text-ink" />
                </label>
                <label class="text-[13px] font-semibold text-ink">until
                    <input type="time" wire:model="quietTo"
                           class="ml-1.5 h-11 rounded-xl border border-border bg-surface px-3 text-[14px] text-ink" />
                </label>
            </div>
            @error('quietTo') <p class="mt-1 text-[12.5px] text-negative">{{ $message }}</p> @enderror

            <button type="button" wire:click="save"
                    class="tap focusable mt-5 flex h-11 items-center rounded-xl bg-fill-brand px-5 text-[14px] font-semibold text-white">
                Save
            </button>
        </div>
    </div>
</div>
