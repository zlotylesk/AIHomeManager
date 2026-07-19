/*
 * Service Worker for Web Push notifications (HMAI-280).
 *
 * Served from the site root so its scope covers every page. It is deliberately
 * NOT built by Encore: a hashed/nested bundle path would narrow the scope and
 * break registration.
 *
 * The payload shape is the JSON envelope WebPushNotificationChannel encodes:
 * { title, body, url? }.
 */

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
        icon: '/favicon.ico',
        badge: '/favicon.ico',
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
