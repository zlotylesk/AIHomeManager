// Pure presentation helpers for the Budget view. Kept free of the DOM and of
// Stimulus so they can be unit-tested in isolation (assets/tests/budget_format.test.js)
// and reused by the controller's HTML builders.

export const TRANSACTION_TYPES = ['income', 'expense'];

export function typeLabel(type) {
    if ('income' === type) {
        return 'Przychód';
    }
    if ('expense' === type) {
        return 'Wydatek';
    }

    return type;
}

// Amounts arrive as whole minor units (grosze) so a month of summed
// transactions cannot drift on float rounding; the UI is the one place that
// divides by 100 for display.
export function moneyLabel(amountInCents, currency) {
    const cents = Number(amountInCents);
    if (!Number.isFinite(cents)) {
        return '—';
    }

    const value = (cents / 100).toFixed(2);

    return currency ? `${value} ${currency}` : value;
}

// A category with no monthly limit reports null percentUsed/monthlyLimitInCents
// (HMAI-380) — "no limit" is a distinct state from "0% used", never invented.
export function limitLabel(monthlyLimitInCents, currency) {
    return null === monthlyLimitInCents || undefined === monthlyLimitInCents
        ? 'Bez limitu'
        : `Limit: ${moneyLabel(monthlyLimitInCents, currency || 'PLN')}`;
}

// percentUsed is null for an unlimited category; clamped at 100 so a category
// spent past its limit still renders a full (not overflowing) bar — the
// exceeding state is carried separately by `overLimit`, not by percent > 100.
export function clampPercent(percentUsed) {
    const value = Number(percentUsed);
    if (null === percentUsed || undefined === percentUsed || !Number.isFinite(value)) {
        return 0;
    }

    return Math.min(100, Math.max(0, Math.round(value)));
}

// A transaction's income/expense type is decided by the category it is filed
// under, not chosen alongside it: a category's type is immutable precisely
// because it defines which money flow the category tracks, and the API rejects
// a transaction whose type disagrees with it. Offering the two as independent
// pickers let the user build a combination that could only ever be refused, so
// the form derives the type from here instead. Returns null when no category is
// selected yet (the empty form on first paint).
export function typeForCategory(categories, categoryId) {
    const category = (categories || []).find((c) => c && c.id === categoryId);

    return category ? category.type : null;
}

// YYYY-MM for the month filters/report picker default. Accepts a Date for
// testability rather than always reading the system clock.
export function currentMonth(date = new Date()) {
    const month = String(date.getMonth() + 1).padStart(2, '0');

    return `${date.getFullYear()}-${month}`;
}
