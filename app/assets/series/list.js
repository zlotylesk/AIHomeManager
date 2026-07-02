// Pure list helpers for the Series list view — filter by title, sort by the
// selected key. No DOM, no mutation of the input. Covered by
// assets/tests/series_view.test.js.

export function filterSeries(list, searchTerm) {
    const term = (searchTerm ?? '').trim().toLowerCase();
    return term
        ? list.filter(s => s.title.toLowerCase().includes(term))
        : [...list];
}

export function sortSeries(list, key) {
    const desc = (a, b) => (b ?? -Infinity) - (a ?? -Infinity);
    const sorted = [...list];
    switch (key) {
        case 'rating-desc':
            sorted.sort((a, b) => desc(a.averageRating, b.averageRating));
            break;
        case 'own-desc':
            sorted.sort((a, b) => desc(a.rating, b.rating));
            break;
        case 'created-desc':
            sorted.sort((a, b) => (b.createdAt ?? '').localeCompare(a.createdAt ?? ''));
            break;
        default:
            sorted.sort((a, b) => a.title.localeCompare(b.title));
    }
    return sorted;
}
