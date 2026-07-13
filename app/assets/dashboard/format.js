// Pure presentation helpers for the Dashboard cockpit (HMAI-262). Kept free of
// the DOM and of Stimulus so they can be unit-tested in isolation
// (assets/tests/dashboard_format.test.js) and reused by the controller's HTML
// builders. Each widget reads its own section of the /api/dashboard read model.

export const GOAL_TYPE_LABELS = {
    book_pages: 'Strony książek',
    series_episodes: 'Odcinki seriali',
    articles_read: 'Przeczytane artykuły',
    music_albums: 'Albumy muzyczne',
    youtube_videos: 'Filmy YouTube',
};

export const GOAL_PERIOD_LABELS = {
    daily: 'Dziennie',
    weekly: 'Tygodniowo',
    monthly: 'Miesięcznie',
};

export const RECOMMENDATION_KIND_LABELS = {
    series: 'Serial',
    book: 'Książka',
};

export const MUSIC_SOURCE_LABELS = {
    lastfm: 'Last.fm',
    discogs: 'Discogs',
    vinyl: 'Winyl',
    manual: 'Ręcznie',
};

// Per-widget empty-state copy — a missing widget must not break the layout.
export const EMPTY_STATE_LABELS = {
    tasks: 'Brak zadań na dziś. 🎉',
    article: 'Brak artykułu na dziś.',
    goals: 'Brak celów — dodaj pierwszy w zakładce Cele.',
    recommendations: 'Brak rekomendacji — zacznij oglądać serial lub czytać książkę.',
    tracks: 'Brak ostatnio słuchanej muzyki.',
};

export function goalTypeLabel(type) {
    return GOAL_TYPE_LABELS[type] ?? type ?? '—';
}

export function goalPeriodLabel(period) {
    return GOAL_PERIOD_LABELS[period] ?? period ?? '—';
}

export function recommendationKindLabel(kind) {
    return RECOMMENDATION_KIND_LABELS[kind] ?? kind ?? '—';
}

export function musicSourceLabel(source) {
    const key = String(source ?? '').toLowerCase();
    return MUSIC_SOURCE_LABELS[key] ?? source ?? '—';
}

export function emptyStateLabel(widget) {
    return EMPTY_STATE_LABELS[widget] ?? 'Brak danych.';
}

// Extract the wall-clock HH:MM from an ISO-8601 datetime, preserving the
// server's timezone offset (no Date conversion → no browser-TZ shift).
export function formatTime(iso) {
    const match = /T(\d{2}):(\d{2})/.exec(String(iso ?? ''));
    return match ? `${match[1]}:${match[2]}` : '';
}

export function formatTimeRange(startIso, endIso) {
    const start = formatTime(startIso);
    const end = formatTime(endIso);
    if (start && end) {
        return `${start}–${end}`;
    }
    return start || end || '';
}

export function readTimeLabel(minutes) {
    const n = Number(minutes);
    return Number.isFinite(n) && n > 0 ? `~${Math.round(n)} min czytania` : '';
}

function dayWord(n) {
    return 1 === n ? 'dzień' : 'dni';
}

export function streakLabel(currentStreak) {
    const n = Number(currentStreak) || 0;
    return n <= 0 ? 'Brak passy' : `${n} ${dayWord(n)} z rzędu`;
}
