import { describe, it, expect } from 'vitest';
import {
    isQueuedResponse,
    isRequiresNetworkResponse,
    QUEUED_MESSAGE,
    REQUIRES_NETWORK_MESSAGE,
} from '../pwa/queue-ux.js';

describe('isQueuedResponse', () => {
    it('recognises the SW-marked queued write', () => {
        expect(isQueuedResponse(202, { queued: true, message: QUEUED_MESSAGE })).toBe(true);
    });

    it('ignores a real 202 without the marker (e.g. an async import accepted)', () => {
        expect(isQueuedResponse(202, { status: 'import_started' })).toBe(false);
    });

    it('ignores the marker on any other status', () => {
        expect(isQueuedResponse(200, { queued: true })).toBe(false);
    });

    it('is false for a null/absent body', () => {
        expect(isQueuedResponse(202, null)).toBe(false);
    });
});

describe('isRequiresNetworkResponse', () => {
    it('recognises the SW requires-network marker', () => {
        expect(isRequiresNetworkResponse(503, { requiresNetwork: true, message: REQUIRES_NETWORK_MESSAGE })).toBe(true);
    });

    it('ignores a genuine 503 error body', () => {
        expect(isRequiresNetworkResponse(503, { error: 'Service unavailable.' })).toBe(false);
    });

    it('is false for a null/absent body', () => {
        expect(isRequiresNetworkResponse(503, null)).toBe(false);
    });
});

describe('messages', () => {
    it('are non-empty Polish strings', () => {
        expect(QUEUED_MESSAGE.length).toBeGreaterThan(0);
        expect(REQUIRES_NETWORK_MESSAGE.length).toBeGreaterThan(0);
    });
});
