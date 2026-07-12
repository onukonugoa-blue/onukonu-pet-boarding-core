/**
 * OPB Service Worker — static asset cache only.
 *
 * Strategy:
 *   - Plugin JS/CSS bundles  → cache-first (immutable hashed filenames)
 *   - Plugin images/icons    → cache-first
 *   - App manifest & SW self → cache-first
 *   - /wp-json/  REST calls  → network only (never cached)
 *   - Everything else        → network only
 *
 * Push notification foundation is present but subscriptions are not
 * activated. The event listeners are stubs ready for future use.
 */

const CACHE_VERSION  = 'opb-2.0.6';
const ASSET_ORIGIN   = self.location.origin;
const ASSET_PATTERNS = [
    /\/wp-content\/plugins\/onukonu-pet-boarding-core\/assets\//,
    /\/opb-manifest\.json$/,
    /\/opb-sw\.js$/,
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
                    // Only cache valid 2xx same-origin responses
                    if (response && response.status === 200 && response.type === 'basic') {
                        cache.put(request, response.clone());
                    }
                    return response;
                });
            })
        )
    );
});

// ── Push notification foundation (stubs — not yet activated) ──────────────────
//
// These listeners are intentionally empty. When push notifications are
// enabled in a future release, the subscription flow (VAPID key exchange,
// PushManager.subscribe, server-side endpoint registration) will be added
// without changing this structural skeleton.

self.addEventListener('push', (event) => {
    if (!event.data) return;

    let payload;
    try {
        payload = event.data.json();
    } catch {
        payload = { title: 'OPB', body: event.data.text() };
    }

    const title   = payload.title   ?? 'OPB';
    const options = {
        body:  payload.body  ?? '',
        icon:  payload.icon  ?? '/wp-content/plugins/onukonu-pet-boarding-core/assets/icons/icon-192.png',
        badge: payload.badge ?? '/wp-content/plugins/onukonu-pet-boarding-core/assets/icons/icon-192.png',
        tag:   payload.tag   ?? 'opb-notification',
        data:  payload.data  ?? {},
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const targetUrl = event.notification.data?.url ?? '/portal/';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            for (const client of clientList) {
                if (client.url.includes('/portal/') && 'focus' in client) {
                    client.navigate(targetUrl);
                    return client.focus();
                }
            }
            if (clients.openWindow) {
                return clients.openWindow(targetUrl);
            }
        })
    );
});

self.addEventListener('pushsubscriptionchange', (event) => {
    // Future: re-subscribe and update server-side endpoint
    event.waitUntil(Promise.resolve());
});
