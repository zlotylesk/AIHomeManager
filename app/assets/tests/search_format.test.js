import { describe, expect, it } from 'vitest';
import { facetCounts, groupByType, groupHeading, typeLabel } from '../search/format.js';

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

describe('facetCounts', () => {
    it('folds the facet list into a type lookup', () => {
        expect(facetCounts([{ type: 'book', count: 42 }, { type: 'task', count: 3 }]))
            .toEqual({ book: 42, task: 3 });
    });

    it('keeps a zero count', () => {
        expect(facetCounts([{ type: 'book', count: 0 }])).toEqual({ book: 0 });
    });

    it('drops entries that could not render as a number', () => {
        // A malformed payload must cost the count, never produce "Książka (undefined)".
        expect(facetCounts([
            { type: 'book', count: null },
            { type: 'task', count: 'many' },
            { type: 'series', count: -1 },
            { type: 'music', count: 1.5 },
            { count: 7 },
            null,
        ])).toEqual({});
    });

    it('returns an empty lookup for a missing or non-array payload', () => {
        expect(facetCounts(undefined)).toEqual({});
        expect(facetCounts('nope')).toEqual({});
    });
});

describe('groupHeading', () => {
    it('appends the whole-match-set count to the group label', () => {
        // The group holds one rendered item but the phrase matches 42 books —
        // showing the total is the entire point of the facet.
        const heading = groupHeading({ type: 'book', label: 'Książka', items: [{}] }, { book: 42 });

        expect(heading).toBe('Książka (42)');
    });

    it('shows a zero count rather than hiding it', () => {
        expect(groupHeading({ type: 'task', label: 'Zadanie' }, { task: 0 })).toBe('Zadanie (0)');
    });

    it('falls back to the bare label when the count is unknown', () => {
        // A failed facet request degrades to the label alone.
        expect(groupHeading({ type: 'book', label: 'Książka' }, {})).toBe('Książka');
        expect(groupHeading({ type: 'book', label: 'Książka' }, undefined)).toBe('Książka');
    });

    it('derives the label from the type when the group carries none', () => {
        expect(groupHeading({ type: 'series' }, { series: 2 })).toBe('Serial (2)');
    });
});
