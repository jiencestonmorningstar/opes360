<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center gap-3">
        <a href="{{ route('dashboard') }}"
           class="tap focusable -ml-2 flex items-center justify-center rounded-lg text-muted hover:text-ink" aria-label="Back">
            <x-icon name="chevron-left" class="size-[22px]" stroke-width="2.2" />
        </a>
        <div>
            <h1 class="text-[22px] font-bold leading-tight tracking-[-0.02em] text-ink lg:text-[25px]">Scan a code</h1>
            <p class="mt-0.5 text-[13.5px] text-muted">Check any OPES360 document, receipt or business.</p>
        </div>
    </div>

    <div class="mx-auto mt-5 max-w-[520px] space-y-4"
         x-data="{
            supported: 'BarcodeDetector' in window,
            scanning: false,
            message: '',
            stream: null,

            async start() {
                this.message = '';
                try {
                    this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
                } catch (e) {
                    // Denied or unavailable — the manual field still works.
                    this.message = 'Camera unavailable. Enter the code below instead.';
                    return;
                }

                this.scanning = true;
                this.$refs.video.srcObject = this.stream;
                await this.$refs.video.play();

                const detector = new BarcodeDetector({ formats: ['qr_code'] });

                const tick = async () => {
                    if (! this.scanning) return;
                    try {
                        const codes = await detector.detect(this.$refs.video);
                        if (codes.length) {
                            this.stop();
                            $wire.set('code', codes[0].rawValue);
                            $wire.lookup();
                            return;
                        }
                    } catch (e) { /* transient decode failure; keep polling */ }
                    requestAnimationFrame(tick);
                };

                requestAnimationFrame(tick);
            },

            stop() {
                this.scanning = false;
                this.stream?.getTracks().forEach(t => t.stop());
                this.stream = null;
            },
         }"
         x-on:destroy="stop()">

        <x-ui.panel title="Camera">
            <template x-if="supported">
                <div>
                    <div class="relative overflow-hidden rounded-xl bg-black" x-show="scanning" x-cloak>
                        <video x-ref="video" class="aspect-square w-full object-cover" muted playsinline></video>
                        <div class="pointer-events-none absolute inset-8 rounded-2xl border-2 border-white/80"></div>
                    </div>

                    <button type="button" x-show="! scanning" @click="start()"
                            class="focusable flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-fill-brand text-[15px] font-semibold text-white hover:opacity-90">
                        <x-icon name="qr-code" class="size-[19px]" stroke-width="2" />
                        Start camera
                    </button>

                    <button type="button" x-show="scanning" x-cloak @click="stop()"
                            class="focusable mt-3 flex h-12 w-full items-center justify-center rounded-xl bg-surface-2 text-[15px] font-semibold text-ink-2 hover:bg-tint-blue hover:text-brand">
                        Stop
                    </button>

                    <p class="mt-2 text-[12.5px] text-warning" x-text="message" x-show="message" x-cloak></p>
                </div>
            </template>

            <template x-if="! supported">
                <p class="text-[13.5px] leading-relaxed text-muted">
                    This browser cannot scan from the camera. Use your phone's camera app to open the code,
                    or paste it below — both land in the same place.
                </p>
            </template>
        </x-ui.panel>

        <x-ui.panel title="Or enter the code">
            <div class="flex gap-2">
                <input type="text" wire:model="code" wire:keydown.enter="lookup"
                       placeholder="Code or verification link"
                       class="h-12 min-w-0 flex-1 rounded-xl border border-border bg-surface px-3.5 text-[14.5px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                <button type="button" wire:click="lookup"
                        class="tap focusable flex shrink-0 items-center justify-center rounded-xl bg-fill-brand px-5 text-[14.5px] font-semibold text-white hover:opacity-90">
                    Check
                </button>
            </div>
            @error('code') <p class="mt-2 text-[13px] font-medium text-warning">{{ $message }}</p> @enderror
        </x-ui.panel>
    </div>
</div>
