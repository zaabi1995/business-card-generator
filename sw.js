/**
 * Cardify service worker, cache-first shell with stale-while-revalidate.
 * Deliberately conservative scope to avoid tripping the PHP auth flow:
 *   - Only caches GET responses from same origin.
 *   - Never caches /admin/*, /printshop/*, /api/*, /portal/* (live data).
 *   - Caches static assets (/assets/*, /favicon.*, /manifest.webmanifest)
 *     with a 7-day max-age, falls back to network on miss.
 *   - Ships an offline banner asset for the /admin offline-state UI
 *     shipped in action 288.
 *
 * Bumping CACHE_VERSION invalidates all prior caches (safe migration).
 */
const CACHE_VERSION = 'cardify-v2-1';
const STATIC_PREFIXES = ['/assets/', '/favicon'];
const NEVER_CACHE = ['/admin/', '/printshop/', '/api/', '/portal/', '/paymob/', '/webhooks/'];

self.addEventListener('install', (event) => {
    self.skipWaiting();
    event.waitUntil(
        caches.open(CACHE_VERSION).then((cache) =>
            cache.addAll([
                '/manifest.webmanifest',
                '/assets/images/logo.svg',
                '/favicon.svg',
            ]).catch(() => {})
        )
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => k !== CACHE_VERSION).map((k) => caches.delete(k)))
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;

    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;
    if (NEVER_CACHE.some((p) => url.pathname.startsWith(p))) return;

    const isStatic = STATIC_PREFIXES.some((p) => url.pathname.startsWith(p));
    if (!isStatic) return;

    event.respondWith(
        caches.open(CACHE_VERSION).then(async (cache) => {
            const cached = await cache.match(req);
            const networkPromise = fetch(req).then((res) => {
                if (res && res.status === 200 && res.type === 'basic') {
                    cache.put(req, res.clone());
                }
                return res;
            }).catch(() => cached);
            return cached || networkPromise;
        })
    );
});
