/*
 * Unified PWA Service Worker (HMAI-346).
 *
 * Built by Workbox's InjectManifest inside the Encore pipeline and emitted to
 * the site ROOT (`public/sw.js`, via `swDest`) so its scope covers every page —
 * the same reason the previous hand-written push worker lived at the root (a
 * hashed `/build/` path would narrow the scope and break registration). This
 * single worker does both jobs: it precaches the app-shell (Encore's hashed
 * statics) AND handles Web Push, absorbing the notification handlers that used
 * to live in the standalone `public/sw.js` (HMAI-280).
 *
 * Runtime caching of API reads + the offline fallback page arrive in HMAI-347.
 */
import { clientsClaim } from 'workbox-core';
import { cleanupOutdatedCaches, matchPrecache, precacheAndRoute } from 'workbox-precaching';
import { NavigationRoute, registerRoute, setCatchHandler } from 'workbox-routing';
import { NetworkFirst } from 'workbox-strategies';
import { CacheableResponsePlugin } from 'workbox-cacheable-response';
import { ExpirationPlugin } from 'workbox-expiration';

const OFFLINE_URL = '/build/offline.html';

// Update strategy: take over as soon as a new worker installs, so a shipped
// app-shell update is never left stranded behind a still-controlling old SW.
self.skipWaiting();
clientsClaim();

// Remove precache buckets left by earlier Workbox revisions on activate.
cleanupOutdatedCaches();

// `self.__WB_MANIFEST` is replaced at build time with the hashed precache list
// (the Encore app-shell assets, incl. the offline page). Precaching gives the
// shell an offline baseline.
precacheAndRoute(self.__WB_MANIFEST);

// --- Runtime caching of API reads (HMAI-347) ---
// GET /api/* is network-first: fresh while online, last cached copy when the
// network is gone or too slow. Only 200s are stored, and the cache is bounded
// (entries + age) so it can never grow without limit. Writes (POST/PATCH/DELETE)
// are deliberately NOT cached — that is the offline-queue's job (HMAI-348).
registerRoute(
    ({ url, request }) => 'GET' === request.method && url.pathname.startsWith('/api/'),
    new NetworkFirst({
        cacheName: 'api-reads',
        networkTimeoutSeconds: 5,
        plugins: [
            new CacheableResponsePlugin({ statuses: [200] }),
            new ExpirationPlugin({ maxEntries: 60, maxAgeSeconds: 24 * 60 * 60, purgeOnQuotaError: true }),
        ],
    }),
);

// --- Navigations (HMAI-347) ---
// Page shells are network-first too, so a previously-visited view opens offline
// (its JS then rehydrates from the cached API reads above). OAuth and the API
// itself are excluded — they must always reach the network. Never-visited views
// fall through to the catch handler below.
registerRoute(
    new NavigationRoute(
        new NetworkFirst({
            cacheName: 'pages',
            networkTimeoutSeconds: 5,
            plugins: [new ExpirationPlugin({ maxEntries: 30, maxAgeSeconds: 7 * 24 * 60 * 60 })],
        }),
        { denylist: [/^\/auth\//, /^\/api\//] },
    ),
);

// A navigation that cannot be served (no network, not cached) shows the
// dedicated offline page instead of the browser's error screen.
setCatchHandler(async ({ request }) => {
    if ('navigate' === request.mode) {
        return (await matchPrecache(OFFLINE_URL)) || Response.error();
    }

    return Response.error();
});

// --- Web Push (absorbed verbatim from the former public/sw.js, HMAI-280) ---
// The payload shape is the JSON envelope WebPushNotificationChannel encodes:
// { title, body, url? }.
self.addEventListener('push', (event) => {
    let payload = {};

    try {
        payload = event.data ? event.data.json() : {};
    } catch {
        // A push with no readable payload still deserves a visible notification —
        // most browsers reveal the subscription if we show nothing at all.
        payload = {};
    }

    const title = payload.title || 'AIHomeManager';
    const options = {
        body: payload.body || '',
        icon: '/build/icons/icon-192.png',
        badge: '/build/icons/icon-192.png',
        tag: payload.tag || undefined,
        data: { url: typeof payload.url === 'string' ? payload.url : null },
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = event.notification.data && event.notification.data.url;

    if (!url) {
        return;
    }

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            for (const client of windows) {
                if (client.url === url && 'focus' in client) {
                    return client.focus();
                }
            }

            return self.clients.openWindow(url);
        }),
    );
});
