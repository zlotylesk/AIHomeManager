// Pure presentation helpers for the global search view. Kept free of the DOM and
// of Stimulus so they can be unit-tested in isolation
// (assets/tests/search_format.test.js) and reused by the controller's builders.

export const TYPE_LABELS = {
    article: 'Artykuł',
    book: 'Książka',
    series: 'Serial',
    music: 'Muzyka',
    task: 'Zadanie',
};

// The order source-module groups appear in the results dropdown.
export const TYPE_ORDER = Object.keys(TYPE_LABELS);

export function typeLabel(type) {
    return TYPE_LABELS[type] ?? type ?? '—';
}

// Buckets a flat result list into per-type groups. Known types come first in
// TYPE_ORDER; any unknown type is appended in first-seen order. Non-array input
// yields an empty grouping.
export function groupByType(results) {
    const byType = new Map();

    for (const result of Array.isArray(results) ? results : []) {
        const type = result && typeof result.type === 'string' ? result.type : 'other';
        if (!byType.has(type)) {
            byType.set(type, []);
        }
        byType.get(type).push(result);
    }

    const orderedTypes = [
        ...TYPE_ORDER.filter((type) => byType.has(type)),
        ...[...byType.keys()].filter((type) => !TYPE_ORDER.includes(type)),
    ];

    return orderedTypes.map((type) => ({ type, label: typeLabel(type), items: byType.get(type) }));
}
