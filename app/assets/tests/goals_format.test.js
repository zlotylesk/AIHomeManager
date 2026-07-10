import { describe, expect, it } from 'vitest';
import {
    clampPercent,
    longestLabel,
    periodLabel,
    progressText,
    streakLabel,
    typeLabel,
} from '../goals/format.js';

describe('typeLabel / periodLabel', () => {
    it('maps known types and periods to Polish labels', () => {
        expect(typeLabel('book_pages')).toBe('Strony książek');
        expect(typeLabel('youtube_videos')).toBe('Filmy YouTube');
        expect(periodLabel('weekly')).toBe('Tygodniowo');
    });

    it('falls back to the raw value or a dash for unknown input', () => {
        expect(typeLabel('mystery')).toBe('mystery');
        expect(periodLabel(undefined)).toBe('—');
    });
});

describe('clampPercent', () => {
    it('clamps into 0..100 and rounds', () => {
        expect(clampPercent(-5)).toBe(0);
        expect(clampPercent(60.4)).toBe(60);
        expect(clampPercent(150)).toBe(100);
    });

    it('returns 0 for non-numeric input', () => {
        expect(clampPercent('abc')).toBe(0);
        expect(clampPercent(undefined)).toBe(0);
    });
});

describe('progressText', () => {
    it('formats achieved / target and defaults missing values to 0', () => {
        expect(progressText(30, 50)).toBe('30 / 50');
        expect(progressText(undefined, undefined)).toBe('0 / 0');
    });
});

describe('streakLabel', () => {
    it('handles zero, singular and plural days', () => {
        expect(streakLabel(0)).toBe('Brak passy');
        expect(streakLabel(1)).toBe('1 dzień z rzędu');
        expect(streakLabel(5)).toBe('5 dni z rzędu');
    });
});

describe('longestLabel', () => {
    it('formats the record with plural and a dash for zero', () => {
        expect(longestLabel(0)).toBe('Rekord: —');
        expect(longestLabel(1)).toBe('Rekord: 1 dzień');
        expect(longestLabel(12)).toBe('Rekord: 12 dni');
    });
});
