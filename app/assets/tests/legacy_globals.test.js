import { beforeEach, describe, expect, it } from 'vitest';
import { publishLegacyGlobals } from '../legacy-globals.js';
import { TOAST_TIMEOUT_MS, apiCall, escHtml, safeUrl } from '../util.js';
import { fetchAllPages, mountPagerAfter, unwrapPage } from '../pagination.js';
import { showError, showInfo } from '../banners.js';

describe('publishLegacyGlobals', () => {
    let target;

    beforeEach(() => {
        target = {};
    });

    // Identity, not equivalence. Asserting that window.escHtml *behaves* like
    // escHtml is exactly what four hand-maintained copies also did right up
    // until they drifted; asserting it IS the same function object is what
    // makes a second implementation impossible.
    it('publishes the very same function objects, not copies of them', () => {
        publishLegacyGlobals(target);

        expect(target.escHtml).toBe(escHtml);
        expect(target.apiCall).toBe(apiCall);
        expect(target.safeUrl).toBe(safeUrl);
        expect(target.unwrapPage).toBe(unwrapPage);
        expect(target.fetchAllPages).toBe(fetchAllPages);
        expect(target.mountPagerAfter).toBe(mountPagerAfter);
        expect(target.showError).toBe(showError);
        expect(target.showInfo).toBe(showInfo);
    });

    it('publishes the toast timeout constant', () => {
        publishLegacyGlobals(target);

        expect(target.TOAST_TIMEOUT_MS).toBe(TOAST_TIMEOUT_MS);
    });

    // The exact set the three legacy panels reference. A helper dropped from
    // here is a ReferenceError on a page with no unit tests of its own, which
    // is the failure mode this whole ticket exists to remove — so the list is
    // pinned rather than left to be discovered in a browser.
    it('publishes every helper the legacy panels reference', () => {
        publishLegacyGlobals(target);

        expect(Object.keys(target).sort()).toEqual([
            'TOAST_TIMEOUT_MS',
            'apiCall',
            'escHtml',
            'fetchAllPages',
            'mountPagerAfter',
            'safeUrl',
            'showError',
            'showInfo',
            'unwrapPage',
        ]);
    });

    it('does nothing when there is no target rather than throwing', () => {
        // app.js calls this at import time; a missing window (a non-browser
        // context pulling the bundle in) must not take the whole entry down.
        expect(() => publishLegacyGlobals(undefined)).not.toThrow();
    });
});
