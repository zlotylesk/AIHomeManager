/**
 * Pure presentation helpers for the trends dashboard.
 *
 * Everything here is free of DOM and Chart.js so it can be unit-tested on its
 * own — the chart library only ever receives the shapes these functions return.
 */

const METRIC_LABELS = {
    books_pages_read: 'Tempo czytania',
    series_episodes_watched: 'Obejrzane odcinki',
    youtube_minutes_watched: 'YouTube',
    music_tracks_played: 'Odsłuchane utwory',
    tasks_completion_rate: 'Ukończone zadania',
};

const UNIT_SUFFIXES = {
    count: '',
    minutes: ' min',
    percent: '%',
};

const GRANULARITY_LABELS = {
    week: 'Tygodniowo',
    month: 'Miesięcznie',
};

/** A rate is a level per bucket, so a line reads it better than bars. */
const RATE_UNIT = 'percent';

export function metricLabel(metric) {
    return METRIC_LABELS[metric] ?? metric;
}

export function granularityLabel(granularity) {
    return GRANULARITY_LABELS[granularity] ?? granularity;
}

/**
 * Format a value with its unit. Whole numbers lose the decimal tail, because
 * "40 stron" reads better than "40.00 stron" on a card.
 */
export function valueLabel(value, unit) {
    if (typeof value !== 'number' || !Number.isFinite(value)) {
        return '—';
    }

    const rounded = Math.round(value * 10) / 10;
    const text = Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(1);

    return `${text}${UNIT_SUFFIXES[unit] ?? ''}`;
}

/**
 * An empty point list is the API's signal that the metric could not be read —
 * a healthy metric always fills its window, an idle one with zeroes. The two
 * must not look the same on screen.
 */
export function isUnavailable(series) {
    return !series || !Array.isArray(series.points) || series.points.length === 0;
}

/**
 * True when the metric was read but nothing happened in the whole window.
 */
export function isIdle(series) {
    return !isUnavailable(series) && series.points.every((point) => point.value === 0);
}

/**
 * Short axis label for a bucket start. A week is named by its day and month
 * ("6.07"), a month by its month and year ("07.2026") — a full date on every
 * tick would collide on a narrow phone.
 */
export function bucketLabel(bucketStart, granularity) {
    if (typeof bucketStart !== 'string' || bucketStart.length < 10) {
        return '';
    }

    const [year, month, day] = bucketStart.split('-');

    return 'month' === granularity ? `${month}.${year}` : `${Number(day)}.${month}`;
}

/**
 * Map an API series onto the chart's data shape. Bars for a cumulative metric
 * (each bucket is a quantity that happened), a line for a rate (each bucket is
 * a level, and bars would suggest the percentages add up).
 */
export function toChartData(series, granularity) {
    const points = isUnavailable(series) ? [] : series.points;

    return {
        type: series && series.unit === RATE_UNIT ? 'line' : 'bar',
        labels: points.map((point) => bucketLabel(point.bucketStart, granularity)),
        values: points.map((point) => point.value),
        suggestedMax: series && series.unit === RATE_UNIT ? 100 : undefined,
    };
}

/**
 * The single figure shown on the card: the fold the API already decided is
 * meaningful for this metric (a total for a count, an average for a rate).
 */
export function headlineLabel(series) {
    if (isUnavailable(series)) {
        return 'Brak danych';
    }

    return valueLabel(series.headline, series.unit);
}

/**
 * How the headline should be read. Naming the fold matters: "łącznie 40 stron"
 * and "średnio 40%" are different claims, and a bare number invites the wrong one.
 */
export function headlineCaption(series) {
    if (isUnavailable(series)) {
        return 'Nie udało się odczytać tej metryki';
    }

    return series.unit === RATE_UNIT ? 'średnio w okresie' : 'łącznie w okresie';
}
