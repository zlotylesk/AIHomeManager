import { describe, expect, it } from 'vitest';
import { groupByType, typeLabel } from '../search/format.js';

describe('typeLabel', () => {
    it('maps known result types to Polish labels', () => {
        expect(typeLabel('book')).toBe('Książka');
        expect(typeLabel('series')).toBe('Serial');
        expect(typeLabel('task')).toBe('Zadanie');
    });

    it('falls back to the raw value or a dash for unknown input', () => {
        expect(typeLabel('podcast')).toBe('podcast');
        expect(typeLabel(undefined)).toBe('—');
    });
});

describe('groupByType', () => {
    it('buckets results by type in the configured order', () => {
        const groups = groupByType([
            { type: 'series', id: 's1', title: 'A' },
            { type: 'book', id: 'b1', title: 'B' },
            { type: 'book', id: 'b2', title: 'C' },
        ]);

        expect(groups.map((g) => g.type)).toEqual(['book', 'series']);
        expect(groups[0].label).toBe('Książka');
        expect(groups[0].items).toHaveLength(2);
        expect(groups[1].items).toHaveLength(1);
    });

    it('appends unknown types after the known ones', () => {
        const groups = groupByType([
            { type: 'podcast', id: 'p1', title: 'X' },
            { type: 'book', id: 'b1', title: 'Y' },
        ]);

        expect(groups.map((g) => g.type)).toEqual(['book', 'podcast']);
    });

    it('returns an empty grouping for empty or non-array input', () => {
        expect(groupByType([])).toEqual([]);
        expect(groupByType(null)).toEqual([]);
    });
});
