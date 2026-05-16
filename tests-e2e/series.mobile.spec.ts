import { test, expect } from '@playwright/test';

const uniqueTitle = (prefix: string) => `${prefix} ${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;

test('series page renders without horizontal overflow at 375px', async ({ page, request }) => {
  const title = uniqueTitle('E2E Mobile');
  await request.post('/api/series', { data: { title } });

  await page.goto('/series');
  await expect(page.locator('.app-title')).toHaveText('Series');
  await expect(page.locator('.series-card', { hasText: title })).toBeVisible();

  const dimensions = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
  }));
  expect(dimensions.scrollWidth, 'document must not be wider than the viewport').toBeLessThanOrEqual(dimensions.clientWidth);
});
