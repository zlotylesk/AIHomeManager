import { test, expect } from '@playwright/test';

test('listening history loads and renders a manually-logged session', async ({ page, request }) => {
  const title = `E2E Track ${Date.now()}`;
  const artist = `E2E Artist ${Math.random().toString(36).slice(2, 7)}`;

  const seed = await request.post('/api/music/sessions', {
    data: { artist, title, playedAt: new Date().toISOString() },
  });
  expect(seed.ok(), `session seed failed: ${seed.status()}`).toBeTruthy();

  await page.goto('/music');
  await expect(page.locator('.app-title')).toHaveText('Music');

  // history loads independently of the (slow/external) top-albums section
  await expect(page.locator('#history-list .loading')).toHaveCount(0, { timeout: 10_000 });

  const row = page.locator('.history-row', { hasText: title });
  await expect(row).toBeVisible();
  await expect(row).toContainText(artist);
  await expect(row).toContainText('Manual');
});

test('manually logging a play adds it to the listening history', async ({ page }) => {
  await page.goto('/music');
  await expect(page.locator('#history-list .loading')).toHaveCount(0, { timeout: 10_000 });

  const title = `E2E Logged Track ${Date.now()}`;
  const now = new Date();
  const localNow = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16);

  await page.locator('#log-artist').fill('E2E Log Artist');
  await page.locator('#log-title').fill(title);
  await page.locator('#log-played-at').fill(localNow);
  await page.locator('#log-session-form button[type=submit]').click();

  const row = page.locator('.history-row', { hasText: title });
  await expect(row).toBeVisible();
  await expect(row).toContainText('Manual');
});

test('source filter narrows the listening history to manual entries', async ({ page, request }) => {
  const title = `E2E Filter Track ${Date.now()}`;
  const seed = await request.post('/api/music/sessions', {
    data: { artist: 'E2E Filter Artist', title, playedAt: new Date().toISOString() },
  });
  expect(seed.ok(), `session seed failed: ${seed.status()}`).toBeTruthy();

  await page.goto('/music');
  await expect(page.locator('#history-list .loading')).toHaveCount(0, { timeout: 10_000 });

  await page.locator('#history-source').selectOption('manual');
  await page.locator('#btn-load-history').click();

  await expect(page.locator('.history-row', { hasText: title })).toBeVisible();
  // every rendered row in manual mode must be a manual entry
  const sources = await page.locator('.history-row .history-source').allTextContents();
  for (const s of sources) {
    expect(s.trim()).toBe('Manual');
  }
});
