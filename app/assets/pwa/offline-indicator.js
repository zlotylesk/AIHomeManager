/**
 * Non-intrusive offline indicator (HMAI-347).
 *
 * A slim banner that appears while the browser reports no network, so the user
 * understands that any data on screen is the last cached copy (served by the
 * Service Worker) rather than live. The pure decision helper is Vitest-covered;
 * `initOfflineIndicator` wires it to the page and is called from `app.js`.
 */

/**
 * Whether the offline banner should be visible: only when the browser is
 * offline. `navigator.onLine` is a coarse signal (true just means "a network
 * interface exists"), but it is the standard trigger for this UX and pairs with
 * the `online`/`offline` events.
 *
 * @param {boolean} online
 * @returns {boolean}
 */
export function shouldShowOfflineBanner(online) {
    return !online;
}

export function initOfflineIndicator() {
    if (typeof window === 'undefined' || !('addEventListener' in window) || !document.body) {
        return;
    }

    const banner = document.createElement('div');
    banner.id = 'offline-indicator';
    banner.className = 'offline-indicator hidden';
    banner.setAttribute('role', 'status');
    banner.setAttribute('aria-live', 'polite');
    banner.textContent = 'Jesteś offline — pokazujemy ostatnio wczytane dane.';
    document.body.appendChild(banner);

    const update = () => {
        banner.classList.toggle('hidden', !shouldShowOfflineBanner(window.navigator.onLine));
    };

    window.addEventListener('online', update);
    window.addEventListener('offline', update);
    update();
}
