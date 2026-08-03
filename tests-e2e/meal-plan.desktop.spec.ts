import { test, expect, type Page } from '@playwright/test';

type PlannedMeal = { id: string; recipeId: string; recipeTitle: string | null; servings: number };
type PlanEntry = { id: string; date: string; slot: string; recipeId: string; recipeTitle: string | null; servings: number };

const SLOTS = ['breakfast', 'lunch', 'dinner', 'snack'];

const RECIPES = [
  { id: 'rec-1', title: 'Naleśniki', servings: 4, prepTimeMinutes: 30, tags: ['obiad'], ingredientCount: 2 },
  { id: 'rec-2', title: 'Zupa pomidorowa', servings: 6, prepTimeMinutes: null, tags: ['zupa'], ingredientCount: 1 },
];

type PlanState = { entries: PlanEntry[]; nextId: number };

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

async function installMealPlanBackend(page: Page, state: PlanState): Promise<void> {
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
function currentWeek(): { from: string; to: string } {
  const now = new Date();
  const base = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const from = new Date(base.getFullYear(), base.getMonth(), base.getDate() - ((base.getDay() + 6) % 7));
  const to = new Date(from.getFullYear(), from.getMonth(), from.getDate() + 6);
  const iso = (d: Date) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

  return { from: iso(from), to: iso(to) };
}

function planned(overrides: Partial<PlanEntry> = {}): PlanEntry {
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

test.describe('Meal plan — desktop', () => {
  test('an empty week still renders every day and every slot', async ({ page }) => {
    await installMealPlanBackend(page, { entries: [], nextId: 1 });
    await page.goto('/meal-plan');

    await expect(page.locator('.meal-day')).toHaveCount(7);
    await expect(page.locator('.meal-day').first().locator('.meal-slot')).toHaveCount(4);
    // The DOM text, not the CSS-uppercased rendering. Asserting the middle two
    // is what catches obiad/kolacja being swapped — the one pair of this
    // vocabulary that is easy to get backwards in translation.
    const slots = page.locator('.meal-day').first().locator('.meal-slot-title');
    await expect(slots.nth(0)).toHaveText('Śniadanie');
    await expect(slots.nth(1)).toHaveText('Obiad');
    await expect(slots.nth(2)).toHaveText('Kolacja');
    await expect(slots.nth(3)).toHaveText('Przekąska');
  });

  test('an empty week has nothing to buy, and says so', async ({ page }) => {
    await installMealPlanBackend(page, { entries: [], nextId: 1 });
    await page.goto('/meal-plan');

    await expect(page.locator('.shopping-list-panel')).toContainText('Nic do kupienia');
  });

  test('plans a meal and the shopping list picks it up scaled by servings', async ({ page }) => {
    const state: PlanState = { entries: [], nextId: 1 };
    await installMealPlanBackend(page, state);
    await page.goto('/meal-plan');

    const week = currentWeek();
    await page.locator('[data-meal-plan-target="recipeSelect"]').selectOption('rec-1');
    await page.locator('[data-meal-plan-target="planDate"]').fill(week.from);
    await page.locator('[data-meal-plan-target="planSlot"]').selectOption('lunch');
    await page.locator('[data-meal-plan-target="planServings"]').fill('6');
    await page.getByRole('button', { name: 'Zaplanuj' }).click();

    await expect(page.locator('.meal-card')).toHaveCount(1);
    await expect(page.locator('.meal-card')).toContainText('Naleśniki');
    await expect(page.locator('.meal-card')).toContainText('6 porcji');

    // 500 g for 4 portions, cooked for 6 → 750 g, rendered whole because grams
    // are counted whole.
    await expect(page.locator('.shopping-item').nth(0)).toHaveText('750 g Mąka');
    // 3 eggs for 4 portions, cooked for 6 → 4.5, and the list says 5. Rounding
    // to nearest would say 4 and leave the cook half an egg short; this is the
    // one direction of error a shopping list must not make. The recipe itself
    // still states 3 — the round-up belongs to buying, not to cooking.
    await expect(page.locator('.shopping-item').nth(1)).toHaveText('5 szt. Jajko');
  });

  test('two different recipes share one slot, the same one twice is refused', async ({ page }) => {
    const state: PlanState = { entries: [], nextId: 1 };
    await installMealPlanBackend(page, state);
    await page.goto('/meal-plan');

    const week = currentWeek();
    const plan = async (recipeId: string) => {
      await page.locator('[data-meal-plan-target="recipeSelect"]').selectOption(recipeId);
      await page.locator('[data-meal-plan-target="planDate"]').fill(week.from);
      await page.locator('[data-meal-plan-target="planSlot"]').selectOption('lunch');
      await page.getByRole('button', { name: 'Zaplanuj' }).click();
    };

    await plan('rec-1');
    await expect(page.locator('.meal-card')).toHaveCount(1);

    // Soup plus a main course is an ordinary Polish lunch.
    await plan('rec-2');
    await expect(page.locator('.meal-card')).toHaveCount(2);

    // The same recipe twice in one slot is a double-clicked button.
    await plan('rec-1');
    await expect(page.locator('#error-banner')).toContainText('już zaplanowany w tym miejscu');
    await expect(page.locator('.meal-card')).toHaveCount(2);
  });

  test('moves a meal to another slot', async ({ page }) => {
    const week = currentWeek();
    const state: PlanState = { entries: [planned()], nextId: 2 };
    await installMealPlanBackend(page, state);
    await page.goto('/meal-plan');

    await expect(page.locator('.meal-card')).toHaveCount(1);
    await page.locator('.js-move-meal').click();
    await expect(page.locator('#info-banner')).toContainText('Wybierz slot docelowy');

    await page.locator(`.meal-slot[data-date="${week.from}"][data-slot="dinner"] .meal-slot-title`).click();

    await expect(page.locator(`.meal-slot[data-date="${week.from}"][data-slot="dinner"] .meal-card`)).toHaveCount(1);
    expect(state.entries[0].slot).toBe('dinner');
  });

  test('clicking the move button twice cancels instead of stranding the selection', async ({ page }) => {
    const week = currentWeek();
    const state: PlanState = { entries: [planned()], nextId: 2 };
    await installMealPlanBackend(page, state);
    await page.goto('/meal-plan');

    await expect(page.locator('.meal-card')).toHaveCount(1);
    await page.locator('.js-move-meal').click();
    await page.locator('.js-move-meal').click();
    await expect(page.locator('#info-banner')).toContainText('Przenoszenie anulowane');

    await page.locator(`.meal-slot[data-date="${week.from}"][data-slot="dinner"] .meal-slot-title`).click();
    expect(state.entries[0].slot).toBe('lunch');
  });

  test('removes a meal from the plan', async ({ page }) => {
    const state: PlanState = { entries: [planned()], nextId: 2 };
    await installMealPlanBackend(page, state);
    await page.goto('/meal-plan');

    await expect(page.locator('.meal-card')).toHaveCount(1);
    await page.locator('.js-unplan-meal').click();

    await expect(page.locator('.meal-card')).toHaveCount(0);
    expect(state.entries).toHaveLength(0);
  });

  test('a plan entry whose recipe went missing keeps its slot and can be removed', async ({ page }) => {
    const state: PlanState = { entries: [planned({ recipeId: 'gone', recipeTitle: null, servings: 2 })], nextId: 2 };
    await installMealPlanBackend(page, state);
    await page.goto('/meal-plan');

    const card = page.locator('.meal-card--missing');
    await expect(card).toHaveCount(1);
    await expect(card).toContainText('Przepis usunięty');

    await card.locator('.js-unplan-meal').click();
    await expect(page.locator('.meal-card')).toHaveCount(0);
  });

  test('navigates between weeks and back to the current one', async ({ page }) => {
    await installMealPlanBackend(page, { entries: [], nextId: 1 });
    await page.goto('/meal-plan');

    const thisWeek = await page.locator('[data-meal-plan-target="windowLabel"]').textContent();

    await page.getByRole('button', { name: 'Następny tydzień →' }).click();
    await expect(page.locator('[data-meal-plan-target="windowLabel"]')).not.toHaveText(thisWeek!);
    await expect(page.locator('.meal-day')).toHaveCount(7);

    await page.getByRole('button', { name: 'Bieżący tydzień' }).click();
    await expect(page.locator('[data-meal-plan-target="windowLabel"]')).toHaveText(thisWeek!);
  });

  test('is reachable from the navigation', async ({ page }) => {
    await installMealPlanBackend(page, { entries: [], nextId: 1 });
    await page.goto('/series');

    await page.locator('nav.navbar a[href="/meal-plan"]').click();
    await expect(page).toHaveURL(/\/meal-plan$/);
    await expect(page.locator('.app-title')).toHaveText('Plan posiłków');
  });
});
