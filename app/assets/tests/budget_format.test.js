import { describe, expect, it } from 'vitest';
import {
    TRANSACTION_TYPES,
    clampPercent,
    currentMonth,
    limitLabel,
    moneyLabel,
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
