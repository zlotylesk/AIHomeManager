import { describe, expect, it } from 'vitest';
import {
    MEAL_SLOTS,
    MEASUREMENT_UNITS,
    dayHeading,
    ingredientCountLabel,
    ingredientLine,
    mealTitle,
    metaLine,
    prepTimeLabel,
    quantityLabel,
    servingsLabel,
    shiftWeek,
    shoppingLine,
    shoppingQuantityLabel,
    slotLabel,
    unitLabel,
    weekOf,
    windowLabel,
} from '../recipes/format.js';

describe('slotLabel', () => {
    it('maps every slot the API can return', () => {
        expect(MEAL_SLOTS.map(slotLabel)).toEqual(['Śniadanie', 'Obiad', 'Kolacja', 'Przekąska']);
    });

    it('does not swap lunch and dinner', () => {
        // The one pair that is easy to get backwards in translation: obiad is
        // the midday meal, kolacja the evening one.
        expect(slotLabel('lunch')).toBe('Obiad');
        expect(slotLabel('dinner')).toBe('Kolacja');
    });

    it('passes an unknown slot through rather than blanking it', () => {
        expect(slotLabel('brunch')).toBe('brunch');
    });
});

describe('unitLabel', () => {
    it('has a label for every unit the enum declares', () => {
        MEASUREMENT_UNITS.forEach((unit) => {
            expect(unitLabel(unit)).toBeTruthy();
        });
    });

    it('translates the spelled-out units', () => {
        expect(unitLabel('piece')).toBe('szt.');
        expect(unitLabel('tablespoon')).toBe('łyżka');
        expect(unitLabel('pinch')).toBe('szczypta');
    });
});

describe('quantityLabel', () => {
    it('never prints a raw float sum', () => {
        // 0.1 + 0.2 surfaces as 0.30000000000000004 — the case the DTO warns
        // about, which is the whole reason this helper exists.
        expect(quantityLabel(0.1 + 0.2, 'l')).toBe('0.3');
    });

    it('counts grams and millilitres whole', () => {
        expect(quantityLabel(333.3333333, 'g')).toBe('333');
        expect(quantityLabel(166.6666, 'ml')).toBe('167');
    });

    it('keeps three decimals for kilograms and litres', () => {
        // 0.667 l rounded to a whole litre would be a different amount of milk.
        expect(quantityLabel(0.6666666, 'l')).toBe('0.667');
        expect(quantityLabel(1.5, 'kg')).toBe('1.5');
    });

    it('keeps half and quarter measures for spoons and cups', () => {
        expect(quantityLabel(0.5, 'tablespoon')).toBe('0.5');
        expect(quantityLabel(1.25, 'cup')).toBe('1.25');
    });

    it('states an indivisible unit as the recipe wrote it', () => {
        // Rounding up belongs to the shopping list, not here. Half an onion is
        // an ordinary recipe line, and displaying it as a whole one would
        // restate what the user typed — while the edit form, which shows the
        // stored value, would go on saying 0.5.
        expect(quantityLabel(0.5, 'piece')).toBe('0.5');
        expect(quantityLabel(1.3333, 'piece')).toBe('1.33');
        expect(quantityLabel(2, 'piece')).toBe('2');
    });

    it('reports a missing quantity rather than showing it as zero', () => {
        // null and '' coerce to a finite 0, so "0 g mąka" would read as a real
        // amount on a shopping list — worse than an obvious dash.
        expect(quantityLabel(null, 'g')).toBe('—');
        expect(quantityLabel(undefined, 'g')).toBe('—');
        expect(quantityLabel('', 'g')).toBe('—');
        expect(quantityLabel('nie wiem', 'g')).toBe('—');
    });

    it('still shows a genuine zero as zero', () => {
        expect(quantityLabel(0, 'g')).toBe('0');
    });

    // These are the exact numbers ShoppingListExporterTest names on the PHP
    // side. The rounding contract is implemented twice — here for the screen,
    // there for the CSV/PDF export — and a decimal half is the one input where
    // the two languages disagree by default: toFixed() rounds the binary double
    // (1.005 is really 1.00499999999999989, so it would give "1.00") while PHP's
    // number_format() rounds the decimal the value prints as. A shopping list
    // whose printout differed from the screen at the last digit would undermine
    // the one thing the export is for, so the pair is pinned on both sides.
    it('rounds a decimal half the way the export does', () => {
        expect(quantityLabel(1.005, 'cup')).toBe('1.01');
        expect(quantityLabel(2.675, 'tablespoon')).toBe('2.68');
        expect(quantityLabel(1.115, 'teaspoon')).toBe('1.12');
        expect(quantityLabel(1.0005, 'kg')).toBe('1.001');
        expect(quantityLabel(0.0005, 'l')).toBe('0.001');
        expect(quantityLabel(0.5, 'g')).toBe('1');
    });
});

describe('shoppingQuantityLabel', () => {
    it('rounds indivisible units UP, never down', () => {
        // Scaling 2 eggs by two thirds gives 1.33; rounding down leaves the
        // cook an egg short halfway through the recipe.
        expect(shoppingQuantityLabel(1.3333, 'piece')).toBe('2');
        expect(shoppingQuantityLabel(0.1, 'piece')).toBe('1');
        expect(shoppingQuantityLabel(2.0001, 'pinch')).toBe('3');
    });

    it('does not inflate an exact whole of an indivisible unit', () => {
        // A float that is 2 for every practical purpose must stay 2 — rounding
        // 1.9999999999 up to 2 is right, but so is leaving a clean 2 alone.
        expect(shoppingQuantityLabel(2, 'piece')).toBe('2');
        expect(shoppingQuantityLabel(6 * (1 / 3) * 1, 'piece')).toBe('2');
    });

    it('formats every other unit exactly like the recipe view', () => {
        // The buying rule is the ONLY difference between the two; a divisible
        // unit must not read one way on the list and another in the recipe.
        for (const [quantity, unit] of [[333.3333, 'g'], [0.6666666, 'l'], [1.005, 'cup'], [1.5, 'kg']]) {
            expect(shoppingQuantityLabel(quantity, unit)).toBe(quantityLabel(quantity, unit));
        }
    });

    it('reports a missing quantity rather than showing it as zero', () => {
        expect(shoppingQuantityLabel(null, 'piece')).toBe('—');
        expect(shoppingQuantityLabel('', 'g')).toBe('—');
    });
});

describe('ingredientLine', () => {
    it('renders quantity, unit and name', () => {
        expect(ingredientLine({ name: 'Mąka', quantity: 500, unit: 'g' })).toBe('500 g Mąka');
        expect(ingredientLine({ name: 'Cebula', quantity: 0.5, unit: 'piece' })).toBe('0.5 szt. Cebula');
    });

    it('degrades to an empty string on no item', () => {
        expect(ingredientLine(null)).toBe('');
    });
});

describe('shoppingLine', () => {
    it('renders the buying quantity, not the recipe one', () => {
        expect(shoppingLine({ name: 'Mąka', quantity: 500, unit: 'g' })).toBe('500 g Mąka');
        // Two recipes each wanting half an onion is one onion to buy.
        expect(shoppingLine({ name: 'Cebula', quantity: 1.0, unit: 'piece' })).toBe('1 szt. Cebula');
        expect(shoppingLine({ name: 'Jajko', quantity: 1.33, unit: 'piece' })).toBe('2 szt. Jajko');
    });

    it('degrades to an empty string on no item', () => {
        expect(shoppingLine(null)).toBe('');
    });
});

describe('servingsLabel', () => {
    it('declines porcja the Polish way', () => {
        expect(servingsLabel(1)).toBe('1 porcja');
        expect(servingsLabel(2)).toBe('2 porcje');
        expect(servingsLabel(4)).toBe('4 porcje');
        expect(servingsLabel(5)).toBe('5 porcji');
        expect(servingsLabel(22)).toBe('22 porcje');
    });

    it('applies the teens exception', () => {
        expect(servingsLabel(12)).toBe('12 porcji');
        expect(servingsLabel(13)).toBe('13 porcji');
        expect(servingsLabel(14)).toBe('14 porcji');
    });

    it('says nothing for a nonsensical count', () => {
        expect(servingsLabel(0)).toBe('');
        expect(servingsLabel(null)).toBe('');
    });
});

describe('prepTimeLabel', () => {
    it('shows minutes below an hour', () => {
        expect(prepTimeLabel(30)).toBe('30 min');
    });

    it('splits an hour or more', () => {
        expect(prepTimeLabel(60)).toBe('1 h');
        expect(prepTimeLabel(95)).toBe('1 h 35 min');
        expect(prepTimeLabel(120)).toBe('2 h');
    });

    it('treats an unrecorded prep time as absent, not as zero', () => {
        expect(prepTimeLabel(null)).toBe('');
        expect(prepTimeLabel(undefined)).toBe('');
    });
});

describe('ingredientCountLabel', () => {
    it('declines składnik the Polish way', () => {
        expect(ingredientCountLabel(1)).toBe('1 składnik');
        expect(ingredientCountLabel(3)).toBe('3 składniki');
        expect(ingredientCountLabel(12)).toBe('12 składników');
    });
});

describe('metaLine', () => {
    it('joins what the recipe has', () => {
        expect(metaLine({ ingredientCount: 3, prepTimeMinutes: 30, servings: 4 }))
            .toBe('3 składniki · 30 min · 4 porcje');
    });

    it('skips a missing prep time instead of leaving a gap', () => {
        expect(metaLine({ ingredientCount: 1, prepTimeMinutes: null, servings: 1 }))
            .toBe('1 składnik · 1 porcja');
    });
});

describe('weekOf', () => {
    it('starts the week on Monday', () => {
        // 2026-08-05 is a Wednesday.
        expect(weekOf(new Date(2026, 7, 5))).toEqual({ from: '2026-08-03', to: '2026-08-09' });
    });

    it('treats Sunday as the last day of its week, not the first', () => {
        // getDay() reports 0 for Sunday, which would otherwise make 2026-08-09
        // start a week of its own.
        expect(weekOf(new Date(2026, 7, 9))).toEqual({ from: '2026-08-03', to: '2026-08-09' });
    });

    it('keeps Monday itself as the start', () => {
        expect(weekOf(new Date(2026, 7, 3))).toEqual({ from: '2026-08-03', to: '2026-08-09' });
    });

    it('spans a month boundary', () => {
        expect(weekOf(new Date(2026, 6, 30))).toEqual({ from: '2026-07-27', to: '2026-08-02' });
    });

    it('ignores the time of day it was built at', () => {
        expect(weekOf(new Date(2026, 7, 5, 23, 45))).toEqual({ from: '2026-08-03', to: '2026-08-09' });
    });
});

describe('shiftWeek', () => {
    it('steps forward and back a whole week', () => {
        const week = { from: '2026-08-03', to: '2026-08-09' };

        expect(shiftWeek(week, 1)).toEqual({ from: '2026-08-10', to: '2026-08-16' });
        expect(shiftWeek(week, -1)).toEqual({ from: '2026-07-27', to: '2026-08-02' });
    });

    it('crosses a year boundary', () => {
        expect(shiftWeek({ from: '2026-12-28', to: '2027-01-03' }, 1))
            .toEqual({ from: '2027-01-04', to: '2027-01-10' });
    });

    it('crosses the spring DST change without losing a day', () => {
        // Poland springs forward on 2027-03-28, so that week is 167 hours, not
        // 168 — an arithmetic walk in milliseconds would land an hour short and
        // round the last day backwards.
        const shifted = shiftWeek({ from: '2027-03-22', to: '2027-03-28' }, 1);

        expect(shifted).toEqual({ from: '2027-03-29', to: '2027-04-04' });
    });
});

describe('dayHeading', () => {
    it('names the weekday and the date', () => {
        expect(dayHeading('2026-08-03')).toBe('poniedziałek 3.08');
        expect(dayHeading('2026-08-09')).toBe('niedziela 9.08');
    });

    it('passes an unparsable value through', () => {
        expect(dayHeading('kiedyś')).toBe('kiedyś');
    });
});

describe('windowLabel', () => {
    it('renders the range', () => {
        expect(windowLabel({ from: '2026-08-03', to: '2026-08-09' })).toBe('3.08 – 9.08.2026');
    });

    it('says nothing without a window', () => {
        expect(windowLabel(null)).toBe('');
        expect(windowLabel({ from: '2026-08-03' })).toBe('');
    });
});

describe('mealTitle', () => {
    it('uses the recipe title', () => {
        expect(mealTitle({ recipeTitle: 'Naleśniki' })).toBe('Naleśniki');
    });

    it('names an entry whose recipe went missing so it can be removed', () => {
        // The API keeps such a row (LEFT JOIN) precisely so the card does not
        // vanish while still occupying its slot.
        expect(mealTitle({ recipeTitle: null })).toBe('Przepis usunięty');
    });
});
