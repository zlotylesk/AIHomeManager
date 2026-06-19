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

test('opening an article detail view shows its fields and closes', async ({ page }) => {
  const title = uniqueTitle('Detail Article');

  await gotoArticles(page);

  await page.fill('#article-title', title);
  await page.fill('#article-url', `https://example.com/detail-${Date.now()}`);
  await page.fill('#article-category', 'Reading');
  await page.fill('#article-read-time', '12');
  await page.click('#form-create-article [type=submit]');

  const row = page.locator('#articles-list .article-row', { hasText: title });
  await expect(row).toBeVisible();

  await row.locator('.btn-view-details').click();

  const modal = page.locator('#article-detail-modal');
  await expect(modal).toBeVisible();
  await expect(modal.locator('#detail-title')).toHaveText(title);
  await expect(modal.locator('.detail-list')).toContainText('Reading');
  await expect(modal.locator('.detail-list')).toContainText('12 min');

  // Esc closes the modal.
  await page.keyboard.press('Escape');
  await expect(modal).toBeHidden();
});

test('editing an article updates its title and category in the list', async ({ page }) => {
  const title = uniqueTitle('Edit Article');

  await gotoArticles(page);

  await page.fill('#article-title', title);
  await page.fill('#article-url', `https://example.com/edit-${Date.now()}`);
  await page.fill('#article-category', 'Draft');
  await page.click('#form-create-article [type=submit]');

  const row = page.locator('#articles-list .article-row', { hasText: title });
  await expect(row).toBeVisible();

  await row.locator('.btn-edit').click();

  const modal = page.locator('#article-edit-modal');
  await expect(modal).toBeVisible();
  await expect(page.locator('#edit-title')).toHaveValue(title);

  const newTitle = `${title} EDITED`;
  await page.fill('#edit-title', newTitle);
  await page.fill('#edit-category', 'Published');
  await page.click('#form-edit-article [type=submit]');

  await expect(page.locator('#info-banner')).toHaveText(/article updated/i);
  const updatedRow = page.locator('#articles-list .article-row', { hasText: newTitle });
  await expect(updatedRow).toBeVisible();
  await expect(updatedRow.locator('.tag')).toHaveText('Published');
});
