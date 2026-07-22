import { describe, it, expect } from 'vitest';
import { shouldShowOfflineBanner } from '../pwa/offline-indicator.js';

describe('shouldShowOfflineBanner', () => {
    it('is hidden while online', () => {
        expect(shouldShowOfflineBanner(true)).toBe(false);
    });

    it('is shown while offline', () => {
        expect(shouldShowOfflineBanner(false)).toBe(true);
    });
});
