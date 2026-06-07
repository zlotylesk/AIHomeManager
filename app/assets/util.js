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

    if (!res.ok) {
        const text = await res.text();
        let payload = null;

        try {
            payload = JSON.parse(text);
        } catch {
            // non-JSON body (HTML error page, plain text, etc.) — keep raw snippet
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
