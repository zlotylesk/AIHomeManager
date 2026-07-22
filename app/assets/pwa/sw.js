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
import { cleanupOutdatedCaches, precacheAndRoute } from 'workbox-precaching';

// Update strategy: take over as soon as a new worker installs, so a shipped
// app-shell update is never left stranded behind a still-controlling old SW.
self.skipWaiting();
clientsClaim();

// Remove precache buckets left by earlier Workbox revisions on activate.
cleanupOutdatedCaches();

// `self.__WB_MANIFEST` is replaced at build time with the hashed precache list
// (the Encore app-shell assets). Precaching gives the shell an offline baseline.
precacheAndRoute(self.__WB_MANIFEST);

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
