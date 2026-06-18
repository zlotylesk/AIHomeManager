import { test, expect } from '@playwright/test';


test('youtube-progress page renders without horizontal overflow at 393px (Pixel 5)', async ({ page }) => {
  const longTitle = 'A deliberately long video title that should wrap rather than push the layout sideways on a narrow phone screen';
  const splitVideo = {
    youtubeId: 'vidMOBILE001',
    title: longTitle,
    channel: 'A Channel With A Fairly Long Name Too',
    durationSeconds: 900,
    status: 'split-pool' as const,
    startedAt: null,
    watchedAt: null,
  };

  await page.route('**/api/youtube-progress/watchlist', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ videos: [splitVideo] }) }),
  );
  await page.route('**/api/youtube-progress/sessions', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        sessions: [
          {
            id: '22222222-2222-4222-8222-222222222222',
            createdAt: '2026-06-09T12:00:00+00:00',
            totalDurationSeconds: 900,
            youtubePlaylistId: null,
            videos: [splitVideo],
          },
        ],
      }),
    }),
  );

  await page.goto('/youtube-progress');
  await expect(page.locator('.app-title')).toHaveText('YouTube Progress');
  await expect(page.locator('.loading')).toHaveCount(0, { timeout: 10_000 });
  await expect(page.locator('.youtube-progress-session-card')).toHaveCount(1);

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
