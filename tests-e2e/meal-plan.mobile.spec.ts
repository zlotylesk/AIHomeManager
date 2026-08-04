import { test, expect } from '@playwright/test';
import {
  type PlanState,
  installMealPlanBackend,
  currentWeek,
  planned,
} from './support/meal-plan-backend';

/**
 * Seven days by four slots is the widest layout the app draws, and it was the
 * only page with no mobile spec at all — so the `.meal-plan-calendar` rule that
 * collapses it to one column under 600 px was carried by nothing.
 *
 * The stubbed API is shared with the desktop spec (see `support/`), because
 * what makes a meal-plan payload realistic — every day, every slot, empty ones
 * included — is a contract decision, and a second hand-written copy of it would
 * eventually describe an API the server does not have.
 */
test.describe('Meal plan — mobile', () => {
  test('an empty week still renders every day and every slot', async ({ page }) => {
    await installMealPlanBackend(page, { entries: [], nextId: 1 });
    await page.goto('/meal-plan');

    await expect(page.locator('.meal-day')).toHaveCount(7);
    await expect(page.locator('.meal-day').first().locator('.meal-slot')).toHaveCount(4);
    await expect(page.locator('.meal-slot')).toHaveCount(28);
  });

  /**
   * The rule the ticket says nothing confirms, asserted directly — and it needs
   * its own test because "does not overflow" cannot stand in for it. The base
   * grid is `repeat(auto-fit, minmax(170px, 1fr))`, so on a 393 px phone it
   * would fall back to *two* columns, which fits the viewport perfectly well:
   * drop the media query and the no-overflow test below still passes while the
   * calendar has silently become unreadable on a phone.
   */
  test('the calendar collapses to a single column', async ({ page }) => {
    await installMealPlanBackend(page, { entries: [], nextId: 1 });
    await page.goto('/meal-plan');
    await expect(page.locator('.meal-day')).toHaveCount(7);

    const boxes = await page.locator('.meal-day').evaluateAll((days) =>
      days.map((day) => {
        const rect = day.getBoundingClientRect();

        return { left: Math.round(rect.left), top: Math.round(rect.top) };
      }));

    expect(new Set(boxes.map((b) => b.left)).size, 'every day must start at the same left edge').toBe(1);
    expect(new Set(boxes.map((b) => b.top)).size, 'the seven days must be stacked, not side by side').toBe(7);
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

    // 500 g for 4 portions cooked for 6 → 750 g; 3 eggs → 4.5, bought as 5,
    // because an ingredient you cannot halve rounds up (see the desktop spec).
    await expect(page.locator('.shopping-item').nth(0)).toHaveText('750 g Mąka');
    await expect(page.locator('.shopping-item').nth(1)).toHaveText('5 szt. Jajko');
  });

  /**
   * On a phone the two sections cannot sit beside each other, so the shopping
   * list has to come after the whole calendar rather than being pushed off to
   * one side or interleaved with it.
   */
  test('the shopping list sits below the calendar, not beside it', async ({ page }) => {
    await installMealPlanBackend(page, { entries: [planned()], nextId: 2 });
    await page.goto('/meal-plan');
    await expect(page.locator('.shopping-item')).toHaveCount(2);

    const calendar = await page.locator('[data-meal-plan-target="calendar"]').boundingBox();
    const shoppingList = await page.locator('.shopping-list-panel').boundingBox();

    expect(shoppingList!.y).toBeGreaterThanOrEqual(calendar!.y + calendar!.height);
  });

  test('does not overflow horizontally', async ({ page }) => {
    await installMealPlanBackend(page, { entries: [planned()], nextId: 2 });
    await page.goto('/meal-plan');
    await expect(page.locator('.meal-card')).toHaveCount(1);

    const overflows = await page.evaluate(
      () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    );
    expect(overflows).toBe(false);
  });

  test('is reachable from the navigation', async ({ page }) => {
    await installMealPlanBackend(page, { entries: [], nextId: 1 });
    await page.goto('/series');

    await page.locator('nav.navbar a[href="/meal-plan"]').click();
    await expect(page).toHaveURL(/\/meal-plan$/);
    await expect(page.locator('.app-title')).toHaveText('Plan posiłków');
  });
});
