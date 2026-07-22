/*
 * Offline-write queue UX (HMAI-348).
 *
 * The Service Worker (sw.js) queues an offline POST/PATCH/DELETE to /api/* in a
 * Background Sync queue and answers the page with a synthetic 202 {queued:true}
 * (it will replay when connectivity returns) or, on a browser without the
 * Background Sync API, a 503 {requiresNetwork:true}. apiCall() (util.js) turns
 * those into a `pwa:queued` / `pwa:requires-network` window event; this module
 * renders the matching toast, so every frontend track (the Encore bundle and the
 * legacy vanilla pages) shares one banner instead of each inventing its own.
 */

export const QUEUED_MESSAGE = 'Zapiszę po powrocie online.';
export const REQUIRES_NETWORK_MESSAGE = 'Ta akcja wymaga połączenia z internetem.';

export const QUEUED_EVENT = 'pwa:queued';
export const REQUIRES_NETWORK_EVENT = 'pwa:requires-network';

// Pure. The SW marks its synthetic responses so a real 202 (e.g. an async import
// accepted, {status:'import_started'}) or a genuine 503 is never mistaken for a
// queued write.
export function isQueuedResponse(status, body) {
    return 202 === status && !!body && true === body.queued;
}

export function isRequiresNetworkResponse(status, body) {
    return 503 === status && !!body && true === body.requiresNetwork;
}

let container = null;

function ensureContainer() {
    if (container && document.body.contains(container)) {
        return container;
    }

    container = document.createElement('div');
    container.className = 'queue-toast';
    container.setAttribute('role', 'status');
    container.setAttribute('aria-live', 'polite');
    document.body.appendChild(container);

    return container;
}

function showToast(message, variant) {
    const el = ensureContainer();
    el.textContent = message;
    el.dataset.variant = variant;
    el.classList.add('is-visible');

    window.clearTimeout(el._hideTimer);
    el._hideTimer = window.setTimeout(() => el.classList.remove('is-visible'), 5000);
}

export function initQueueUx() {
    window.addEventListener(QUEUED_EVENT, (event) => {
        showToast((event.detail && event.detail.message) || QUEUED_MESSAGE, 'queued');
    });
    window.addEventListener(REQUIRES_NETWORK_EVENT, (event) => {
        showToast((event.detail && event.detail.message) || REQUIRES_NETWORK_MESSAGE, 'requires-network');
    });
}
