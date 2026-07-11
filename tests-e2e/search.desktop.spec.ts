import { test, expect, type Page } from '@playwright/test';

type SearchResult = { type: string; id: string; title: string; snippet: string; url: string };

const RESULTS: SearchResult[] = [
  { type: 'book', id: 'b1', title: 'Dune', snippet: 'Frank Herbert', url: '/books' },
  { type: 'book', id: 'b2', title: 'Space Odyssey', snippet: 'a voyage', url: '/books' },
  { type: 'series', id: 's1', title: 'Deep Space Nine', snippet: 'station', url: '/series' },
];

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

test('typing shows grouped, ranked search results from the API', async ({ page }) => {
  await stubPage(page, RESULTS);
  await page.goto('/goals');

  await page.locator('[data-search-target="input"]').fill('space');

  await expect(page.locator('.search-result')).toHaveCount(3);
  await expect(page.locator('.search-group')).toHaveCount(2);
  await expect(page.locator('.search-group-label').first()).toHaveText('Książka');
  await expect(page.locator('.search-result').first()).toContainText('Dune');
  await expect(page.locator('.search-result').first()).toHaveAttribute('href', '/books');
});

test('shows an empty state when nothing matches', async ({ page }) => {
  await stubPage(page, []);
  await page.goto('/goals');

  await page.locator('[data-search-target="input"]').fill('zzzzz');

  await expect(page.locator('.search-empty')).toBeVisible();
  await expect(page.locator('.search-result')).toHaveCount(0);
});

test('does not query the API for a single character', async ({ page }) => {
  let called = false;
  await page.route('**/api/goals/streaks', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: '[]' }));
  await page.route('**/api/goals', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: '[]' }));
  await page.route('**/api/search*', (route) => {
    called = true;
    return route.fulfill({ status: 200, contentType: 'application/json', body: '[]' });
  });

  await page.goto('/goals');
  await page.locator('[data-search-target="input"]').fill('a');
  await page.waitForTimeout(500);

  expect(called).toBe(false);
  await expect(page.locator('.navbar-search-results')).toBeHidden();
});
