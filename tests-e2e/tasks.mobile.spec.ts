import { test, expect } from '@playwright/test';

const uniqueTitle = (prefix: string) => `${prefix} ${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;

test('tasks page renders without horizontal overflow at 393px (Pixel 5)', async ({ page, request }) => {
  const title = uniqueTitle('E2E Mobile Task with a deliberately long title that should wrap rather than push the table sideways');
  const seed = await request.post('/api/tasks', {
    data: { title, start: '2026-06-01T09:00:00+02:00', end: '2026-06-01T10:30:00+02:00' },
  });
  expect(seed.ok(), `task seed failed: ${seed.status()}`).toBeTruthy();

  await page.goto('/tasks');
  await expect(page.locator('.app-title')).toHaveText('Tasks');
  await expect(page.locator('#tasks-loading')).toBeHidden({ timeout: 10_000 });
  await expect(page.locator('#tasks-table tbody tr', { hasText: title })).toBeVisible();

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
