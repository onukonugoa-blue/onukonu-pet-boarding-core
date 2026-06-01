/**
 * OPB Service Worker — static asset cache only.
 *
 * Strategy:
 *   - Plugin JS/CSS bundles  → cache-first (immutable hashed filenames)
 *   - Plugin images/icons    → cache-first
 *   - /wp-json/  REST calls  → network only (never cached)
 *   - Everything else        → network only
 *
 * The cache is versioned by OPB_VERSION embedded at plugin build time.
 * A hard refresh or plugin update bumps the version and purges stale caches.
 */

const CACHE_VERSION  = 'opb-1.3.0';
const ASSET_ORIGIN   = self.location.origin;
const ASSET_PATTERNS = [
    /\/wp-content\/plugins\/onukonu-pet-boarding-core\/assets\//,
];
const NEVER_CACHE = [
    /\/wp-json\//,
    /\/wp-admin\//,
    /\/wp-login\.php/,
];

// ── Install: activate immediately ─────────────────────────────────────────────
self.addEventListener('install', () => self.skipWaiting());

// ── Activate: purge old caches ────────────────────────────────────────────────
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(
                keys
                    .filter((k) => k.startsWith('opb-') && k !== CACHE_VERSION)
                    .map((k) => caches.delete(k))
            )
        ).then(() => self.clients.claim())
    );
});

// ── Fetch: cache-first for static plugin assets ───────────────────────────────
self.addEventListener('fetch', (event) => {
    const { request } = event;

    // Only handle GET
    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    // Never intercept non-same-origin or excluded paths
    if (url.origin !== ASSET_ORIGIN) return;
    if (NEVER_CACHE.some((p) => p.test(url.pathname))) return;

    // Cache-first only for matching plugin asset paths
    const isAsset = ASSET_PATTERNS.some((p) => p.test(url.pathname));
    if (!isAsset) return;

    event.respondWith(
        caches.open(CACHE_VERSION).then((cache) =>
            cache.match(request).then((cached) => {
                if (cached) return cached;

                return fetch(request).then((response) => {
                    // Only cache valid 2xx responses
                    if (response && response.status === 200 && response.type === 'basic') {
                        cache.put(request, response.clone());
                    }
                    return response;
                });
            })
        )
    );
});
