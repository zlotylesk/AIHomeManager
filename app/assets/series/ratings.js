// Pure presentation helpers for ratings — no DOM, no side effects. Covered by
// assets/tests/series_view.test.js.

export function avg(nums) {
    const filtered = nums.filter(n => n !== null && n !== undefined);
    if (!filtered.length) return null;
    return Math.round((filtered.reduce((a, b) => a + b, 0) / filtered.length) * 100) / 100;
}

export function cardRating(label, value) {
    const has = value !== null && value !== undefined;
    return `<span class="card-rating${has ? '' : ' card-rating-empty'}">${label} ${has ? `★ ${value}` : '—'}</span>`;
}

export function statusLabel(status) {
    return {ongoing: 'Ongoing', ended: 'Ended'}[status] ?? null;
}

export function ratingHighlight(entity) {
    if (entity.episodeCount > 0 && entity.watchedCount < entity.episodeCount) {
        return 'incomplete';
    }
    if (entity.averageRating === null || entity.averageRating === undefined) {
        return null;
    }
    if (entity.rating === null || entity.rating === undefined) {
        return 'mismatch';
    }
    return Math.round(entity.averageRating) !== entity.rating ? 'mismatch' : null;
}

export function ratingFlag(entity) {
    const state = ratingHighlight(entity);
    if (state === 'incomplete') {
        return {cls: 'is-rating-incomplete', title: `W toku — obejrzane ${entity.watchedCount}/${entity.episodeCount} odcinków`};
    }
    if (state === 'mismatch') {
        const avgRounded = Math.round(entity.averageRating);
        const title = entity.rating === null || entity.rating === undefined
            ? `Brak Twojej oceny (średnia ${avgRounded})`
            : `Twoja ocena ${entity.rating} ≠ średnia ${avgRounded}`;
        return {cls: 'is-rating-mismatch', title};
    }
    return {cls: '', title: ''};
}

export function cardRatingFlag(s) {
    if (s.episodeCount > 0 && s.watchedCount < s.episodeCount) {
        return {cls: 'is-rating-incomplete', title: `W toku — obejrzane ${s.watchedCount}/${s.episodeCount} odcinków`};
    }
    if (ratingHighlight(s) === 'mismatch' || (s.seasons ?? []).some(se => ratingHighlight(se) === 'mismatch')) {
        return {cls: 'is-rating-mismatch', title: 'Twoja ocena ≠ średnia z odcinków (serial lub sezon)'};
    }
    return {cls: '', title: ''};
}
