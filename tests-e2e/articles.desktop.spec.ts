import { test, expect, type Page } from '@playwright/test';


const uniqueTitle = (prefix: string) => `${prefix} ${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;

async function gotoArticles(page: Page): Promise<void> {
  await page.goto('/articles');
  await expect(page.locator('.app-title')).toHaveText('Articles');
  // The list spinner is replaced once the fetch resolves.
  await expect(page.locator('#articles-list .loading')).toHaveCount(0, { timeout: 10_000 });
}

test('an article created through the New Article form appears in the list', async ({ page }) => {
  const title = uniqueTitle('E2E Article');

  await gotoArticles(page);

  await page.fill('#article-title', title);
  await page.fill('#article-url', `https://example.com/${Date.now()}`);
  await page.fill('#article-category', 'Tech');
  await page.fill('#article-read-time', '7');
  await page.click('#form-create-article [type=submit]');

  await expect(page.locator('#info-banner')).toHaveText(/article added/i);
  const row = page.locator('#articles-list .article-row', { hasText: title });
  await expect(row).toBeVisible();
  await expect(row.locator('.tag')).toHaveText('Tech');
});
