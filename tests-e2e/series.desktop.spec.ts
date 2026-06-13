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

  // Episode starts unwatched: counter reads 0/1, checkbox unchecked.
  await expect(page.locator('.season-block .season-header small')).toContainText('0/1 watched');
  const checkbox = page.locator('.episodes-table .js-episode-watched');
  await expect(checkbox).not.toBeChecked();

  // Toggle watched — the row gets the watched class and the counter follows.
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

  // Existing episode starts unrated — the cell offers a "Rate" affordance.
  await expect(page.locator('.meta')).toContainText('No ratings yet');
  const rateCell = page.locator('.episodes-table .rating-cell-btn');
  await expect(rateCell).toHaveText('Rate');

  // Open the inline selector and pick 7.
  await rateCell.click();
  await page.locator('.rating-editor .rating-btn', { hasText: '7' }).first().click();

  await expect(page.locator('.season-block .season-header small')).toContainText('avg 7');
  await expect(page.locator('.meta strong')).toContainText('★ 7');
  await expect(page.locator('.episodes-table .rating-cell-btn')).toHaveText('★ 7');

  // Re-rate the same episode to 9 — averages must follow without re-adding it.
  await page.locator('.episodes-table .rating-cell-btn').click();
  await page.locator('.rating-editor .rating-btn', { hasText: '9' }).first().click();

  await expect(page.locator('.season-block .season-header small')).toContainText('avg 9');
  await expect(page.locator('.meta strong')).toContainText('★ 9');
});

test('setting own series and season ratings persists independently of the average', async ({ page, request }) => {
  // HMAI-179: the manual "My rating" controls are separate from the
  // episode-derived average — here there are no episodes, so only the manual
  // scores change.
  const title = uniqueTitle('E2E OwnRating');
  const seriesRes = await request.post('/api/series', { data: { title } });
  const { id: seriesId } = await seriesRes.json();
  const seasonRes = await request.post(`/api/series/${seriesId}/seasons`, { data: { number: 1 } });
  expect(seasonRes.ok()).toBeTruthy();

  await gotoSeriesList(page);
  await openSeriesDetail(page, title);

  // Series' own rating starts unset.
  const seriesOwn = page.locator('#series-own-rating .rating-cell-btn');
  await expect(seriesOwn).toHaveText('Rate');
  await seriesOwn.click();
  await page.locator('#series-own-rating .rating-editor .rating-btn', { hasText: '9' }).first().click();
  await expect(page.locator('#series-own-rating .rating-cell-btn')).toHaveText('★ 9');

  // Season's own rating, likewise independent of any episode average.
  const seasonOwn = page.locator('.season-block [data-season-own-rating] .rating-cell-btn');
  await expect(seasonOwn).toHaveText('Rate');
  await seasonOwn.click();
  await page.locator('[data-season-own-rating] .rating-editor .rating-btn', { hasText: '6' }).first().click();
  await expect(page.locator('.season-block [data-season-own-rating] .rating-cell-btn')).toHaveText('★ 6');

  // Reload from the API — both manual scores must have persisted.
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

  // The delete button raises a confirm() dialog — auto-accept it.
  page.on('dialog', (dialog) => dialog.accept());

  await gotoSeriesList(page);
  await openSeriesDetail(page, title);

  await expect(page.locator('.episodes-table tbody tr')).toHaveCount(1);
  await expect(page.locator('.episodes-table td', { hasText: 'Doomed Pilot' })).toBeVisible();

  await page.locator('.episodes-table .js-delete-episode').click();

  // After the model re-renders the row is gone.
  await expect(page.locator('.episodes-table tbody tr')).toHaveCount(0);
});

test('editing the series title inline persists it (HMAI-186)', async ({ page, request }) => {
  const title = uniqueTitle('E2E EditTitle');
  const seriesRes = await request.post('/api/series', { data: { title } });
  expect(seriesRes.ok()).toBeTruthy();

  await gotoSeriesList(page);
  await openSeriesDetail(page, title);

  // Click the inline title, change it, save with Enter.
  const newTitle = `${title} Edited`;
  await page.locator('#series-title-edit .inline-editable-value').click();
  const input = page.locator('#series-title-edit .inline-editable-input');
  await input.fill(newTitle);
  await input.press('Enter');

  // Display reflects the new title in place…
  await expect(page.locator('#series-title-edit .inline-editable-value')).toHaveText(newTitle);

  // …and it persisted — reload from the API and reopen by the new title.
  await page.reload();
  await expect(page.locator('#series-list .loading')).toHaveCount(0, { timeout: 10_000 });
  await openSeriesDetail(page, newTitle);
  await expect(page.locator('#series-title-edit .inline-editable-value')).toHaveText(newTitle);
});

test('list search filters and sort reorders the visible cards (HMAI-189)', async ({ page, request }) => {
  // Two series sharing a unique stamp (to isolate them from other rows in the
  // shared dev DB), with titles that sort differently from their creation order.
  const stamp = `${Date.now()}${Math.random().toString(36).slice(2, 6)}`;
  const alpha = `AAA ${stamp}`;
  const zeta = `ZZZ ${stamp}`;
  // alpha is created first → zeta is the more recent of the two.
  expect((await request.post('/api/series', { data: { title: alpha } })).ok()).toBeTruthy();
  // created_at is stored at second resolution, so two back-to-back inserts share
  // a timestamp and "Recently added" can't separate them — the tie sorts
  // arbitrarily (flaky in CI). Wait past a full second so zeta is strictly newer.
  await new Promise((resolve) => setTimeout(resolve, 1100));
  expect((await request.post('/api/series', { data: { title: zeta } })).ok()).toBeTruthy();

  await gotoSeriesList(page);

  // Filtering by the shared stamp isolates exactly our two cards.
  await page.locator('#series-search').fill(stamp);
  await expect(page.locator('.series-card')).toHaveCount(2);

  // Default sort is Title A–Z → AAA precedes ZZZ.
  await expect(page.locator('.series-card').first().locator('h3')).toHaveText(alpha);

  // A narrower term hides the non-matching card entirely.
  await page.locator('#series-search').fill(`AAA ${stamp}`);
  await expect(page.locator('.series-card')).toHaveCount(1);
  await expect(page.locator('.series-card h3')).toHaveText(alpha);

  // Back to both, then sort by "Recently added" → ZZZ (newer) jumps to first.
  await page.locator('#series-search').fill(stamp);
  await page.locator('#series-sort').selectOption('created-desc');
  await expect(page.locator('.series-card').first().locator('h3')).toHaveText(zeta);
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
