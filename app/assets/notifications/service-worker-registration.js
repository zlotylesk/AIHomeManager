/**
 * Registers the push Service Worker (HMAI-280).
 *
 * Registration only installs the worker — it asks for no permission and creates
 * no subscription, so nothing user-visible happens here. The opt-in flow
 * (Notification.requestPermission → pushManager.subscribe → POST the
 * subscription) lands with the preferences UI in HMAI-283, and needs the worker
 * to already be registered.
 *
 * The worker lives at /sw.js rather than in the Encore build so its scope covers
 * the whole site.
 */
export function registerPushServiceWorker() {
    if (!('serviceWorker' in navigator)) {
        return Promise.resolve(null);
    }

    return navigator.serviceWorker.register('/sw.js').catch((error) => {
        // A missing worker must never take the page down with it — push is an
        // enhancement, every other channel keeps working.
        console.warn('Push service worker registration failed:', error);

        return null;
    });
}
