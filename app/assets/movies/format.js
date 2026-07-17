// Pure presentation helpers for the Movies view. Kept free of the DOM and of
// Stimulus so they can be unit-tested in isolation (assets/tests/movies_format.test.js)
// and reused by the controller's HTML builders.

export const STATUS_LABELS = {
    released: 'Wydany',
    upcoming: 'Zapowiedź',
};

export const MOVIE_STATUSES = Object.keys(STATUS_LABELS);

// Watched/unwatched list filter — value maps to the API `?watched=` query param.
export const FILTERS = [
    { value: 'all', label: 'Wszystkie' },
    { value: 'watched', label: 'Obejrzane' },
    { value: 'unwatched', label: 'Nieobejrzane' },
];

export function statusLabel(status) {
    if (!status) {
        return '—';
    }
    return STATUS_LABELS[status] ?? status;
}

export function watchedLabel(watched) {
    return watched ? 'Obejrzany' : 'Nieobejrzany';
}

export function ratingLabel(rating) {
    const n = Number(rating);
    return Number.isInteger(n) && n >= 1 && n <= 10 ? `Ocena: ${n}/10` : 'Brak oceny';
}

export function yearLabel(year) {
    const n = Number(year);
    return Number.isInteger(n) && n > 0 ? String(n) : '—';
}

// Compact "year • status" line for the card and detail header; drops empty parts.
export function metaLine(movie) {
    const parts = [];
    const year = yearLabel(movie?.year);
    if ('—' !== year) {
        parts.push(year);
    }
    if (movie?.status) {
        parts.push(statusLabel(movie.status));
    }
    return parts.join(' • ');
}

// Maps a filter value to the API `?watched=` query string suffix.
export function watchedQuery(filter) {
    if ('watched' === filter) {
        return '?watched=true';
    }
    if ('unwatched' === filter) {
        return '?watched=false';
    }
    return '';
}
