'use strict';

window.TOAST_TIMEOUT_MS = 5000;

window.safeUrl = function safeUrl(url) {
    if (typeof url !== 'string' || url === '') {
        return null;
    }
    try {
        const parsed = new URL(url, document.baseURI);
        return parsed.protocol === 'https:' || parsed.protocol === 'http:' ? url : null;
    } catch {
        return null;
    }
};

window.apiCall = async function apiCall(url, options = {}) {
    const headers = new Headers(options.headers || {});
    const meta = document.querySelector('meta[name="api-key"]');
    const apiKey = meta ? meta.getAttribute('content') : '';
    if (apiKey && !headers.has('X-API-Key')) {
        headers.set('X-API-Key', apiKey);
    }

    const res = await fetch(url, { ...options, headers });

    // Offline write intercepted by the Service Worker (HMAI-348) — mirrors the
    // Encore assets/pwa/queue-ux.js contract (this legacy vanilla file cannot import
    // ES modules). A synthetic 202 {queued:true} or 503 {requiresNetwork:true} is
    // surfaced as a distinct non-success outcome + the shared queue toast event; a
    // real 202 (async import) / real 503 carries no marker and falls through.
    if (res.status === 202 || res.status === 503) {
        let marker = null;
        try {
            marker = await res.clone().json();
        } catch (_) {
        }

        if (res.status === 202 && marker && marker.queued === true) {
            return signalQueuedWrite('pwa:queued', marker.message || 'Zapiszę po powrocie online.', { queued: true });
        }
        if (res.status === 503 && marker && marker.requiresNetwork === true) {
            return signalQueuedWrite('pwa:requires-network', marker.message || 'Ta akcja wymaga połączenia z internetem.', { requiresNetwork: true });
        }
    }

    if (!res.ok) {
        const text = await res.text();
        let payload = null;

        try {
            payload = JSON.parse(text);
        } catch (_) {
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
};

function signalQueuedWrite(eventName, message, flags) {
    if (typeof window !== 'undefined' && typeof window.dispatchEvent === 'function') {
        window.dispatchEvent(new CustomEvent(eventName, { detail: { message } }));
    }

    const error = new Error(message);
    Object.assign(error, flags, { handled: true });
    throw error;
}
