/**
 * Shared client-side half of the API's list envelope `{data, pagination}`.
 *
 * Every list endpoint answers with the same shape, so unwrapping it and drawing
 * the pager lives here once rather than in each of the eleven panels — the
 * lesson the 1.33.0 review drew from a rounding rule that had been copied into
 * two languages and drifted.
 *
 * The pure helpers are exported for Vitest; `renderPager` is the only part that
 * touches the DOM.
 */

export const FIRST_PAGE = 1;

/**
 * Unwraps a list response into `{items, pagination}`.
 *
 * A response that predates the envelope (or a stubbed one in a test) is still
 * accepted as a bare array, reported as a single full page — degrading to
 * "everything on one page" is safe, whereas throwing would take down a panel
 * over a shape difference.
 */
export function unwrapPage(payload) {
    if (Array.isArray(payload)) {
        return { items: payload, pagination: onePageOf(payload.length) };
    }

    if (payload && Array.isArray(payload.data)) {
        return { items: payload.data, pagination: payload.pagination ?? onePageOf(payload.data.length) };
    }

    return { items: [], pagination: onePageOf(0) };
}

function onePageOf(count) {
    return { page: FIRST_PAGE, perPage: count || 1, total: count, totalPages: 1 };
}

/**
 * Walks every page of a list endpoint and returns the concatenated items.
 *
 * This exists for the one panel that filters and sorts **client-side over the
 * whole library** (Series): showing it a pager would silently narrow its search
 * box to the current page, which is the loss of filtering the change is
 * required not to cause. The endpoint stays paginated either way — each
 * response is bounded, which is what the mobile client and the PWA's cache
 * budget actually needed — this caller just asks for all of them.
 *
 * Prefer a pager. Reach for this only when a panel genuinely needs the full set
 * in memory.
 */
export async function fetchAllPages(fetchPage) {
    const first = unwrapPage(await fetchPage(FIRST_PAGE));
    const totalPages = Number(first.pagination?.totalPages) || 1;
    const items = [...first.items];

    for (let page = FIRST_PAGE + 1; page <= totalPages; ++page) {
        items.push(...unwrapPage(await fetchPage(page)).items);
    }

    return items;
}

/** True when the pager is worth drawing at all. */
export function hasMultiplePages(pagination) {
    return Boolean(pagination) && Number(pagination.totalPages) > 1;
}

/**
 * "Strona 2 z 7 · 137 pozycji" — the total is spelled out because the page
 * count alone does not tell the user how much is behind it.
 */
export function pagerLabel(pagination) {
    if (!pagination) {
        return '';
    }

    const page = Number(pagination.page) || FIRST_PAGE;
    const totalPages = Number(pagination.totalPages) || 1;
    const total = Number(pagination.total) || 0;

    return `Strona ${page} z ${totalPages} · ${total} ${itemNoun(total)}`;
}

/** Polish declension, teens exception included. */
export function itemNoun(count) {
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
}

/** Clamps a requested page into the range the response actually has. */
export function clampPage(page, pagination) {
    const totalPages = Number(pagination?.totalPages) || 1;
    const wanted = Number(page) || FIRST_PAGE;

    return Math.min(Math.max(wanted, FIRST_PAGE), totalPages);
}

/** Appends `page`/`perPage` to a query string builder, omitting the default page. */
export function withPage(params, page) {
    if (Number(page) > FIRST_PAGE) {
        params.set('page', String(page));
    }

    return params;
}

/**
 * The query suffix for a request, including the leading `?` — or an empty
 * string when there is nothing to ask for.
 *
 * Returning `''` rather than a bare `?` keeps first-page URLs byte-identical to
 * what they were before pagination, which is what stops a route stub (or an
 * HTTP cache key) from missing on a trailing question mark.
 */
export function pageQuery(params, page) {
    const query = withPage(params, page).toString();

    return query ? `?${query}` : '';
}

/**
 * Draws prev/next controls plus the label into `container`, calling
 * `onPage(newPage)` when the user moves. Renders nothing when there is only one
 * page, so a small library never sees a pager it does not need.
 */
export function renderPager(container, pagination, onPage) {
    if (!container) {
        return;
    }

    container.innerHTML = '';

    if (!hasMultiplePages(pagination)) {
        return;
    }

    const page = Number(pagination.page) || FIRST_PAGE;
    const totalPages = Number(pagination.totalPages) || 1;

    const nav = document.createElement('div');
    nav.className = 'pager';

    const prev = document.createElement('button');
    prev.type = 'button';
    prev.className = 'pager-prev';
    prev.textContent = '‹ Poprzednia';
    prev.disabled = page <= FIRST_PAGE;
    prev.addEventListener('click', () => onPage(page - 1));

    const label = document.createElement('span');
    label.className = 'pager-label';
    label.textContent = pagerLabel(pagination);

    const next = document.createElement('button');
    next.type = 'button';
    next.className = 'pager-next';
    next.textContent = 'Następna ›';
    next.disabled = page >= totalPages;
    next.addEventListener('click', () => onPage(page + 1));

    nav.append(prev, label, next);
    container.append(nav);
}

/**
 * Mounts the pager immediately after `listEl`, creating (and reusing) its host
 * element on the fly.
 *
 * Doing it here rather than adding a placeholder to each of the eleven Twig
 * templates keeps the markup change out of the panels entirely: a panel that
 * renders a list gets a pager by calling this, and one that does not is
 * untouched.
 */
export function mountPagerAfter(listEl, pagination, onPage) {
    if (!listEl || !listEl.parentNode) {
        return;
    }

    let host = listEl.nextElementSibling;

    if (!host || !host.classList.contains('pager-host')) {
        host = document.createElement('div');
        host.className = 'pager-host';
        listEl.parentNode.insertBefore(host, listEl.nextSibling);
    }

    renderPager(host, pagination, onPage);
}
