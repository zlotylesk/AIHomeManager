import { describe, expect, it } from 'vitest';
import {
    avg,
    cardRating,
    cardRatingFlag,
    filterSeries,
    ratingFlag,
    ratingHighlight,
    sortSeries,
    statusLabel,
} from '../controllers/series_controller.js';

describe('sortSeries', () => {
    it('sorts by title A–Z by default', () => {
        const list = [{title: 'The Wire'}, {title: 'Breaking Bad'}, {title: 'Chernobyl'}];
        expect(sortSeries(list, 'title').map(s => s.title))
            .toEqual(['Breaking Bad', 'Chernobyl', 'The Wire']);
    });

    it('sorts by average rating descending with nulls last', () => {
        const list = [
            {title: 'A', averageRating: 7},
            {title: 'B', averageRating: null},
            {title: 'C', averageRating: 9},
        ];
        expect(sortSeries(list, 'rating-desc').map(s => s.title)).toEqual(['C', 'A', 'B']);
    });

    it('sorts by own rating descending with nulls last', () => {
        const list = [
            {title: 'A', rating: 5},
            {title: 'B', rating: null},
            {title: 'C', rating: 8},
        ];
        expect(sortSeries(list, 'own-desc').map(s => s.title)).toEqual(['C', 'A', 'B']);
    });

    it('sorts by creation date newest first', () => {
        const list = [
            {title: 'A', createdAt: '2026-01-01'},
            {title: 'B', createdAt: '2026-06-01'},
            {title: 'C', createdAt: '2026-03-01'},
        ];
        expect(sortSeries(list, 'created-desc').map(s => s.title)).toEqual(['B', 'C', 'A']);
    });

    it('does not mutate the input array', () => {
        const list = [{title: 'B'}, {title: 'A'}];
        const result = sortSeries(list, 'title');
        expect(result).not.toBe(list);
        expect(list.map(s => s.title)).toEqual(['B', 'A']);
    });
});

describe('filterSeries', () => {
    const list = [
        {title: 'Breaking Bad'},
        {title: 'Better Call Saul'},
        {title: 'The Wire'},
    ];

    it('matches titles case-insensitively by substring', () => {
        expect(filterSeries(list, 'B').map(s => s.title))
            .toEqual(['Breaking Bad', 'Better Call Saul']);
        expect(filterSeries(list, 'wire').map(s => s.title)).toEqual(['The Wire']);
    });

    it('returns a copy of the full list for an empty/blank/null term', () => {
        for (const term of ['', '   ', null, undefined]) {
            const result = filterSeries(list, term);
            expect(result).toHaveLength(3);
            expect(result).not.toBe(list);
        }
    });

    it('returns an empty array when nothing matches', () => {
        expect(filterSeries(list, 'zzz')).toEqual([]);
    });
});

describe('ratingHighlight', () => {
    it('flags incomplete with priority over a mismatch', () => {
        expect(ratingHighlight({episodeCount: 10, watchedCount: 5, averageRating: 8, rating: 3}))
            .toBe('incomplete');
    });

    it('returns null when there is no average rating', () => {
        expect(ratingHighlight({episodeCount: 10, watchedCount: 10, averageRating: null, rating: 5}))
            .toBeNull();
    });

    it('flags mismatch when an average exists but no own rating', () => {
        expect(ratingHighlight({episodeCount: 5, watchedCount: 5, averageRating: 8, rating: null}))
            .toBe('mismatch');
    });

    it('flags mismatch when rounded average differs from own rating', () => {
        expect(ratingHighlight({episodeCount: 5, watchedCount: 5, averageRating: 8.4, rating: 5}))
            .toBe('mismatch');
    });

    it('returns null when rounded average equals own rating', () => {
        expect(ratingHighlight({episodeCount: 5, watchedCount: 5, averageRating: 7.6, rating: 8}))
            .toBeNull();
    });

    it('is not incomplete when there are no episodes', () => {
        expect(ratingHighlight({episodeCount: 0, watchedCount: 0, averageRating: null, rating: null}))
            .toBeNull();
    });
});

describe('ratingFlag', () => {
    it('maps incomplete to the amber class with a watched-count title', () => {
        const flag = ratingFlag({episodeCount: 10, watchedCount: 5, averageRating: 8, rating: 3});
        expect(flag.cls).toBe('is-rating-incomplete');
        expect(flag.title).toContain('5/10');
    });

    it('maps a missing own rating to a mismatch with a "no rating" title', () => {
        const flag = ratingFlag({episodeCount: 5, watchedCount: 5, averageRating: 8, rating: null});
        expect(flag.cls).toBe('is-rating-mismatch');
        expect(flag.title).toBe('Brak Twojej oceny (średnia 8)');
    });

    it('maps a diverging own rating to a mismatch with both values', () => {
        const flag = ratingFlag({episodeCount: 5, watchedCount: 5, averageRating: 8.4, rating: 5});
        expect(flag.cls).toBe('is-rating-mismatch');
        expect(flag.title).toBe('Twoja ocena 5 ≠ średnia 8');
    });

    it('returns a neutral flag when the rating is aligned', () => {
        expect(ratingFlag({episodeCount: 5, watchedCount: 5, averageRating: 7.6, rating: 8}))
            .toEqual({cls: '', title: ''});
    });
});

describe('cardRatingFlag', () => {
    it('flags an incomplete show', () => {
        expect(cardRatingFlag({episodeCount: 10, watchedCount: 5, averageRating: 8, rating: 8, seasons: []}).cls)
            .toBe('is-rating-incomplete');
    });

    it('flags a show whose own rating mismatches its average', () => {
        expect(cardRatingFlag({episodeCount: 5, watchedCount: 5, averageRating: 8, rating: 3, seasons: []}).cls)
            .toBe('is-rating-mismatch');
    });

    it('flags a mismatch coming from any season even when the show is aligned', () => {
        const show = {
            episodeCount: 5, watchedCount: 5, averageRating: 8, rating: 8,
            seasons: [{episodeCount: 3, watchedCount: 3, averageRating: 9, rating: 4}],
        };
        expect(cardRatingFlag(show).cls).toBe('is-rating-mismatch');
    });

    it('returns a neutral flag when show and seasons are all aligned', () => {
        const show = {
            episodeCount: 5, watchedCount: 5, averageRating: 8, rating: 8,
            seasons: [{episodeCount: 3, watchedCount: 3, averageRating: 9, rating: 9}],
        };
        expect(cardRatingFlag(show)).toEqual({cls: '', title: ''});
    });
});

describe('cardRating', () => {
    it('renders a value with a star and no empty modifier', () => {
        const html = cardRating('My', 8);
        expect(html).toContain('★ 8');
        expect(html).not.toContain('card-rating-empty');
    });

    it('renders a dash with the empty modifier when there is no value', () => {
        const html = cardRating('Avg', null);
        expect(html).toContain('card-rating-empty');
        expect(html).toContain('—');
    });
});

describe('statusLabel', () => {
    it('maps known statuses and returns null for the rest', () => {
        expect(statusLabel('ongoing')).toBe('Ongoing');
        expect(statusLabel('ended')).toBe('Ended');
        expect(statusLabel('cancelled')).toBeNull();
        expect(statusLabel(null)).toBeNull();
    });
});

describe('avg', () => {
    it('averages numbers rounded to two decimals', () => {
        expect(avg([8, 6, 10])).toBe(8);
        expect(avg([7, 8])).toBe(7.5);
        expect(avg([8, 8, 7])).toBe(7.67);
    });

    it('ignores null and undefined entries', () => {
        expect(avg([8, null, 6, undefined])).toBe(7);
    });

    it('returns null when there is nothing to average', () => {
        expect(avg([])).toBeNull();
        expect(avg([null, undefined])).toBeNull();
    });
});
