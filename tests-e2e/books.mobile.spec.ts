import { test, expect } from '@playwright/test';

const uniqueIsbn = () => {
  const suffix = `${Date.now()}${Math.floor(Math.random() * 1000)}`.padStart(13, '0').slice(-13);
  return suffix;
};
const uniqueTitle = (prefix: string) => `${prefix} ${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;

test('books page renders without horizontal overflow at 393px (Pixel 5)', async ({ page, request }) => {
  const title = uniqueTitle('E2E Mobile Book');
  const seed = await request.post('/api/books', {
    data: {
      isbn: uniqueIsbn(),
      title,
      author: 'Mobile Author',
      publisher: 'Mobile Publisher',
      year: 2024,
      total_pages: 200,
    },
  });
  expect(seed.ok(), `seed failed: ${seed.status()}`).toBeTruthy();

  await page.goto('/books');
  await expect(page.locator('.app-title')).toHaveText('Books');
  await expect(page.locator('.book-card', { hasText: title })).toBeVisible();

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
  // — same tolerance the series mobile spec uses; real breakouts land well past.
  expect(layout.worstRight, `"${layout.worstSel}" extends past the ${layout.clientWidth}px viewport`).toBeLessThanOrEqual(layout.clientWidth + 2);
});
