{{--
    Connection state, shown only when it is not normal. The plan's rule is that
    offline is the expected case, not an error — so this states the situation
    calmly and does not block anything.
--}}
<div x-data="{ online: true }"
     x-init="
        online = navigator.onLine;
        window.addEventListener('online', () => online = true);
        window.addEventListener('offline', () => online = false);
     "
     x-show="! online"
     x-cloak
     x-transition
     class="sticky top-0 z-40 flex items-center justify-center gap-2 bg-warning px-4 py-2 text-center text-white"
     role="status">
    <x-icon name="offline" class="size-[17px]" stroke-width="2" />
    <span class="text-[13px] font-semibold">
        You're offline — pages you've opened stay available.
    </span>
</div>
