import { test, expect, type Page } from '@playwright/test';

const uniqueTitle = (prefix: string) => `${prefix} ${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;

async function gotoSeriesList(page: Page): Promise<void> {
  await page.goto('/series');
  await expect(page.locator('.app-title')).toHaveText('Series');
  await expect(page.locator('#series-list .loading')).toHaveCount(0, { timeout: 10_000 });
}

async function openSeriesDetail(page: Page, title: string): Promise<void> {
  const card = page.locator('.series-card', { hasText: title });
  await expect(card).toBeVisible();
  await card.click();
  await expect(page.locator('#series-detail-view')).toBeVisible();
  await expect(page.locator('#series-detail-content h2')).toHaveText(title);
}

test('list loads and renders titles for created series', async ({ page, request }) => {
  const title = uniqueTitle('E2E List');
  const create = await request.post('/api/series', { data: { title } });
  expect(create.ok()).toBeTruthy();

  await gotoSeriesList(page);
  await expect(page.locator('.series-card', { hasText: title })).toBeVisible();
});

test('create form adds a new series without a full page reload', async ({ page }) => {
  await gotoSeriesList(page);

  // Stamp window with a random sentinel; a full page reload would discard it.
  const sentinel = Math.random().toString(36).slice(2);
  await page.evaluate((s) => { (window as unknown as Record<string, string>).__e2eSentinel = s; }, sentinel);

  const title = uniqueTitle('E2E Create');
  await page.locator('#btn-add-series').click();
  await page.locator('#input-series-title').fill(title);
  await page.locator('#form-add-series button[type=submit]').click();

  await expect(page.locator('.series-card', { hasText: title })).toBeVisible();
  await expect(page.locator('#modal-add-series')).toHaveClass(/hidden/);

  const survivedSentinel = await page.evaluate(() => (window as unknown as Record<string, string>).__e2eSentinel);
  expect(survivedSentinel, 'sentinel must survive — full page reload would clear it').toBe(sentinel);
});

test('adding a rated episode updates season and series average immediately', async ({ page, request }) => {
  const title = uniqueTitle('E2E Rating');
  const seriesRes = await request.post('/api/series', { data: { title } });
  const { id: seriesId } = await seriesRes.json();
  const seasonRes = await request.post(`/api/series/${seriesId}/seasons`, { data: { number: 1 } });
  expect(seasonRes.ok()).toBeTruthy();

  await gotoSeriesList(page);
  await openSeriesDetail(page, title);

  await expect(page.locator('.meta')).toContainText('No ratings yet');

  await page.locator('.js-add-episode').click();
  await page.locator('.add-episode-form input[type=text]').fill('Pilot');
  await page.locator('.rating-btn', { hasText: '8' }).first().click();
  await page.locator('.add-episode-form button[type=submit]').click();

  await expect(page.locator('.season-block .season-header small')).toContainText('avg 8');
  await expect(page.locator('.meta')).toContainText('Average rating');
  await expect(page.locator('.meta strong')).toContainText('★ 8');
});

test('API 422 surfaces an error message visible to the user', async ({ page }) => {
  await gotoSeriesList(page);

  // Stub the POST so the JS hits the actual error branch.
  // The JS short-circuits empty/whitespace titles before sending, so we need a
  // real-looking title that the (mocked) server rejects.
  await page.route('**/api/series', async (route) => {
    if (route.request().method() === 'POST') {
      await route.fulfill({
        status: 422,
        contentType: 'application/json',
        body: JSON.stringify({ error: 'Title is too long.' }),
      });
      return;
    }
    await route.fallback();
  });

  await page.locator('#btn-add-series').click();
  await page.locator('#input-series-title').fill('My Show');
  await page.locator('#form-add-series button[type=submit]').click();

  const banner = page.locator('#error-banner');
  await expect(banner).toBeVisible();
  await expect(banner).toHaveText(/title is too long/i);
});
