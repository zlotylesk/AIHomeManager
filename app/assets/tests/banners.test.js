import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { hideError, showError, showInfo } from '../banners.js';
import { TOAST_TIMEOUT_MS } from '../util.js';

// These used to exist six times over (once per Encore module, five times across
// the legacy panels). Now that there is one implementation driving the two
// global banners in base.html.twig, it is worth a test of its own — the legacy
// panels have none, and they are the ones that call it most.
describe('banners', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        document.body.innerHTML =
            '<div id="error-banner" class="hidden"></div><div id="info-banner" class="hidden"></div>';
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('shows the message and reveals the error banner', () => {
        showError('Nie udało się zapisać.');

        const banner = document.getElementById('error-banner');
        expect(banner.textContent).toBe('Nie udało się zapisać.');
        expect(banner.classList.contains('hidden')).toBe(false);
    });

    it('hides the error banner again after the toast timeout', () => {
        showError('Nie udało się zapisać.');
        vi.advanceTimersByTime(TOAST_TIMEOUT_MS);

        expect(document.getElementById('error-banner').classList.contains('hidden')).toBe(true);
    });

    it('writes the message as text, so markup in it cannot become markup', () => {
        // textContent rather than innerHTML: an API error message can carry
        // whatever the server put in it, and it reaches this banner unescaped.
        showError('<img src=x onerror=alert(1)>');

        const banner = document.getElementById('error-banner');
        expect(banner.querySelector('img')).toBeNull();
        expect(banner.textContent).toBe('<img src=x onerror=alert(1)>');
    });

    it('drives the info banner independently of the error one', () => {
        showInfo('Import rozpoczęty.');

        expect(document.getElementById('info-banner').textContent).toBe('Import rozpoczęty.');
        expect(document.getElementById('info-banner').classList.contains('hidden')).toBe(false);
        expect(document.getElementById('error-banner').classList.contains('hidden')).toBe(true);
    });

    it('hides the error banner on demand', () => {
        showError('boom');
        hideError();

        expect(document.getElementById('error-banner').classList.contains('hidden')).toBe(true);
    });

    it('does nothing when the banner is missing rather than throwing', () => {
        // A panel whose template has no banner must not lose the action it was
        // reporting on — the legacy copies had no such guard.
        document.body.innerHTML = '';

        expect(() => showError('x')).not.toThrow();
        expect(() => showInfo('x')).not.toThrow();
        expect(() => hideError()).not.toThrow();
    });
});
