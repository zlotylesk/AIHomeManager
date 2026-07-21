import { describe, expect, it } from 'vitest';
import {
    bucketLabel,
    granularityLabel,
    headlineCaption,
    headlineLabel,
    isIdle,
    isUnavailable,
    metricLabel,
    toChartData,
    valueLabel,
} from '../insights/format.js';

const series = (overrides = {}) => ({
    metric: 'books_pages_read',
    unit: 'count',
    total: 40,
    average: 8,
    headline: 40,
    points: [
        { bucketStart: '2026-07-06', value: 40 },
        { bucketStart: '2026-07-13', value: 0 },
    ],
    ...overrides,
});

describe('metricLabel', () => {
    it('names every metric the API can send', () => {
        expect(metricLabel('books_pages_read')).toBe('Tempo czytania');
        expect(metricLabel('series_episodes_watched')).toBe('Obejrzane odcinki');
        expect(metricLabel('youtube_minutes_watched')).toBe('YouTube');
        expect(metricLabel('music_tracks_played')).toBe('Odsłuchane utwory');
        expect(metricLabel('tasks_completion_rate')).toBe('Ukończone zadania');
    });

    it('falls back to the raw key so a new metric is visible, not blank', () => {
        expect(metricLabel('sleep_hours')).toBe('sleep_hours');
    });
});

describe('granularityLabel', () => {
    it('translates both buckets', () => {
        expect(granularityLabel('week')).toBe('Tygodniowo');
        expect(granularityLabel('month')).toBe('Miesięcznie');
    });
});

describe('valueLabel', () => {
    it('drops the decimal tail of a whole number', () => {
        expect(valueLabel(40, 'count')).toBe('40');
    });

    it('keeps one decimal when it carries information', () => {
        expect(valueLabel(66.666, 'percent')).toBe('66.7%');
    });

    it('appends the unit suffix', () => {
        expect(valueLabel(30, 'minutes')).toBe('30 min');
        expect(valueLabel(50, 'percent')).toBe('50%');
        expect(valueLabel(12, 'count')).toBe('12');
    });

    it('degrades to a dash rather than printing NaN', () => {
        expect(valueLabel(undefined, 'count')).toBe('—');
        expect(valueLabel(Number.NaN, 'count')).toBe('—');
    });
});

describe('isUnavailable / isIdle', () => {
    it('treats an empty point list as unavailable', () => {
        expect(isUnavailable(series({ points: [] }))).toBe(true);
        expect(isUnavailable(series())).toBe(false);
    });

    it('tells an idle window apart from an unreadable metric', () => {
        const idle = series({
            points: [
                { bucketStart: '2026-07-06', value: 0 },
                { bucketStart: '2026-07-13', value: 0 },
            ],
        });

        expect(isIdle(idle)).toBe(true);
        expect(isIdle(series())).toBe(false);
        expect(isIdle(series({ points: [] }))).toBe(false);
    });
});

describe('bucketLabel', () => {
    it('names a week by day and month', () => {
        expect(bucketLabel('2026-07-06', 'week')).toBe('6.07');
    });

    it('names a month by month and year', () => {
        expect(bucketLabel('2026-07-01', 'month')).toBe('07.2026');
    });

    it('survives a malformed value', () => {
        expect(bucketLabel(null, 'week')).toBe('');
        expect(bucketLabel('2026', 'week')).toBe('');
    });
});

describe('toChartData', () => {
    it('draws a cumulative metric as bars', () => {
        const data = toChartData(series(), 'week');

        expect(data.type).toBe('bar');
        expect(data.labels).toEqual(['6.07', '13.07']);
        expect(data.values).toEqual([40, 0]);
        expect(data.suggestedMax).toBeUndefined();
    });

    /**
     * Bars would suggest the percentages stack up; a rate is a level per bucket.
     */
    it('draws a rate as a line capped at 100', () => {
        const data = toChartData(series({ unit: 'percent', metric: 'tasks_completion_rate' }), 'week');

        expect(data.type).toBe('line');
        expect(data.suggestedMax).toBe(100);
    });

    it('yields empty arrays for an unavailable metric instead of throwing', () => {
        const data = toChartData(series({ points: [] }), 'week');

        expect(data.labels).toEqual([]);
        expect(data.values).toEqual([]);
    });
});

describe('headline', () => {
    it('shows the fold the API already chose', () => {
        expect(headlineLabel(series())).toBe('40');
        expect(headlineLabel(series({ unit: 'percent', headline: 50 }))).toBe('50%');
    });

    /**
     * "łącznie 40" and "średnio 40%" are different claims — a bare number
     * invites the wrong one.
     */
    it('names which fold the number is', () => {
        expect(headlineCaption(series())).toBe('łącznie w okresie');
        expect(headlineCaption(series({ unit: 'percent' }))).toBe('średnio w okresie');
    });

    it('says so plainly when the metric could not be read', () => {
        const broken = series({ points: [] });

        expect(headlineLabel(broken)).toBe('Brak danych');
        expect(headlineCaption(broken)).toBe('Nie udało się odczytać tej metryki');
    });
});
