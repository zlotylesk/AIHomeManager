import { test, expect } from '@playwright/test';

const uniqueTitle = (prefix: string) => `${prefix} ${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;

test('series page renders without horizontal overflow at 375px', async ({ page, request }) => {
  const title = uniqueTitle('E2E Mobile');
  await request.post('/api/series', { data: { title } });

  await page.goto('/series');
  await expect(page.locator('.app-title')).toHaveText('Series');
  await expect(page.locator('.series-card', { hasText: title })).toBeVisible();

  const layout = await page.evaluate(() => {
    const clientWidth = document.documentElement.clientWidth;
    // Element-level check: find the element whose right edge sticks out furthest.
    // `overflow-x: clip` keeps the document from scrolling, but layout geometry
    // is preserved — a genuinely-wide element still reports its real rect, so
    // this stays a meaningful regression check, not a no-op.
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
  // 2px tolerance absorbs sub-pixel rounding of fractional `1fr` grid tracks
  // (Linux Chromium on Pixel 5 reports ~1.7px past viewport for a 2-column
  // `.series-grid`); a real breakout lands well past this and names the culprit.
  expect(layout.worstRight, `"${layout.worstSel}" extends past the ${layout.clientWidth}px viewport`).toBeLessThanOrEqual(layout.clientWidth + 2);
});
