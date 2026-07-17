import { describe, expect, it } from 'vitest';
import {
    metaLine,
    ratingLabel,
    statusLabel,
    watchedLabel,
    watchedQuery,
    yearLabel,
} from '../movies/format.js';

describe('statusLabel', () => {
    it('maps known statuses to Polish labels', () => {
        expect(statusLabel('released')).toBe('Wydany');
        expect(statusLabel('upcoming')).toBe('Zapowiedź');
    });

    it('returns a dash for null and the raw value for unknown input', () => {
        expect(statusLabel(null)).toBe('—');
        expect(statusLabel('')).toBe('—');
        expect(statusLabel('mystery')).toBe('mystery');
    });
});

describe('watchedLabel', () => {
    it('maps the boolean flag to a Polish label', () => {
        expect(watchedLabel(true)).toBe('Obejrzany');
        expect(watchedLabel(false)).toBe('Nieobejrzany');
    });
});

describe('ratingLabel', () => {
    it('formats an in-range rating and falls back when missing', () => {
        expect(ratingLabel(8)).toBe('Ocena: 8/10');
        expect(ratingLabel(null)).toBe('Brak oceny');
        expect(ratingLabel(0)).toBe('Brak oceny');
        expect(ratingLabel(11)).toBe('Brak oceny');
    });
});

describe('yearLabel', () => {
    it('renders a positive year and a dash otherwise', () => {
        expect(yearLabel(2017)).toBe('2017');
        expect(yearLabel(null)).toBe('—');
        expect(yearLabel(0)).toBe('—');
    });
});

describe('metaLine', () => {
    it('joins year and status, dropping empty parts', () => {
        expect(metaLine({ year: 2017, status: 'released' })).toBe('2017 • Wydany');
        expect(metaLine({ year: null, status: 'upcoming' })).toBe('Zapowiedź');
        expect(metaLine({ year: 1999, status: null })).toBe('1999');
        expect(metaLine({ year: null, status: null })).toBe('');
        expect(metaLine(null)).toBe('');
    });
});

describe('watchedQuery', () => {
    it('maps the filter to the API query suffix', () => {
        expect(watchedQuery('all')).toBe('');
        expect(watchedQuery('watched')).toBe('?watched=true');
        expect(watchedQuery('unwatched')).toBe('?watched=false');
    });
});
