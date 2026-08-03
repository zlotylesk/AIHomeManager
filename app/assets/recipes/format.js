// Pure presentation helpers for the Recipes and meal-plan views. Kept free of
// the DOM and of Stimulus so they can be unit-tested in isolation
// (assets/tests/recipes_format.test.js) and shared by both controllers.

// The four slots in the order they happen through a day. The API already
// returns them in this order (the read is gap-filled precisely so a client
// never has to know the sequence), but the create form has to offer them from
// somewhere.
export const MEAL_SLOTS = ['breakfast', 'lunch', 'dinner', 'snack'];

// Canonical unit identifiers, in the order the enum declares them.
export const MEASUREMENT_UNITS = ['g', 'kg', 'ml', 'l', 'piece', 'tablespoon', 'teaspoon', 'cup', 'pinch'];

const SLOT_LABELS = {
    breakfast: 'Śniadanie',
    lunch: 'Obiad',
    dinner: 'Kolacja',
    snack: 'Przekąska',
};

const UNIT_LABELS = {
    g: 'g',
    kg: 'kg',
    ml: 'ml',
    l: 'l',
    piece: 'szt.',
    tablespoon: 'łyżka',
    teaspoon: 'łyżeczka',
    cup: 'szklanka',
    pinch: 'szczypta',
};

export function slotLabel(slot) {
    return SLOT_LABELS[slot] || slot;
}

export function unitLabel(unit) {
    return UNIT_LABELS[unit] || unit;
}

// How many decimals a unit is worth showing. This is the rounding contract
// ShoppingListItemDTO deliberately handed to the frontend: the API returns raw
// sums because the precision a unit deserves is a presentation decision, and
// baking one precision into the payload would be wrong for every other unit.
// Grams and millilitres are counted whole (nobody weighs 333.33 g); kilograms
// and litres need three, since 0.667 l rounded to a whole litre is a different
// amount of milk; spoons and cups get two, because half and quarter measures
// are real quantities a cook uses.
const UNIT_DECIMALS = {
    g: 0,
    ml: 0,
    kg: 3,
    l: 3,
    tablespoon: 2,
    teaspoon: 2,
    cup: 2,
    pinch: 0,
};

// Units you cannot buy a fraction of. On the SHOPPING LIST their quantities
// round UP rather than to nearest: scaling 2 eggs by two thirds gives 1.33, and
// rounding that down leaves the cook an egg short halfway through the recipe —
// the one direction of error a shopping list must not make.
//
// This is a rule about buying, not about cooking, which is why it belongs to
// shoppingQuantityLabel alone. A recipe may legitimately call for half an
// onion, and rounding that up where the recipe is displayed would restate what
// the user wrote as twice the onion.
const INDIVISIBLE_UNITS = ['piece', 'pinch'];

// Round to a number of decimals the way the export does.
//
// toFixed() rounds the exact binary double, and a decimal half is almost never
// exactly representable: 1.005 is stored as 1.00499999999999989, so
// (1.005).toFixed(2) is "1.00" — while the CSV/PDF export rounds through PHP's
// number_format(), which reads the decimal the value prints as and answers
// "1.01". Left alone the two disagree on the last digit of the same shopping
// list, and the printout taken to the shop is trusted precisely because it is
// supposed to be what the screen said.
//
// Shifting the decimal point through the string representation makes this side
// round what a person sees, which is both what the export does and what a cook
// expects.
function roundToDecimals(value, decimals) {
    const shifted = Number(`${value}e${decimals}`);
    if (!Number.isFinite(shifted)) {
        // A magnitude extreme enough that toString() switched to exponential
        // notation (below 1e-6, above 1e21). No kitchen quantity reaches it,
        // but a NaN on a shopping list would be worse than a last digit.
        return value.toFixed(decimals);
    }

    return Number(`${Math.round(shifted)}e-${decimals}`).toFixed(decimals);
}

// A quantity that is not a quantity. null and '' both coerce to a finite 0, so
// they have to be rejected before Number() ever sees them: a missing quantity
// rendered as "0 g mąka" reads as a real amount, which on a shopping list is
// worse than an obvious dash.
const NO_QUANTITY = '—';

function toQuantity(quantity) {
    if (null === quantity || undefined === quantity || '' === quantity) {
        return null;
    }

    const value = Number(quantity);

    return Number.isFinite(value) ? value : null;
}

function withUnitPrecision(value, unit) {
    const decimals = Object.prototype.hasOwnProperty.call(UNIT_DECIMALS, unit) ? UNIT_DECIMALS[unit] : 2;
    // Round, then strip trailing zeros, so 1.500 kg reads "1.5 kg" but 1.234
    // keeps its precision.
    const fixed = roundToDecimals(value, decimals);

    return decimals > 0 ? fixed.replace(/\.?0+$/, '') : fixed;
}

// Format a quantity as the recipe states it. Never prints a raw float: a float
// stored by the aggregate can surface as 0.30000000000000004.
//
// Deliberately does NOT apply the shopping list's round-up rule. A recipe is a
// set of instructions, not a purchase order: half an onion is an ordinary
// ingredient line, and rounding it up here would display something the user
// never wrote — while the edit form, which shows the stored value, would keep
// saying 0.5. An indivisible unit has no entry in UNIT_DECIMALS, so it falls
// through to the two-decimal default, which is faithful for anything a person
// would type.
export function quantityLabel(quantity, unit) {
    const value = toQuantity(quantity);

    return null === value ? NO_QUANTITY : withUnitPrecision(value, unit);
}

// Format a quantity as the shopping list needs it: what you have to come home
// with. This is where the round-up rule for indivisible units belongs.
export function shoppingQuantityLabel(quantity, unit) {
    const value = toQuantity(quantity);
    if (null === value) {
        return NO_QUANTITY;
    }

    if (INDIVISIBLE_UNITS.includes(unit)) {
        // The epsilon keeps a quantity that is a whole number in intent but
        // 2.0000000000000004 in floating point from being rounded up to 3.
        return String(Math.ceil(value - 1e-9));
    }

    return withUnitPrecision(value, unit);
}

// One line of a recipe's ingredients: "500 g mąka".
export function ingredientLine(item) {
    if (!item) {
        return '';
    }

    return `${quantityLabel(item.quantity, item.unit)} ${unitLabel(item.unit)} ${item.name}`;
}

// One line of the shopping list — the same shape, the buying rule.
export function shoppingLine(item) {
    if (!item) {
        return '';
    }

    return `${shoppingQuantityLabel(item.quantity, item.unit)} ${unitLabel(item.unit)} ${item.name}`;
}

// Polish plural for "porcja" — 1 porcja / 2-4 porcje / 5+ porcji, with the
// teens exception (12 porcji, not 12 porcje).
export function servingsLabel(servings) {
    const count = Number(servings);
    if (!Number.isFinite(count) || count < 1) {
        return '';
    }

    const last = count % 10;
    const lastTwo = count % 100;

    if (1 === count) {
        return '1 porcja';
    }
    if (last >= 2 && last <= 4 && (lastTwo < 12 || lastTwo > 14)) {
        return `${count} porcje`;
    }

    return `${count} porcji`;
}

// Null is a real state — a recipe with no recorded prep time, not "0 min".
export function prepTimeLabel(prepTimeMinutes) {
    const minutes = Number(prepTimeMinutes);
    if (null === prepTimeMinutes || undefined === prepTimeMinutes || !Number.isFinite(minutes)) {
        return '';
    }

    if (minutes < 60) {
        return `${minutes} min`;
    }

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return rest ? `${hours} h ${rest} min` : `${hours} h`;
}

export function ingredientCountLabel(count) {
    const value = Number(count);
    if (!Number.isFinite(value)) {
        return '';
    }

    const last = value % 10;
    const lastTwo = value % 100;

    if (1 === value) {
        return '1 składnik';
    }
    if (last >= 2 && last <= 4 && (lastTwo < 12 || lastTwo > 14)) {
        return `${value} składniki`;
    }

    return `${value} składników`;
}

// The card's one-line summary, skipping whatever the recipe does not have
// rather than printing an empty segment or a dash.
export function metaLine(recipe) {
    return [ingredientCountLabel(recipe.ingredientCount), prepTimeLabel(recipe.prepTimeMinutes), servingsLabel(recipe.servings)]
        .filter(Boolean)
        .join(' · ');
}

function toIsoDate(date) {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

// The Monday..Sunday week containing a date, as the {from, to} the API needs.
//
// The API deliberately requires both ends rather than defaulting to "this
// week": working out which day starts a week is domain knowledge that has no
// business in an HTTP layer, and a server-side default would risk answering
// confidently about a week nobody asked for. So the calendar computes it here.
// Built from local date parts rather than by subtracting milliseconds, because
// a week that straddles a DST change is not 7 × 24 h and an arithmetic walk
// would land an hour off and silently shift a day.
export function weekOf(date = new Date()) {
    const base = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    // getDay() is 0 for Sunday, which is the *last* day of a Polish week.
    const offsetToMonday = (base.getDay() + 6) % 7;
    const from = new Date(base.getFullYear(), base.getMonth(), base.getDate() - offsetToMonday);
    const to = new Date(from.getFullYear(), from.getMonth(), from.getDate() + 6);

    return { from: toIsoDate(from), to: toIsoDate(to) };
}

// Shift a {from, to} window by whole weeks. Same reasoning as weekOf: the shift
// is done in date parts, so crossing a DST boundary cannot move the window by
// an hour and round a day the wrong way.
export function shiftWeek(window, weeks) {
    const parse = (iso) => {
        const [year, month, day] = iso.split('-').map(Number);

        return new Date(year, month - 1, day + weeks * 7);
    };

    return { from: toIsoDate(parse(window.from)), to: toIsoDate(parse(window.to)) };
}

const WEEKDAYS = ['niedziela', 'poniedziałek', 'wtorek', 'środa', 'czwartek', 'piątek', 'sobota'];

// "poniedziałek 3.08" — the calendar column heading.
export function dayHeading(isoDate) {
    const [year, month, day] = String(isoDate).split('-').map(Number);
    if (!year || !month || !day) {
        return String(isoDate);
    }

    const date = new Date(year, month - 1, day);

    return `${WEEKDAYS[date.getDay()]} ${day}.${String(month).padStart(2, '0')}`;
}

// "3.08 – 9.08.2026" — the window caption above the calendar and the list.
export function windowLabel(window) {
    if (!window || !window.from || !window.to) {
        return '';
    }

    const short = (iso) => {
        const [, month, day] = String(iso).split('-');

        return `${Number(day)}.${month}`;
    };

    return `${short(window.from)} – ${short(window.to)}.${String(window.to).slice(0, 4)}`;
}

// A plan entry whose recipe went missing keeps its place on the calendar with a
// null title (the API uses a LEFT JOIN precisely so the card does not vanish
// while still occupying its slot). Naming it is what lets the user remove it.
export function mealTitle(meal) {
    return meal && meal.recipeTitle ? meal.recipeTitle : 'Przepis usunięty';
}
