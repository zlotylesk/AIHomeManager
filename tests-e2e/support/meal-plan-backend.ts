import { type Page } from '@playwright/test';

/**
 * The in-memory `/api/meal-plan` + `/api/recipes` fake both meal-plan specs
 * drive. The `/meal-plan` page is server-rendered and fills itself from the
 * API, so the specs stub the API rather than the page (the Budget/Podcasts
 * route-stub precedent).
 *
 * It lives here rather than in one spec because the desktop and mobile specs
 * need the *same* fake: what it returns encodes real contract decisions — the
 * gap-filled calendar, the 409 on a duplicate placement, the per-meal servings
 * scaling — and a second copy is how one of them would quietly drift into
 * describing an API the server does not have. Playwright's `testMatch` only
 * collects `*.desktop.spec.ts` / `*.mobile.spec.ts`, so this file sits inside
 * `testDir` without being picked up as a suite of its own.
 */

type PlannedMeal = { id: string; recipeId: string; recipeTitle: string | null; servings: number };
export type PlanEntry = { id: string; date: string; slot: string; recipeId: string; recipeTitle: string | null; servings: number };
export type PlanState = { entries: PlanEntry[]; nextId: number };

const SLOTS = ['breakfast', 'lunch', 'dinner', 'snack'];

const RECIPES = [
  { id: 'rec-1', title: 'Naleśniki', servings: 4, prepTimeMinutes: 30, tags: ['obiad'], ingredientCount: 2 },
  { id: 'rec-2', title: 'Zupa pomidorowa', servings: 6, prepTimeMinutes: null, tags: ['zupa'], ingredientCount: 1 },
];

function eachDay(from: string, to: string): string[] {
  const [fy, fm, fd] = from.split('-').map(Number);
  const [ty, tm, td] = to.split('-').map(Number);
  const start = new Date(fy, fm - 1, fd);
  const end = new Date(ty, tm - 1, td);
  const days: string[] = [];

  for (let d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
    days.push(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`);
  }

  return days;
}

/**
 * Mirrors the API's gap filling: every day of the window, every slot of the
 * day, empty ones included. The UI draws its grid straight from this, so a stub
 * that omitted the empty slots would exercise a payload the server never sends.
 */
function buildPlan(state: PlanState, from: string, to: string) {
  return {
    from,
    to,
    days: eachDay(from, to).map((date) => ({
      date,
      slots: SLOTS.map((slot) => ({
        slot,
        meals: state.entries
          .filter((entry) => entry.date === date && entry.slot === slot)
          .map<PlannedMeal>((entry) => ({
            id: entry.id,
            recipeId: entry.recipeId,
            recipeTitle: entry.recipeTitle,
            servings: entry.servings,
          })),
      })),
    })),
  };
}

function buildShoppingList(state: PlanState, from: string, to: string) {
  const inWindow = state.entries.filter((entry) => entry.date >= from && entry.date <= to);
  // Two ingredients of the 4-portion recipe, both scaled by the planned
  // servings: flour proves the scaling reaches the view at all, and the eggs
  // are there because an indivisible unit is the one place the shopping list
  // formats differently from the recipe it came from.
  const scale = inWindow
    .filter((entry) => 'rec-1' === entry.recipeId)
    .reduce((sum, entry) => sum + entry.servings / 4, 0);

  return {
    from,
    to,
    items: scale > 0
      ? [
          { name: 'Mąka', unit: 'g', quantity: 500 * scale },
          { name: 'Jajko', unit: 'piece', quantity: 3 * scale },
        ]
      : [],
  };
}

export async function installMealPlanBackend(page: Page, state: PlanState): Promise<void> {
  await page.route(/\/api\/(meal-plan|recipes)/, async (route) => {
    const request = route.request();
    const method = request.method();
    const url = new URL(request.url());
    const path = url.pathname;
    const respond = (status: number, body?: unknown) =>
      route.fulfill(undefined === body
        ? { status }
        : { status, contentType: 'application/json', body: JSON.stringify(body) });

    if ('/api/recipes' === path) {
      return respond(200, RECIPES);
    }

    if ('/api/meal-plan/shopping-list' === path) {
      return respond(200, buildShoppingList(state, url.searchParams.get('from')!, url.searchParams.get('to')!));
    }

    if ('/api/meal-plan' === path) {
      if ('POST' === method) {
        const body = request.postDataJSON() as { date: string; slot: string; recipeId: string; servings: number };
        const clash = state.entries.some(
          (e) => e.date === body.date && e.slot === body.slot && e.recipeId === body.recipeId,
        );
        if (clash) {
          return respond(409, { error: 'Already planned.' });
        }

        const id = `meal-${state.nextId++}`;
        state.entries.push({
          id,
          date: body.date,
          slot: body.slot,
          recipeId: body.recipeId,
          recipeTitle: RECIPES.find((r) => r.id === body.recipeId)?.title ?? null,
          servings: body.servings,
        });

        return respond(201, { id });
      }

      return respond(200, buildPlan(state, url.searchParams.get('from')!, url.searchParams.get('to')!));
    }

    const entryMatch = path.match(/^\/api\/meal-plan\/([^/]+)$/);
    if (entryMatch) {
      const id = entryMatch[1];

      if ('DELETE' === method) {
        state.entries = state.entries.filter((e) => e.id !== id);
        return respond(204);
      }

      if ('PATCH' === method) {
        const body = request.postDataJSON() as { date: string; slot: string };
        const moving = state.entries.find((e) => e.id === id);
        const clash = state.entries.some(
          (e) => e.id !== id && e.date === body.date && e.slot === body.slot && e.recipeId === moving?.recipeId,
        );
        if (clash) {
          return respond(409, { error: 'Already planned.' });
        }

        state.entries = state.entries.map((e) => (e.id === id ? { ...e, date: body.date, slot: body.slot } : e));
        return respond(204);
      }
    }

    return respond(404, { error: 'Unhandled.' });
  });
}

/**
 * Monday..Sunday of the current week — the window the page opens on.
 *
 * Computed here rather than in the browser: both run on the same machine in the
 * same timezone, so the result is identical, and doing it in Node lets a test
 * seed the plan *before* the first navigation instead of mutating state and
 * reloading. One navigation per test is not just faster — the reload was a
 * second chance for the page load to fail.
 */
export function currentWeek(): { from: string; to: string } {
  const now = new Date();
  const base = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const from = new Date(base.getFullYear(), base.getMonth(), base.getDate() - ((base.getDay() + 6) % 7));
  const to = new Date(from.getFullYear(), from.getMonth(), from.getDate() + 6);
  const iso = (d: Date) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

  return { from: iso(from), to: iso(to) };
}

export function planned(overrides: Partial<PlanEntry> = {}): PlanEntry {
  return {
    id: 'meal-1',
    date: currentWeek().from,
    slot: 'lunch',
    recipeId: 'rec-1',
    recipeTitle: 'Naleśniki',
    servings: 4,
    ...overrides,
  };
}
