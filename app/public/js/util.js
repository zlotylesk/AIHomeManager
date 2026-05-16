'use strict';

window.apiCall = async function apiCall(url, options = {}) {
    const res = await fetch(url, options);

    if (!res.ok) {
        const text = await res.text();
        let payload = null;

        try {
            payload = JSON.parse(text);
        } catch (_) {
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
};
