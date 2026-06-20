import { test, expect } from '@playwright/test';

const uniqueTitle = (prefix: string) => `${prefix} ${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;

test('articles page renders without horizontal overflow at 393px (Pixel 5)', async ({ page, request }) => {
  // A long title plus an unread article (all four action buttons visible) is the worst case for the row width.
  const title = uniqueTitle('E2E Mobile Article with a deliberately long title that should wrap rather than push the row sideways');
  const seed = await request.post('/api/articles', {
    data: { title, url: `https://example.com/mobile-${Date.now()}`, category: 'MobileTest' },
  });
  expect(seed.ok(), `article seed failed: ${seed.status()}`).toBeTruthy();

  await page.goto('/articles');
  await expect(page.locator('.app-title')).toHaveText('Articles');
  await expect(page.locator('#articles-list .loading')).toHaveCount(0, { timeout: 10_000 });
  await expect(page.locator('#articles-list .article-row', { hasText: title })).toBeVisible();

  const layout = await page.evaluate(() => {
    const clientWidth = document.documentElement.clientWidth;
    let worst = { sel: '', right: 0 };
    for (const el of document.querySelectorAll<HTMLElement>('body *')) {
      const r = el.getBoundingClientRect();
      if (r.width === 0 || r.height === 0) continue;
      if (r.right > worst.right) {
        worst = { sel: el.tagName.toLowerCase() + (el.className ? '.' + String(el.className).trim().split(/\s+/).join('.') : ''), right: r.right };
      }
    }
    return {
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth,
      worstSel: worst.sel,
      worstRight: Math.round(worst.right * 100) / 100,
    };
  });

  expect(layout.scrollWidth, 'document must not scroll horizontally').toBeLessThanOrEqual(layout.clientWidth);
  expect(layout.worstRight, `"${layout.worstSel}" extends past the ${layout.clientWidth}px viewport`).toBeLessThanOrEqual(layout.clientWidth + 2);
});
