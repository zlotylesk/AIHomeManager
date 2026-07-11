// Pure presentation helpers for the Goals view. Kept free of the DOM and of
// Stimulus so they can be unit-tested in isolation (assets/tests/goals_format.test.js)
// and reused by the controller's HTML builders.

export const TYPE_LABELS = {
    book_pages: 'Strony książek',
    series_episodes: 'Odcinki seriali',
    articles_read: 'Przeczytane artykuły',
    music_albums: 'Albumy muzyczne',
    youtube_videos: 'Filmy YouTube',
};

export const PERIOD_LABELS = {
    daily: 'Dziennie',
    weekly: 'Tygodniowo',
    monthly: 'Miesięcznie',
};

export const GOAL_TYPES = Object.keys(TYPE_LABELS);
export const GOAL_PERIODS = Object.keys(PERIOD_LABELS);

export function typeLabel(type) {
    return TYPE_LABELS[type] ?? type ?? '—';
}

export function periodLabel(period) {
    return PERIOD_LABELS[period] ?? period ?? '—';
}

// Clamp any incoming percent into a safe 0–100 integer for the progress bar.
export function clampPercent(percent) {
    const n = Number(percent);
    if (!Number.isFinite(n)) {
        return 0;
    }
    return Math.max(0, Math.min(100, Math.round(n)));
}

export function progressText(achieved, target) {
    return `${Number(achieved) || 0} / ${Number(target) || 0}`;
}

function dayWord(n) {
    return 1 === n ? 'dzień' : 'dni';
}

export function streakLabel(currentLength) {
    const n = Number(currentLength) || 0;
    return n <= 0 ? 'Brak passy' : `${n} ${dayWord(n)} z rzędu`;
}

export function longestLabel(longestLength) {
    const n = Number(longestLength) || 0;
    return n <= 0 ? 'Rekord: —' : `Rekord: ${n} ${dayWord(n)}`;
}
