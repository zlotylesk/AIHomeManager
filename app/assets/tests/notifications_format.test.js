import { describe, it, expect } from 'vitest';
import {
    typeLabel,
    channelLabel,
    statusLabel,
    isChannelEnabled,
    quietHoursLabel,
    isQuietRangeComplete,
    formatSentAt,
} from '../notifications/format.js';
import { urlBase64ToUint8Array } from '../notifications/push.js';

describe('labels', () => {
    it('names every known notification type', () => {
        expect(typeLabel('task_due')).toBe('Termin zadania');
        expect(typeLabel('goal_streak_at_risk')).toBe('Zagrożona seria');
    });

    it('falls back to the raw value for an unknown key', () => {
        expect(typeLabel('smoke_signal')).toBe('smoke_signal');
        expect(channelLabel('carrier_pigeon')).toBe('carrier_pigeon');
        expect(statusLabel('unknown')).toBe('unknown');
    });

    it('names channels and statuses', () => {
        expect(channelLabel('email')).toBe('E-mail');
        expect(statusLabel('sent')).toBe('Wysłane');
    });
});

describe('isChannelEnabled', () => {
    it('reads the enabled channel list', () => {
        expect(isChannelEnabled({ channels: ['email'] }, 'email')).toBe(true);
        expect(isChannelEnabled({ channels: ['email'] }, 'push')).toBe(false);
    });

    it('treats a missing preference as nothing enabled', () => {
        expect(isChannelEnabled(undefined, 'email')).toBe(false);
        expect(isChannelEnabled({}, 'email')).toBe(false);
    });
});

describe('quietHoursLabel', () => {
    it('reports no quiet period when either end is missing', () => {
        expect(quietHoursLabel({ quietFrom: null, quietTo: null })).toBe('Brak ciszy');
        expect(quietHoursLabel({ quietFrom: '22:00', quietTo: null })).toBe('Brak ciszy');
    });

    it('renders a same-day window plainly', () => {
        expect(quietHoursLabel({ quietFrom: '13:00', quietTo: '15:00' })).toBe('13:00–15:00');
    });

    it('spells out a window that wraps past midnight', () => {
        expect(quietHoursLabel({ quietFrom: '22:00', quietTo: '07:00' })).toBe('22:00–07:00 (przez noc)');
    });
});

describe('isQuietRangeComplete', () => {
    it('accepts both ends set or both cleared', () => {
        expect(isQuietRangeComplete('22:00', '07:00')).toBe(true);
        expect(isQuietRangeComplete(null, null)).toBe(true);
        expect(isQuietRangeComplete('', '')).toBe(true);
    });

    it('rejects a half-stated range, which would silently persist as no quiet hours', () => {
        expect(isQuietRangeComplete('22:00', null)).toBe(false);
        expect(isQuietRangeComplete(null, '07:00')).toBe(false);
    });
});

describe('formatSentAt', () => {
    it('prefers the sent time and falls back to creation', () => {
        expect(formatSentAt({ sentAt: '2026-07-19T09:00:00+02:00', createdAt: '2026-07-19T08:00:00+02:00' })).not.toBe('');
        expect(formatSentAt({ sentAt: null, createdAt: '2026-07-19T08:00:00+02:00' })).not.toBe('');
    });

    it('stays empty rather than printing "Invalid Date"', () => {
        expect(formatSentAt({})).toBe('');
        expect(formatSentAt({ sentAt: 'not a date' })).toBe('');
    });
});

describe('urlBase64ToUint8Array', () => {
    it('decodes a base64url VAPID key into raw bytes', () => {
        // "Hello" in base64url, unpadded — the shape the key arrives in.
        expect(Array.from(urlBase64ToUint8Array('SGVsbG8'))).toEqual([72, 101, 108, 108, 111]);
    });

    it('restores the base64url alphabet', () => {
        expect(Array.from(urlBase64ToUint8Array('-_8'))).toEqual([251, 255]);
    });
});
