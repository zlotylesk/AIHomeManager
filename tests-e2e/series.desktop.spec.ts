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

test('marking an episode watched updates the season counter (HMAI-188)', async ({ page, request }) => {
  const title = uniqueTitle('E2E Watched');
  const seriesRes = await request.post('/api/series', { data: { title } });
  const { id: seriesId } = await seriesRes.json();
  const seasonRes = await request.post(`/api/series/${seriesId}/seasons`, { data: { number: 1 } });
  const { id: seasonId } = await seasonRes.json();
  const epRes = await request.post(`/api/series/${seriesId}/seasons/${seasonId}/episodes`, { data: { title: 'Pilot', number: 1 } });
  expect(epRes.ok()).toBeTruthy();

  await gotoSeriesList(page);
  await openSeriesDetail(page, title);

  await expect(page.locator('.season-block .season-header small')).toContainText('0/1 watched');
  const checkbox = page.locator('.episodes-table .js-episode-watched');
  await expect(checkbox).not.toBeChecked();

  await checkbox.check();
  await expect(page.locator('.season-block .season-header small')).toContainText('1/1 watched');
  await expect(page.locator('.episodes-table tr.episode-watched')).toHaveCount(1);
});

test('rating an existing episode updates season and series average immediately', async ({ page, request }) => {
  const title = uniqueTitle('E2E RateExisting');
  const seriesRes = await request.post('/api/series', { data: { title } });
  const { id: seriesId } = await seriesRes.json();
  const seasonRes = await request.post(`/api/series/${seriesId}/seasons`, { data: { number: 1 } });
  const { id: seasonId } = await seasonRes.json();
  const epRes = await request.post(`/api/series/${seriesId}/seasons/${seasonId}/episodes`, { data: { title: 'Unrated Pilot', number: 1 } });
  expect(epRes.ok()).toBeTruthy();

  await gotoSeriesList(page);
  await openSeriesDetail(page, title);

  await expect(page.locator('.meta')).toContainText('No ratings yet');
  const rateCell = page.locator('.episodes-table .rating-cell-btn');
  await expect(rateCell).toHaveText('Rate');

  await rateCell.click();
  await page.locator('.rating-editor .rating-btn', { hasText: '7' }).first().click();

  await expect(page.locator('.season-block .season-header small')).toContainText('avg 7');
  await expect(page.locator('.meta strong')).toContainText('★ 7');
  await expect(page.locator('.episodes-table .rating-cell-btn')).toHaveText('★ 7');

  await page.locator('.episodes-table .rating-cell-btn').click();
  await page.locator('.rating-editor .rating-btn', { hasText: '9' }).first().click();

  await expect(page.locator('.season-block .season-header small')).toContainText('avg 9');
  await expect(page.locator('.meta strong')).toContainText('★ 9');
});

test('setting own series and season ratings persists independently of the average', async ({ page, request }) => {
  const title = uniqueTitle('E2E OwnRating');
  const seriesRes = await request.post('/api/series', { data: { title } });
  const { id: seriesId } = await seriesRes.json();
  const seasonRes = await request.post(`/api/series/${seriesId}/seasons`, { data: { number: 1 } });
  expect(seasonRes.ok()).toBeTruthy();

  await gotoSeriesList(page);
  await openSeriesDetail(page, title);

  const seriesOwn = page.locator('#series-own-rating .rating-cell-btn');
  await expect(seriesOwn).toHaveText('Rate');
  await seriesOwn.click();
  await page.locator('#series-own-rating .rating-editor .rating-btn', { hasText: '9' }).first().click();
  await expect(page.locator('#series-own-rating .rating-cell-btn')).toHaveText('★ 9');

  const seasonOwn = page.locator('.season-block [data-season-own-rating] .rating-cell-btn');
  await expect(seasonOwn).toHaveText('Rate');
  await seasonOwn.click();
  await page.locator('[data-season-own-rating] .rating-editor .rating-btn', { hasText: '6' }).first().click();
  await expect(page.locator('.season-block [data-season-own-rating] .rating-cell-btn')).toHaveText('★ 6');

  await page.reload();
  await expect(page.locator('#series-list .loading')).toHaveCount(0, { timeout: 10_000 });
  await openSeriesDetail(page, title);
  await expect(page.locator('#series-own-rating .rating-cell-btn')).toHaveText('★ 9');
  await expect(page.locator('.season-block [data-season-own-rating] .rating-cell-btn')).toHaveText('★ 6');
});

test('deleting an episode removes it from the season table (HMAI-185)', async ({ page, request }) => {
  const title = uniqueTitle('E2E Delete');
  const seriesRes = await request.post('/api/series', { data: { title } });
  const { id: seriesId } = await seriesRes.json();
  const seasonRes = await request.post(`/api/series/${seriesId}/seasons`, { data: { number: 1 } });
  const { id: seasonId } = await seasonRes.json();
  const epRes = await request.post(`/api/series/${seriesId}/seasons/${seasonId}/episodes`, { data: { title: 'Doomed Pilot', number: 1 } });
  expect(epRes.ok()).toBeTruthy();

  page.on('dialog', (dialog) => dialog.accept());

  await gotoSeriesList(page);
  await openSeriesDetail(page, title);

  await expect(page.locator('.episodes-table tbody tr')).toHaveCount(1);
  await expect(page.locator('.episodes-table td', { hasText: 'Doomed Pilot' })).toBeVisible();

  await page.locator('.episodes-table .js-delete-episode').click();

  await expect(page.locator('.episodes-table tbody tr')).toHaveCount(0);
});

test('editing the series title inline persists it (HMAI-186)', async ({ page, request }) => {
  const title = uniqueTitle('E2E EditTitle');
  const seriesRes = await request.post('/api/series', { data: { title } });
  expect(seriesRes.ok()).toBeTruthy();

  await gotoSeriesList(page);
  await openSeriesDetail(page, title);

  const newTitle = `${title} Edited`;
  await page.locator('#series-title-edit .inline-editable-value').click();
  const input = page.locator('#series-title-edit .inline-editable-input');
  await input.fill(newTitle);
  await input.press('Enter');

  await expect(page.locator('#series-title-edit .inline-editable-value')).toHaveText(newTitle);

  await page.reload();
  await expect(page.locator('#series-list .loading')).toHaveCount(0, { timeout: 10_000 });
  await openSeriesDetail(page, newTitle);
  await expect(page.locator('#series-title-edit .inline-editable-value')).toHaveText(newTitle);
});

test('list search filters and sort reorders the visible cards (HMAI-189)', async ({ page, request }) => {
  const stamp = `${Date.now()}${Math.random().toString(36).slice(2, 6)}`;
  const alpha = `AAA ${stamp}`;
  const zeta = `ZZZ ${stamp}`;
  expect((await request.post('/api/series', { data: { title: alpha } })).ok()).toBeTruthy();
  await new Promise((resolve) => setTimeout(resolve, 1100));
  expect((await request.post('/api/series', { data: { title: zeta } })).ok()).toBeTruthy();

  await gotoSeriesList(page);

  await page.locator('#series-search').fill(stamp);
  await expect(page.locator('.series-card')).toHaveCount(2);

  await expect(page.locator('.series-card').first().locator('h3')).toHaveText(alpha);

  await page.locator('#series-search').fill(`AAA ${stamp}`);
  await expect(page.locator('.series-card')).toHaveCount(1);
  await expect(page.locator('.series-card h3')).toHaveText(alpha);

  await page.locator('#series-search').fill(stamp);
  await page.locator('#series-sort').selectOption('created-desc');
  await expect(page.locator('.series-card').first().locator('h3')).toHaveText(zeta);
});

test('Import from Trakt button kicks off the async import and shows a started banner (HMAI-184)', async ({ page }) => {
  await gotoSeriesList(page);

  let importCalled = false;
  await page.route('**/api/series/import/trakt', async (route) => {
    importCalled = true;
    await route.fulfill({
      status: 202,
      contentType: 'application/json',
      body: JSON.stringify({ status: 'import_started' }),
    });
  });

  const button = page.locator('#btn-import-trakt');
  await expect(button).toBeVisible();
  await button.click();

  const banner = page.locator('#info-banner');
  await expect(banner).toBeVisible();
  await expect(banner).toHaveText(/import started/i);
  expect(importCalled).toBeTruthy();
});

test('Import from Trakt prompts to connect when no token is stored (HMAI-184)', async ({ page }) => {
  await gotoSeriesList(page);

  await page.route('**/api/series/import/trakt', async (route) => {
    await route.fulfill({
      status: 409,
      contentType: 'application/json',
      body: JSON.stringify({ error: 'Trakt is not connected.', authUrl: '/auth/trakt' }),
    });
  });

  await page.locator('#btn-import-trakt').click();

  const banner = page.locator('#error-banner');
  await expect(banner).toBeVisible();
  await expect(banner.locator('a[href="/auth/trakt"]')).toBeVisible();
});

test('API 422 surfaces an error message visible to the user', async ({ page }) => {
  await gotoSeriesList(page);

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

test('cards flag rating mismatch (red) and incomplete watched state (amber) — incomplete wins (HMAI-221)', async ({ page }) => {
  const season = (over: Record<string, unknown>) => ({
    id: 's', number: 1, rating: null, averageRating: null,
    watchedCount: 0, episodeCount: 0, episodes: [], ...over,
  });
  const series = (title: string, over: Record<string, unknown>) => ({
    id: title, title, coverUrl: null, year: null, status: null,
    createdAt: '2026-01-01T00:00:00+00:00', description: null,
    rating: null, averageRating: null, watchedCount: 0, episodeCount: 0,
    seasons: [], ...over,
  });

  const payload = [
    series('Divergent Show', {
      rating: 5, averageRating: 8, watchedCount: 10, episodeCount: 10,
      seasons: [season({ rating: 5, averageRating: 8, watchedCount: 10, episodeCount: 10 })],
    }),
    series('Partial Show', {
      rating: 5, averageRating: 8, watchedCount: 3, episodeCount: 10,
      seasons: [season({ rating: 5, averageRating: 8, watchedCount: 3, episodeCount: 10 })],
    }),
    series('Aligned Show', {
      rating: 7, averageRating: 7, watchedCount: 10, episodeCount: 10,
      seasons: [season({ rating: 7, averageRating: 7, watchedCount: 10, episodeCount: 10 })],
    }),
  ];

  await page.route('**/api/series', async (route) => {
    if (route.request().method() === 'GET') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(payload) });
      return;
    }
    await route.fallback();
  });

  await gotoSeriesList(page);

  await expect(page.locator('.series-card', { hasText: 'Divergent Show' })).toHaveClass(/is-rating-mismatch/);

  const incomplete = page.locator('.series-card', { hasText: 'Partial Show' });
  await expect(incomplete).toHaveClass(/is-rating-incomplete/);
  await expect(incomplete).not.toHaveClass(/is-rating-mismatch/);

  await expect(page.locator('.series-card', { hasText: 'Aligned Show' })).not.toHaveClass(/is-rating-(incomplete|mismatch)/);
});
