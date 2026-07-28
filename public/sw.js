/*
 * OPES360 service worker.
 *
 * Phase 4 of the plan adds the full offline engine (IndexedDB mirror, outbox,
 * sync protocol). This is the app-shell layer it will build on: the shell and
 * assets are precached so the app launches instantly and renders something
 * useful with no connection, and a clear offline page replaces the browser's
 * dinosaur when a navigation cannot be served.
 */

const VERSION = 'opes360-v1';
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
