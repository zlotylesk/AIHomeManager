import { describe, expect, it } from 'vitest';
import {
    TRANSACTION_TYPES,
    clampPercent,
    currentMonth,
    limitLabel,
    moneyLabel,
    typeForCategory,
    typeLabel,
} from '../budget/format.js';

describe('typeLabel', () => {
    it('labels income and expense in Polish', () => {
        expect(typeLabel('income')).toBe('Przychód');
        expect(typeLabel('expense')).toBe('Wydatek');
    });

    it('passes through an unknown type unchanged', () => {
        expect(typeLabel('other')).toBe('other');
    });
});

describe('TRANSACTION_TYPES', () => {
    it('exposes exactly the two Domain transaction types', () => {
        expect(TRANSACTION_TYPES).toEqual(['income', 'expense']);
    });
});

describe('moneyLabel', () => {
    it('formats whole minor units as a 2-decimal amount with the currency', () => {
        expect(moneyLabel(499900, 'PLN')).toBe('4999.00 PLN');
    });

    it('formats a negative balance (expenses exceeding income)', () => {
        expect(moneyLabel(-150050, 'PLN')).toBe('-1500.50 PLN');
    });

    it('omits the currency suffix when none is given', () => {
        expect(moneyLabel(1000, null)).toBe('10.00');
    });

    it('returns an em dash for a non-numeric amount', () => {
        expect(moneyLabel(undefined, 'PLN')).toBe('—');
    });
});

describe('limitLabel', () => {
    it('reports no limit distinctly from a zero limit', () => {
        expect(limitLabel(null, null)).toBe('Bez limitu');
    });

    it('formats a set limit with its currency', () => {
        expect(limitLabel(10000, 'PLN')).toBe('Limit: 100.00 PLN');
    });

    it('defaults to PLN when the limit currency is missing', () => {
        expect(limitLabel(10000, null)).toBe('Limit: 100.00 PLN');
    });
});

describe('clampPercent', () => {
    it('returns 0 for a null percentUsed (unlimited category)', () => {
        expect(clampPercent(null)).toBe(0);
    });

    it('rounds a fractional percent', () => {
        expect(clampPercent(42.6)).toBe(43);
    });

    it('clamps above 100 so an over-limit category never overflows the bar', () => {
        expect(clampPercent(150)).toBe(100);
    });

    it('clamps below 0', () => {
        expect(clampPercent(-5)).toBe(0);
    });
});

describe('currentMonth', () => {
    it('formats a given date as YYYY-MM', () => {
        expect(currentMonth(new Date(2026, 6, 15))).toBe('2026-07');
    });

    it('pads a single-digit month', () => {
        expect(currentMonth(new Date(2026, 0, 1))).toBe('2026-01');
    });
});

describe('typeForCategory', () => {
    const categories = [
        { id: 'cat-1', name: 'Jedzenie', type: 'expense' },
        { id: 'cat-2', name: 'Wynagrodzenie', type: 'income' },
    ];

    it('resolves the type of the selected category', () => {
        expect(typeForCategory(categories, 'cat-1')).toBe('expense');
        expect(typeForCategory(categories, 'cat-2')).toBe('income');
    });

    // Null, not a guessed default: with no category chosen there is no type to
    // report, and defaulting to "expense" would file income under the wrong flow.
    it('returns null when the category is unknown or unset', () => {
        expect(typeForCategory(categories, 'nope')).toBeNull();
        expect(typeForCategory(categories, '')).toBeNull();
        expect(typeForCategory(categories, undefined)).toBeNull();
        expect(typeForCategory([], 'cat-1')).toBeNull();
        expect(typeForCategory(undefined, 'cat-1')).toBeNull();
    });
});
