{{--
    Connection and sync state.

    Shown only when something is not normal — offline is the expected case in
    this product, not an error, so a healthy online session says nothing at all.
    Unsynced work is never left implicit: a pending count stays visible until it
    clears, which is the whole point of an offline-first app the user has to
    trust with money.
--}}
<div x-data="opesSyncStatus" x-cloak
     x-show="! online || pending > 0 || failed > 0"
     x-transition
     class="sticky top-0 z-40"
     role="status" aria-live="polite">

    {{-- Offline takes precedence: it explains everything else on the bar. --}}
    <template x-if="! online">
        <div class="flex items-center justify-center gap-2 bg-fill-warning px-4 py-2 text-center text-white">
            <x-icon name="offline" class="size-[17px]" stroke-width="2" />
            <span class="text-[13px] font-semibold">
                You're offline —
                <span x-show="pending > 0"><span x-text="pending"></span> change<span x-show="pending !== 1">s</span> will sync when you're back.</span>
                <span x-show="pending === 0">pages you've opened stay available.</span>
            </span>
        </div>
    </template>

    <template x-if="online && failed > 0">
        <div class="flex items-center justify-center gap-2 bg-fill-negative px-4 py-2 text-center text-white">
            <x-icon name="alert" class="size-[17px]" stroke-width="2" />
            <span class="text-[13px] font-semibold">
                <span x-text="failed"></span> change<span x-show="failed !== 1">s</span> couldn't sync and need attention.
            </span>
        </div>
    </template>

    <template x-if="online && failed === 0 && pending > 0">
        <div class="flex items-center justify-center gap-2 bg-fill-brand px-4 py-2 text-center text-white">
            <x-icon name="sync" class="size-[16px]" stroke-width="2" ::class="running && 'animate-spin'" />
            <span class="text-[13px] font-semibold">
                Syncing <span x-text="pending"></span> change<span x-show="pending !== 1">s</span>…
            </span>
            <button type="button" @click="syncNow()" x-show="! running"
                    class="text-[12.5px] font-semibold underline underline-offset-2">
                Retry now
            </button>
        </div>
    </template>
</div>
