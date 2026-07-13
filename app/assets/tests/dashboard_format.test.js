import { describe, expect, it } from 'vitest';
import {
    emptyStateLabel,
    formatTime,
    formatTimeRange,
    goalPeriodLabel,
    goalTypeLabel,
    musicSourceLabel,
    readTimeLabel,
    recommendationKindLabel,
    streakLabel,
} from '../dashboard/format.js';

describe('label helpers', () => {
    it('maps known goal types, periods and recommendation kinds to Polish labels', () => {
        expect(goalTypeLabel('series_episodes')).toBe('Odcinki seriali');
        expect(goalPeriodLabel('monthly')).toBe('Miesięcznie');
        expect(recommendationKindLabel('series')).toBe('Serial');
        expect(recommendationKindLabel('book')).toBe('Książka');
    });

    it('falls back to the raw value or a dash for unknown input', () => {
        expect(goalTypeLabel('mystery')).toBe('mystery');
        expect(goalPeriodLabel(undefined)).toBe('—');
        expect(recommendationKindLabel(null)).toBe('—');
    });
});

describe('musicSourceLabel', () => {
    it('normalizes case and maps known sources', () => {
        expect(musicSourceLabel('lastfm')).toBe('Last.fm');
        expect(musicSourceLabel('VINYL')).toBe('Winyl');
    });

    it('falls back to the raw value / dash', () => {
        expect(musicSourceLabel('spotify')).toBe('spotify');
        expect(musicSourceLabel(undefined)).toBe('—');
    });
});

describe('emptyStateLabel', () => {
    it('returns per-widget copy and a generic fallback', () => {
        expect(emptyStateLabel('tasks')).toBe('Brak zadań na dziś. 🎉');
        expect(emptyStateLabel('unknown')).toBe('Brak danych.');
    });
});

describe('formatTime / formatTimeRange', () => {
    it('extracts the wall-clock HH:MM preserving the server offset', () => {
        expect(formatTime('2026-07-13T09:05:00+02:00')).toBe('09:05');
        expect(formatTime('2026-07-13T23:30:00Z')).toBe('23:30');
    });

    it('returns an empty string for a malformed value', () => {
        expect(formatTime('not-a-date')).toBe('');
        expect(formatTime(undefined)).toBe('');
    });

    it('joins a start/end range and degrades to a single side', () => {
        expect(formatTimeRange('2026-07-13T09:00:00+02:00', '2026-07-13T10:30:00+02:00')).toBe('09:00–10:30');
        expect(formatTimeRange('2026-07-13T09:00:00+02:00', null)).toBe('09:00');
        expect(formatTimeRange(null, null)).toBe('');
    });
});

describe('readTimeLabel', () => {
    it('formats a positive minute count and hides missing/zero', () => {
        expect(readTimeLabel(5)).toBe('~5 min czytania');
        expect(readTimeLabel(0)).toBe('');
        expect(readTimeLabel(null)).toBe('');
    });
});

describe('streakLabel', () => {
    it('handles zero, singular and plural days', () => {
        expect(streakLabel(0)).toBe('Brak passy');
        expect(streakLabel(1)).toBe('1 dzień z rzędu');
        expect(streakLabel(4)).toBe('4 dni z rzędu');
    });
});
