import { test, expect, type Page } from '@playwright/test';

type SearchResult = { type: string; id: string; title: string; snippet: string; url: string };

async function stubPage(page: Page, results: SearchResult[]): Promise<void> {
  await page.route('**/api/goals/streaks', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: '[]' }),
  );
  await page.route('**/api/goals', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: '[]' }),
  );
  await page.route('**/api/search*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(results) }),
  );
}

test('global search works on a mobile viewport', async ({ page }) => {
  await stubPage(page, [
    { type: 'book', id: 'b1', title: 'Dune', snippet: 'Frank Herbert', url: '/books' },
    { type: 'series', id: 's1', title: 'Severance', snippet: 'office', url: '/series' },
  ]);
  await page.goto('/goals');

  await page.locator('[data-search-target="input"]').fill('dune');

  await expect(page.locator('.search-result')).toHaveCount(2);
  await expect(page.locator('.search-group')).toHaveCount(2);
  await expect(page.locator('.search-result').first()).toContainText('Dune');
});

test('clicking a result navigates to the source entity on mobile', async ({ page }) => {
  await stubPage(page, [{ type: 'book', id: 'b1', title: 'Dune', snippet: 'Frank Herbert', url: '/books' }]);
  await page.route('**/api/books**', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: '[]' }),
  );
  await page.goto('/goals');

  await page.locator('[data-search-target="input"]').fill('dune');
  await page.locator('.search-result').first().click();

  await expect(page).toHaveURL(/\/books$/);
});

test('shows an empty state on mobile when nothing matches', async ({ page }) => {
  await stubPage(page, []);
  await page.goto('/goals');

  await page.locator('[data-search-target="input"]').fill('zzzzz');

  await expect(page.locator('.search-empty')).toBeVisible();
});
