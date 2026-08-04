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

// --- list envelope {data, pagination} -------------------------------------
//
// The legacy track is loaded as plain <script> tags and cannot import the ESM
// assets/pagination.js, so the same helpers exist here too. That is a duplicate
// with a known owner: HMAI-414 unifies the legacy and Encore helper sets, and
// these move with escHtml/apiCall/safeUrl when it does.

window.unwrapPage = function unwrapPage(payload) {
    if (Array.isArray(payload)) {
        return { items: payload, pagination: { page: 1, perPage: payload.length || 1, total: payload.length, totalPages: 1 } };
    }
    if (payload && Array.isArray(payload.data)) {
        return {
            items: payload.data,
            pagination: payload.pagination
                || { page: 1, perPage: payload.data.length || 1, total: payload.data.length, totalPages: 1 },
        };
    }

    return { items: [], pagination: { page: 1, perPage: 1, total: 0, totalPages: 1 } };
};

// Polish declension, teens exception included — the same rule as itemNoun() in
// assets/pagination.js. It is spelled out rather than left as a flat "pozycji"
// because the pager only appears past the first page, which is exactly where
// totals like 52 fall in the "pozycje" bucket.
window.itemNoun = function itemNoun(count) {
    const n = Math.abs(Number(count) || 0);
    const last = n % 10;
    const lastTwo = n % 100;

    if (n === 1) {
        return 'pozycja';
    }
    if (last >= 2 && last <= 4 && !(lastTwo >= 12 && lastTwo <= 14)) {
        return 'pozycje';
    }

    return 'pozycji';
};

window.fetchAllPages = async function fetchAllPages(fetchPage) {
    const first = window.unwrapPage(await fetchPage(1));
    const totalPages = Number(first.pagination && first.pagination.totalPages) || 1;
    const items = first.items.slice();

    for (let page = 2; page <= totalPages; ++page) {
        items.push(...window.unwrapPage(await fetchPage(page)).items);
    }

    return items;
};

window.mountPagerAfter = function mountPagerAfter(listEl, pagination, onPage) {
    if (!listEl || !listEl.parentNode) {
        return;
    }

    let host = listEl.nextElementSibling;
    if (!host || !host.classList.contains('pager-host')) {
        host = document.createElement('div');
        host.className = 'pager-host';
        listEl.parentNode.insertBefore(host, listEl.nextSibling);
    }

    host.innerHTML = '';

    const totalPages = Number(pagination && pagination.totalPages) || 1;
    if (totalPages <= 1) {
        return;
    }

    const page = Number(pagination.page) || 1;
    const nav = document.createElement('div');
    nav.className = 'pager';

    const prev = document.createElement('button');
    prev.type = 'button';
    prev.className = 'pager-prev';
    prev.textContent = '‹ Poprzednia';
    prev.disabled = page <= 1;
    prev.addEventListener('click', () => onPage(page - 1));

    const label = document.createElement('span');
    label.className = 'pager-label';
    const total = Number(pagination.total) || 0;
    label.textContent = `Strona ${page} z ${totalPages} · ${total} ${window.itemNoun(total)}`;

    const next = document.createElement('button');
    next.type = 'button';
    next.className = 'pager-next';
    next.textContent = 'Następna ›';
    next.disabled = page >= totalPages;
    next.addEventListener('click', () => onPage(page + 1));

    nav.append(prev, label, next);
    host.append(nav);
};
