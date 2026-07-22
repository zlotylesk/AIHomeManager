import {
    QUEUED_EVENT,
    REQUIRES_NETWORK_EVENT,
    QUEUED_MESSAGE,
    REQUIRES_NETWORK_MESSAGE,
    isQueuedResponse,
    isRequiresNetworkResponse,
} from './pwa/queue-ux.js';

export const TOAST_TIMEOUT_MS = 5000;

export function safeUrl(url) {
    if (typeof url !== 'string' || url === '') {
        return null;
    }
    try {
        const parsed = new URL(url, document.baseURI);
        return parsed.protocol === 'https:' || parsed.protocol === 'http:' ? url : null;
    } catch {
        return null;
    }
}

export async function apiCall(url, options = {}) {
    const headers = new Headers(options.headers || {});
    const meta = document.querySelector('meta[name="api-key"]');
    const apiKey = meta ? meta.getAttribute('content') : '';
    if (apiKey && !headers.has('X-API-Key')) {
        headers.set('X-API-Key', apiKey);
    }

    const res = await fetch(url, { ...options, headers });

    // Offline write intercepted by the Service Worker (HMAI-348): a synthetic 202
    // {queued:true} (Background Sync will replay it) or 503 {requiresNetwork:true}
    // (this browser has no Background Sync). Surface it as a distinct, non-success
    // outcome — the caller must never render the write as saved — and fire the
    // shared queue toast. A real 202 (async import) / real 503 carries no marker
    // and falls through untouched.
    if (202 === res.status || 503 === res.status) {
        const marker = await res.clone().json().catch(() => null);

        if (isQueuedResponse(res.status, marker)) {
            return signalQueuedWrite(QUEUED_EVENT, marker.message || QUEUED_MESSAGE, { queued: true });
        }
        if (isRequiresNetworkResponse(res.status, marker)) {
            return signalQueuedWrite(REQUIRES_NETWORK_EVENT, marker.message || REQUIRES_NETWORK_MESSAGE, { requiresNetwork: true });
        }
    }

    if (!res.ok) {
        const text = await res.text();
        let payload = null;

        try {
            payload = JSON.parse(text);
        } catch {
        }

        const message = payload && typeof payload.error === 'string'
            ? payload.error
            : `API ${res.status}: ${text.slice(0, 200)}`;
        const error = new Error(message);
        error.status = res.status;
        error.body = payload ?? text;
        throw error;
    }

    if (res.status === 204) {
        return null;
    }

    return res.json();
}

export function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function signalQueuedWrite(eventName, message, flags) {
    if ('undefined' !== typeof window && 'function' === typeof window.dispatchEvent) {
        window.dispatchEvent(new CustomEvent(eventName, { detail: { message } }));
    }

    const error = new Error(message);
    Object.assign(error, flags, { handled: true });
    throw error;
}
