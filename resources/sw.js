/*
 * OPES360 service worker.
 *
 * Phase 4 of the plan adds the full offline engine (IndexedDB mirror, outbox,
 * sync protocol). This is the app-shell layer it will build on: the shell and
 * assets are precached so the app launches instantly and renders something
 * useful with no connection, and a clear offline page replaces the browser's
 * dinosaur when a navigation cannot be served.
 */

/*
 * Stamped by the /sw.js route from the build manifest's hash — see routes/web.php.
 *
 * It used to be the literal 'opes360-v1', which meant this file was
 * byte-identical on every deploy. A browser only reinstalls a service worker
 * when its bytes change, so the worker never updated: `activate` never ran, no
 * old cache was ever deleted, and ASSET_CACHE accumulated every build ever
 * shipped. On the phones this is installed on, that is storage nobody asked to
 * give up. A version that moves with the release makes the activate handler
 * below do the job it was written to do.
 */
const VERSION = '__OPES_BUILD__';
const SHELL_CACHE = `${VERSION}-shell`;
const ASSET_CACHE = `${VERSION}-assets`;
const PAGE_CACHE = `${VERSION}-pages`;

const SHELL_URLS = ['/offline', '/manifest.webmanifest'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(SHELL_CACHE)
            .then((cache) => cache.addAll(SHELL_URLS))
            // A failed precache must not block activation; the runtime handlers
            // still work and will fill the cache on first successful fetch.
            .catch(() => undefined)
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter((key) => !key.startsWith(VERSION))
                        .map((key) => caches.delete(key))
                )
            )
            .then(() => self.clients.claim())
    );
});

/** Hashed build assets never change content, so cache-first is safe and fastest. */
function isBuildAsset(url) {
    return url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/');
}

/** Anything that must never be served stale. */
function isBypassed(url) {
    return (
        url.pathname.startsWith('/livewire/') ||
        url.pathname.startsWith('/api/') ||
        url.pathname.endsWith('/print')
    );
}

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    // Third-party requests and same-origin exclusions go straight to the network.
    if (url.origin !== self.location.origin || isBypassed(url)) {
        return;
    }

    if (isBuildAsset(url)) {
        event.respondWith(
            caches.match(request).then(
                (hit) =>
                    hit ||
                    fetch(request).then((response) => {
                        const copy = response.clone();
                        caches.open(ASSET_CACHE).then((cache) => cache.put(request, copy));

                        return response;
                    })
            )
        );

        return;
    }

    if (request.mode === 'navigate') {
        // Network-first: a page that can be fetched fresh always should be,
        // because balances and statuses go stale in a way assets never do.
        event.respondWith(
            fetch(request)
                .then((response) => {
                    const copy = response.clone();
                    caches.open(PAGE_CACHE).then((cache) => cache.put(request, copy));

                    return response;
                })
                .catch(() =>
                    caches
                        .match(request)
                        .then((hit) => hit || caches.match('/offline'))
                )
        );
    }
});
