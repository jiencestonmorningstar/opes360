{{--
    The theme, decided before first paint.

    Anything slower produces a white flash on every load in dark mode, which is
    why this is inline in the <head> rather than in the bundle.

    One copy, included by both layouts. There were two — the same eight lines
    written twice, and already drifted into two different shapes. That matters
    more than tidiness now: the policy pins these bytes by hash (see
    App\Support\Csp), so two spellings mean two hashes to keep in step, and the
    consequence of letting one slip is a script the browser refuses on every
    wire:navigate.

    `data-navigate-once` because it has nothing to do on a navigation — <html>
    survives the swap with the class already on it.
--}}
<script @cspNonce data-navigate-once>
    (function () {
        try {
            var stored = localStorage.getItem('opes-theme');
            var system = window.matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.classList.toggle('dark', stored === 'dark' || (stored !== 'light' && system));
        } catch (e) {
            /* Private mode with storage disabled — fall back to the light theme. */
        }
    })();
</script>
