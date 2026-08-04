import { test, expect } from '@playwright/test';
import {
  type PlanState,
  installMealPlanBackend,
  currentWeek,
  planned,
} from './support/meal-plan-backend';

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
