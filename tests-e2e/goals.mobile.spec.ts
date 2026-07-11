import { test, expect, type Page } from '@playwright/test';

async function mockReads(page: Page): Promise<void> {
  await page.route('**/api/goals/streaks', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify([{ type: 'book_pages', currentLength: 2, longestLength: 4, lastActivityDate: '2026-07-10' }]),
    }),
  );
  await page.route('**/api/goals', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify([
        { goalId: '11111111-1111-4111-8111-111111111111', type: 'book_pages', period: 'daily', target: 50, achieved: 50, percent: 100, met: true },
      ]),
    }),
  );
}

test('goals view renders cards and streaks on a mobile viewport', async ({ page }) => {
  await mockReads(page);

  await page.goto('/goals');
  await expect(page.locator('.app-title')).toHaveText('Cele i streaki');
  await expect(page.locator('.loading')).toHaveCount(0, { timeout: 10_000 });

  await expect(page.locator('.goal-card')).toHaveCount(1);
  await expect(page.locator('.goal-card--met')).toHaveCount(1);
  await expect(page.locator('.streak-card')).toContainText('2 dni z rzędu');
});

test('creating a goal on mobile shows its progress and streak', async ({ page }) => {
  let goals: Array<Record<string, unknown>> = [];
  let streaks: Array<Record<string, unknown>> = [];
  await page.route('**/api/goals/streaks', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(streaks) }),
  );
  await page.route('**/api/goals', (route) => {
    if ('POST' === route.request().method()) {
      goals = [{ goalId: '11111111-1111-4111-8111-111111111111', type: 'book_pages', period: 'daily', target: 50, achieved: 30, percent: 60, met: false }];
      streaks = [{ type: 'book_pages', currentLength: 2, longestLength: 4, lastActivityDate: '2026-07-10' }];
      return route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ id: '11111111-1111-4111-8111-111111111111' }) });
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(goals) });
  });

  await page.goto('/goals');
  await expect(page.locator('.app-title')).toHaveText('Cele i streaki');
  await expect(page.locator('.loading')).toHaveCount(0, { timeout: 10_000 });

  await page.locator('[data-goals-target="type"]').selectOption('book_pages');
  await page.locator('[data-goals-target="target"]').fill('50');
  await page.locator('[data-goals-target="period"]').selectOption('daily');
  await page.locator('.goal-create-form button[type="submit"]').click();

  await expect(page.locator('.goal-card')).toHaveCount(1);
  await expect(page.locator('.goal-card')).toContainText('30 / 50');
  await expect(page.locator('.streak-card')).toContainText('2 dni z rzędu');
});
