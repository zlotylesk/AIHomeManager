import { describe, it, expect } from 'vitest';
import { shouldOfferPushPrompt, nextVisitCount, MIN_VISITS_BEFORE_PROMPT } from '../pwa/push-state.js';

describe('shouldOfferPushPrompt', () => {
    const base = { supported: true, permission: 'default', dismissed: false, visits: MIN_VISITS_BEFORE_PROMPT };

    it('offers on a return visit when supported, undecided and not dismissed', () => {
        expect(shouldOfferPushPrompt(base)).toBe(true);
    });

    it('stays silent on the first visit (contextual, not on entry)', () => {
        expect(shouldOfferPushPrompt({ ...base, visits: 1 })).toBe(false);
    });

    it('does not re-ask once permission is granted', () => {
        expect(shouldOfferPushPrompt({ ...base, permission: 'granted' })).toBe(false);
    });

    it('does not nag once permission is denied', () => {
        expect(shouldOfferPushPrompt({ ...base, permission: 'denied' })).toBe(false);
    });

    it('respects an explicit dismissal', () => {
        expect(shouldOfferPushPrompt({ ...base, dismissed: true })).toBe(false);
    });

    it('is silent when the browser cannot do push', () => {
        expect(shouldOfferPushPrompt({ ...base, supported: false })).toBe(false);
    });
});

describe('nextVisitCount', () => {
    it('starts at 1 for a missing counter', () => {
        expect(nextVisitCount(null)).toBe(1);
    });

    it('starts at 1 for a garbage value', () => {
        expect(nextVisitCount('abc')).toBe(1);
    });

    it('increments a stored count', () => {
        expect(nextVisitCount('2')).toBe(3);
    });
});
