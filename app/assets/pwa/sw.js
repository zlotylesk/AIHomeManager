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
 * Offline write queueing (Background Sync) arrives in HMAI-348.
 */
import { clientsClaim } from 'workbox-core';
import { cleanupOutdatedCaches, matchPrecache, precacheAndRoute } from 'workbox-precaching';
import { NavigationRoute, registerRoute, setCatchHandler } from 'workbox-routing';
import { NetworkFirst, NetworkOnly } from 'workbox-strategies';
import { CacheableResponsePlugin } from 'workbox-cacheable-response';
import { ExpirationPlugin } from 'workbox-expiration';
import { BackgroundSyncPlugin } from 'workbox-background-sync';

const OFFLINE_URL = '/build/offline.html';
const WRITE_METHODS = ['POST', 'PATCH', 'DELETE'];
const QUEUED_MESSAGE = 'Zapiszę po powrocie online.';
const REQUIRES_NETWORK_MESSAGE = 'Ta akcja wymaga połączenia z internetem.';

// Runtime cache versioning (HMAI-351). The precache is content-hashed and self-
// invalidating (Workbox revisions + cleanupOutdatedCaches), but the runtime read
// caches persist by design — so they need an explicit lever. Bump CACHE_VERSION to
// orphan every runtime bucket at once (e.g. after an /api response-shape change
// that would make a stale cached read render wrong); the activate handler below
// deletes the orphans. The Background Sync WRITE queue is deliberately NOT
// versioned here — it is an IndexedDB queue, not a Cache Storage bucket, and
// dropping it would lose writes a user made offline.
const CACHE_VERSION = 'v1';
const RUNTIME_CACHE_PREFIX = 'aihm-runtime-';
const API_READS_CACHE = `${RUNTIME_CACHE_PREFIX}api-reads-${CACHE_VERSION}`;
const PAGES_CACHE = `${RUNTIME_CACHE_PREFIX}pages-${CACHE_VERSION}`;
const CURRENT_RUNTIME_CACHES = new Set([API_READS_CACHE, PAGES_CACHE]);
// Unversioned names used before HMAI-351 — swept once on upgrade so no client is
// left holding a "zombie" bucket the code no longer writes to.
const LEGACY_RUNTIME_CACHES = ['api-reads', 'pages'];

// Update strategy: take over as soon as a new worker installs, so a shipped
// app-shell update is never left stranded behind a still-controlling old SW.
self.skipWaiting();
clientsClaim();

// Remove precache buckets left by earlier Workbox revisions on activate.
cleanupOutdatedCaches();

// No-zombie-cache sweep (HMAI-351): on every activation delete any runtime bucket
// that is not a current one — old CACHE_VERSIONs and the pre-HMAI-351 unversioned
// names. Scoped to OUR runtime prefix + the known legacy names so it never touches
// Workbox's precache (handled above), the api-writes queue, or another origin's
// caches. This is what makes a shipped app-shell update actually reach the client
// instead of being shadowed by a stale bucket.
self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const names = await caches.keys();
            await Promise.all(
                names
                    .filter(
                        (name) =>
                            (name.startsWith(RUNTIME_CACHE_PREFIX) && !CURRENT_RUNTIME_CACHES.has(name)) ||
                            LEGACY_RUNTIME_CACHES.includes(name),
                    )
                    .map((name) => caches.delete(name)),
            );
        })(),
    );
});

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
        cacheName: API_READS_CACHE,
        networkTimeoutSeconds: 5,
        plugins: [
            new CacheableResponsePlugin({ statuses: [200] }),
            new ExpirationPlugin({ maxEntries: 60, maxAgeSeconds: 24 * 60 * 60, purgeOnQuotaError: true }),
        ],
    }),
);

// --- Offline write queue (HMAI-348) ---
// POST/PATCH/DELETE to /api/* are network-only. Offline, the request is stored in
// a Background Sync queue (IndexedDB) and replayed when connectivity returns; the
// page gets a synthetic 202 {queued:true} so it can tell the user "saved when back
// online" instead of treating the write as lost. On browsers without the Background
// Sync API there is no reliable auto-replay trigger, so we deliberately do NOT queue
// — the page gets a 503 {requiresNetwork:true} and says the action needs a
// connection (honest graceful-degrade, never a silent loss or a duplicated write).
const backgroundSyncSupported = 'sync' in self.registration;
const writeHandler = backgroundSyncSupported
    ? buildQueueingWriteHandler()
    : buildRequiresNetworkWriteHandler();

for (const method of WRITE_METHODS) {
    registerRoute(({ url }) => url.pathname.startsWith('/api/'), writeHandler, method);
}

function buildQueueingWriteHandler() {
    const strategy = new NetworkOnly({
        plugins: [new BackgroundSyncPlugin('api-writes', { maxRetentionMinutes: 24 * 60 })],
    });

    return async (args) => {
        try {
            return await strategy.handle(args);
        } catch {
            // The plugin already enqueued the request in its fetchDidFail hook; the
            // network is simply gone. Tell the page it is queued, not failed.
            return jsonResponse(202, { queued: true, message: QUEUED_MESSAGE });
        }
    };
}

function buildRequiresNetworkWriteHandler() {
    const strategy = new NetworkOnly();

    return async (args) => {
        try {
            return await strategy.handle(args);
        } catch {
            return jsonResponse(503, { queued: false, requiresNetwork: true, message: REQUIRES_NETWORK_MESSAGE });
        }
    };
}

function jsonResponse(status, body) {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

// --- Navigations (HMAI-347) ---
// Page shells are network-first too, so a previously-visited view opens offline
// (its JS then rehydrates from the cached API reads above). OAuth and the API
// itself are excluded — they must always reach the network. Never-visited views
// fall through to the catch handler below.
registerRoute(
    new NavigationRoute(
        new NetworkFirst({
            cacheName: PAGES_CACHE,
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
