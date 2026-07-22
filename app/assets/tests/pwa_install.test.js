import { describe, it, expect } from 'vitest';
import { isAppInstalled, shouldOfferInstall } from '../pwa/install-state.js';

describe('isAppInstalled', () => {
    it('is false with no standalone signal (running in a browser tab)', () => {
        expect(isAppInstalled()).toBe(false);
        expect(isAppInstalled({ displayStandalone: false, iosStandalone: false })).toBe(false);
    });

    it('is true when display-mode is standalone (Android/desktop installed)', () => {
        expect(isAppInstalled({ displayStandalone: true })).toBe(true);
    });

    it('is true when navigator.standalone is set (iOS installed)', () => {
        expect(isAppInstalled({ iosStandalone: true })).toBe(true);
    });
});

describe('shouldOfferInstall', () => {
    it('offers install only with a captured prompt, not installed, not dismissed', () => {
        expect(shouldOfferInstall({ hasDeferredPrompt: true, installed: false, dismissed: false })).toBe(true);
    });

    it('does not offer without a captured beforeinstallprompt', () => {
        expect(shouldOfferInstall({ hasDeferredPrompt: false })).toBe(false);
    });

    it('does not offer when the app is already installed', () => {
        expect(shouldOfferInstall({ hasDeferredPrompt: true, installed: true })).toBe(false);
    });

    it('does not offer once dismissed this session', () => {
        expect(shouldOfferInstall({ hasDeferredPrompt: true, dismissed: true })).toBe(false);
    });
});
