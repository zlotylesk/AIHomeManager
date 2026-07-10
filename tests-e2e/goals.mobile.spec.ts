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
