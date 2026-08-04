import { describe, expect, it, vi } from 'vitest';
import {
    FIRST_PAGE,
    clampPage,
    fetchAllPages,
    hasMultiplePages,
    itemNoun,
    mountPagerAfter,
    pageQuery,
    pagerLabel,
    renderPager,
    unwrapPage,
    withPage,
} from '../pagination.js';

describe('unwrapPage', () => {
    it('unwraps the {data, pagination} envelope every list endpoint returns', () => {
        const payload = { data: [{ id: 'a' }], pagination: { page: 2, perPage: 50, total: 137, totalPages: 3 } };

        expect(unwrapPage(payload)).toEqual({
            items: [{ id: 'a' }],
            pagination: { page: 2, perPage: 50, total: 137, totalPages: 3 },
        });
    });

    it('accepts a bare array as one full page, so a stubbed or older response never breaks a panel', () => {
        const { items, pagination } = unwrapPage([{ id: 'a' }, { id: 'b' }]);

        expect(items).toHaveLength(2);
        expect(pagination.totalPages).toBe(1);
        expect(pagination.total).toBe(2);
    });

    it('degrades to an empty page rather than throwing on an unexpected shape', () => {
        expect(unwrapPage(null).items).toEqual([]);
        expect(unwrapPage({ unexpected: true }).items).toEqual([]);
    });
});

describe('pagerLabel', () => {
    it('names the page, the page count and the size of the whole match set', () => {
        expect(pagerLabel({ page: 2, perPage: 50, total: 137, totalPages: 3 })).toBe('Strona 2 z 3 · 137 pozycji');
    });

    it('declines the Polish noun, teens included', () => {
        expect(itemNoun(1)).toBe('pozycja');
        expect(itemNoun(2)).toBe('pozycje');
        expect(itemNoun(5)).toBe('pozycji');
        // 12–14 are the exception the plain "ends in 2..4" rule gets wrong.
        expect(itemNoun(12)).toBe('pozycji');
        expect(itemNoun(22)).toBe('pozycje');
        expect(itemNoun(0)).toBe('pozycji');
    });
});

describe('hasMultiplePages', () => {
    it('is false for a single page, so a small library sees no controls at all', () => {
        expect(hasMultiplePages({ totalPages: 1 })).toBe(false);
        expect(hasMultiplePages(null)).toBe(false);
    });

    it('is true once there is more than one page', () => {
        expect(hasMultiplePages({ totalPages: 2 })).toBe(true);
    });
});

describe('clampPage', () => {
    it('keeps a requested page inside the range the response actually has', () => {
        expect(clampPage(0, { totalPages: 3 })).toBe(1);
        expect(clampPage(9, { totalPages: 3 })).toBe(3);
        expect(clampPage(2, { totalPages: 3 })).toBe(2);
    });
});

describe('withPage', () => {
    it('omits the default page so a first-page URL stays clean', () => {
        expect(withPage(new URLSearchParams(), FIRST_PAGE).toString()).toBe('');
    });

    it('preserves the filters already on the query', () => {
        const params = new URLSearchParams({ status: 'reading' });

        expect(withPage(params, 3).toString()).toBe('status=reading&page=3');
    });
});

describe('pageQuery', () => {
    it('returns an empty string rather than a bare "?" on the first page', () => {
        // A trailing "?" would change the URL a route stub or an HTTP cache
        // matches on, for no gain.
        expect(pageQuery(new URLSearchParams(), FIRST_PAGE)).toBe('');
    });

    it('prefixes the "?" only when there is something to ask for', () => {
        expect(pageQuery(new URLSearchParams(), 2)).toBe('?page=2');
        expect(pageQuery(new URLSearchParams({ status: 'reading' }), FIRST_PAGE)).toBe('?status=reading');
        expect(pageQuery(new URLSearchParams({ status: 'reading' }), 2)).toBe('?status=reading&page=2');
    });
});

describe('fetchAllPages', () => {
    it('walks every page and concatenates the items', async () => {
        const fetchPage = vi.fn(async (page) => ({
            data: [{ id: `p${page}` }],
            pagination: { page, perPage: 1, total: 3, totalPages: 3 },
        }));

        await expect(fetchAllPages(fetchPage)).resolves.toEqual([{ id: 'p1' }, { id: 'p2' }, { id: 'p3' }]);
        expect(fetchPage).toHaveBeenCalledTimes(3);
    });

    it('makes a single request when there is only one page', async () => {
        const fetchPage = vi.fn(async () => ({ data: [{ id: 'only' }], pagination: { page: 1, perPage: 50, total: 1, totalPages: 1 } }));

        await expect(fetchAllPages(fetchPage)).resolves.toEqual([{ id: 'only' }]);
        expect(fetchPage).toHaveBeenCalledTimes(1);
    });
});

describe('renderPager', () => {
    it('renders nothing when there is only one page', () => {
        const host = document.createElement('div');

        renderPager(host, { page: 1, perPage: 50, total: 3, totalPages: 1 }, () => {});

        expect(host.children).toHaveLength(0);
    });

    it('disables the edges so the user cannot page out of range', () => {
        const host = document.createElement('div');

        renderPager(host, { page: 1, perPage: 1, total: 3, totalPages: 3 }, () => {});
        expect(host.querySelector('.pager-prev').disabled).toBe(true);
        expect(host.querySelector('.pager-next').disabled).toBe(false);

        renderPager(host, { page: 3, perPage: 1, total: 3, totalPages: 3 }, () => {});
        expect(host.querySelector('.pager-prev').disabled).toBe(false);
        expect(host.querySelector('.pager-next').disabled).toBe(true);
    });

    it('reports the page the user moved to', () => {
        const host = document.createElement('div');
        const onPage = vi.fn();

        renderPager(host, { page: 2, perPage: 1, total: 3, totalPages: 3 }, onPage);
        host.querySelector('.pager-next').click();
        host.querySelector('.pager-prev').click();

        expect(onPage).toHaveBeenNthCalledWith(1, 3);
        expect(onPage).toHaveBeenNthCalledWith(2, 1);
    });
});

describe('mountPagerAfter', () => {
    it('creates the host next to the list and reuses it on the next render', () => {
        document.body.innerHTML = '<div id="wrap"><div id="list"></div></div>';
        const list = document.getElementById('list');

        mountPagerAfter(list, { page: 1, perPage: 1, total: 2, totalPages: 2 }, () => {});
        mountPagerAfter(list, { page: 2, perPage: 1, total: 2, totalPages: 2 }, () => {});

        // Re-rendering must not stack a second pager under the list.
        expect(document.querySelectorAll('.pager-host')).toHaveLength(1);
        expect(document.querySelectorAll('.pager')).toHaveLength(1);
        expect(list.nextElementSibling.classList.contains('pager-host')).toBe(true);
    });

    it('clears the controls when a reload drops back to a single page', () => {
        document.body.innerHTML = '<div id="wrap"><div id="list"></div></div>';
        const list = document.getElementById('list');

        mountPagerAfter(list, { page: 1, perPage: 1, total: 2, totalPages: 2 }, () => {});
        mountPagerAfter(list, { page: 1, perPage: 50, total: 1, totalPages: 1 }, () => {});

        expect(document.querySelectorAll('.pager')).toHaveLength(0);
    });

    it('does nothing for a detached element rather than throwing', () => {
        expect(() => mountPagerAfter(document.createElement('div'), { totalPages: 2 }, () => {})).not.toThrow();
        expect(() => mountPagerAfter(null, { totalPages: 2 }, () => {})).not.toThrow();
    });
});
