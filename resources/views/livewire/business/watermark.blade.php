<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div>
        <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Watermark</h1>
        <p class="mt-1 text-[14.5px] text-muted">
            A faint background seal on your printed documents — generate one from your business details, or upload your own.
        </p>
    </div>

    @include('partials.business-tabs')

    @if (session('watermarkStatus'))
        <div class="mt-4 rounded-xl bg-tint-green px-4 py-3 text-[13.5px] font-semibold text-positive">
            {{ session('watermarkStatus') }}
        </div>
    @endif

    <div class="mt-4 grid gap-4 lg:grid-cols-3">

        <div class="space-y-4 lg:col-span-2">
            <x-ui.panel title="Generate a seal">
                <span class="mb-1.5 block text-[13px] font-semibold text-ink-2">Design</span>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    @foreach ($designs as $key => $config)
                        <button type="button" wire:click="chooseDesign('{{ $key }}')"
                                class="focusable rounded-xl border-2 p-3 text-left transition-colors
                                       {{ $design === $key ? 'border-brand bg-tint-blue' : 'border-border bg-surface hover:bg-surface-2' }}">
                            <span class="block text-[13.5px] font-semibold text-ink">{{ $config['label'] }}</span>
                            <span class="mt-0.5 block text-[12px] text-muted">{{ $config['description'] }}</span>
                        </button>
                    @endforeach
                </div>

                <label class="mb-1.5 mt-4 block text-[13px] font-semibold text-ink-2">
                    Bottom text (optional — defaults to your registration number)
                </label>
                <input type="text" wire:model.blur="bottomText" maxlength="60" placeholder="RC 12345"
                       class="w-full rounded-xl border border-border bg-surface px-3 py-2 text-[13.5px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">

                <button type="button" wire:click="saveGenerated"
                        class="focusable mt-4 w-full rounded-xl bg-fill-brand py-2.5 text-[13.5px] font-semibold text-white hover:opacity-90">
                    Generate &amp; save
                </button>
            </x-ui.panel>

            <x-ui.panel title="Or upload your own">
                <input type="file" wire:model="upload" accept="image/*"
                       class="block w-full text-[13px] text-ink-2 file:mr-3 file:rounded-lg file:border-0 file:bg-surface-2 file:px-3 file:py-2 file:text-[13px] file:font-semibold file:text-ink-2">
                @error('upload') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                <p class="mt-2 text-[12.5px] text-faint">PNG, JPG or WEBP, up to 4 MB. A pale, low-contrast image works best behind text.</p>

                <button type="button" wire:click="saveUpload" wire:target="upload" wire:loading.attr="disabled"
                        class="focusable mt-3 w-full rounded-xl border border-border bg-surface py-2.5 text-[13.5px] font-semibold text-ink-2 hover:bg-surface-2">
                    Upload &amp; save
                </button>
            </x-ui.panel>
        </div>

        <div class="space-y-4">
            <x-ui.panel title="Preview">
                <div class="flex items-center justify-center rounded-xl bg-surface-2 p-6">
                    <div class="w-full max-w-[220px] opacity-70">{!! $preview !!}</div>
                </div>
            </x-ui.panel>

            <x-ui.panel title="On your documents">
                <div class="flex items-center justify-between text-[14px]">
                    <span class="text-ink-2">Show watermark when printing</span>
                    <button type="button" wire:click="toggle"
                            class="focusable relative h-6 w-11 rounded-full transition-colors {{ $showWatermark ? 'bg-fill-brand' : 'bg-surface-2' }}"
                            aria-label="Toggle watermark on printed documents">
                        <span class="absolute top-0.5 size-5 rounded-full bg-white shadow transition-transform {{ $showWatermark ? 'translate-x-5' : 'translate-x-0.5' }}"></span>
                    </button>
                </div>
                @if (! $company->watermark_path)
                    <p class="mt-2 text-[12.5px] text-faint">Generate or upload a watermark above before turning this on.</p>
                @endif

                @if ($company->watermark_path)
                    <button type="button" wire:click="remove" wire:confirm="Remove the watermark from your account?"
                            class="focusable mt-3 w-full rounded-xl py-2 text-[13px] font-semibold text-warning hover:bg-tint-red">
                        Remove watermark
                    </button>
                @endif
            </x-ui.panel>
        </div>
    </div>
</div>
