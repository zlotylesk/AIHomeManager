/**
 * Registers the app's single Service Worker at /sw.js (HMAI-280, HMAI-346).
 *
 * Since HMAI-346 this is the unified PWA worker — Workbox's InjectManifest
 * builds it in the Encore pipeline and emits it to the site ROOT so its scope
 * covers every page (a hashed /build/ path would narrow the scope). One worker
 * precaches the app-shell AND handles Web Push, so registering it here is all
 * the push opt-in flow (HMAI-283) needs to find later via
 * `navigator.serviceWorker.getRegistration()`.
 *
 * Registration only installs the worker — it asks for no permission and creates
 * no subscription, so nothing user-visible happens here. In non-production
 * builds no /sw.js is emitted, so registration fails soft (the catch below).
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
