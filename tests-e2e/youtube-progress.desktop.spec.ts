import { test, expect, type Page } from '@playwright/test';

// The YouTubeProgress panel has no "create video" endpoint — videos only enter
// the local pool through `POST /sync`, which calls the real YouTube API behind
// OAuth. So unlike Books/Series (which seed via their POST endpoints), these
// specs drive the rendered Stimulus UI by route-mocking the two read endpoints
// the controller hits on connect(). Same `page.route` pattern the Books error
// spec uses — it keeps the test hermetic and independent of YouTube/DB state.

type VideoFixture = {
  youtubeId: string;
  title: string;
  channel: string;
  durationSeconds: number;
  status: 'split-pool' | 'started' | 'watched';
  startedAt: string | null;
  watchedAt: string | null;
};

const video = (overrides: Partial<VideoFixture> = {}): VideoFixture => ({
  youtubeId: 'vid00000001',
  title: 'Deterministic session splitting in PHP',
  channel: 'AIHM Channel',
  durationSeconds: 600,
  status: 'split-pool',
  startedAt: null,
  watchedAt: null,
  ...overrides,
});

async function mockReads(
  page: Page,
  data: { videos?: VideoFixture[]; sessions?: Array<Record<string, unknown>> },
): Promise<void> {
  await page.route('**/api/youtube-progress/watchlist', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ videos: data.videos ?? [] }) }),
  );
  await page.route('**/api/youtube-progress/sessions', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ sessions: data.sessions ?? [] }) }),
  );
}

async function gotoPanel(page: Page): Promise<void> {
  await page.goto('/youtube-progress');
  await expect(page.locator('.app-title')).toHaveText('YouTube Progress');
  // connect() kicks off both reads; wait until neither section shows the spinner.
  await expect(page.locator('.loading')).toHaveCount(0, { timeout: 10_000 });
}

test('panel renders watchlist rows and a session card from synced data', async ({ page }) => {
  const split = video({ youtubeId: 'vidSPLIT001', title: 'Short intro clip', durationSeconds: 480, status: 'split-pool' });
  const watched = video({ youtubeId: 'vidWATCH001', title: 'Already seen talk', durationSeconds: 1200, status: 'watched', watchedAt: '2026-06-08T10:00:00+00:00' });
  await mockReads(page, {
    videos: [split, watched],
    sessions: [
      {
        id: '11111111-1111-4111-8111-111111111111',
        createdAt: '2026-06-09T12:00:00+00:00',
        totalDurationSeconds: 480,
        youtubePlaylistId: null,
        videos: [split],
      },
    ],
  });

  await gotoPanel(page);

  // Watchlist section: both videos rendered, with status badges.
  const watchlist = page.locator('[data-youtube-progress-target="watchlist"]');
  await expect(watchlist.locator('.youtube-progress-video-row')).toHaveCount(2);
  await expect(watchlist.getByText('Short intro clip')).toBeVisible();
  await expect(watchlist.locator('.youtube-progress-status-badge--watched')).toHaveText('Obejrzany');

  // Session section: one un-pushed card exposing the "Wyślij do YouTube" button.
  const sessions = page.locator('[data-youtube-progress-target="sessions"]');
  await expect(sessions.locator('.youtube-progress-session-card')).toHaveCount(1);
  await expect(sessions.getByRole('button', { name: 'Wyślij do YouTube' })).toBeVisible();
  await expect(sessions.getByText('8:00 · 1 filmów')).toBeVisible();
});

test('empty reads render the empty-state placeholders, not spinners', async ({ page }) => {
  await mockReads(page, { videos: [], sessions: [] });

  await gotoPanel(page);

  await expect(page.getByText('Watchlista jest pusta.')).toBeVisible();
  await expect(page.getByText('Brak sesji. Zsynchronizuj watchlistę.')).toBeVisible();
  await expect(page.locator('.loading')).toHaveCount(0);
});

test('"Rozpocznij" on a split-pool video POSTs the mark-started command', async ({ page }) => {
  await mockReads(page, { videos: [video({ youtubeId: 'vidSTART001', status: 'split-pool' })] });

  await gotoPanel(page);

  const watchlist = page.locator('[data-youtube-progress-target="watchlist"]');
  const startReq = page.waitForRequest(
    (req) => req.method() === 'POST' && req.url().endsWith('/api/youtube-progress/videos/vidSTART001/start'),
  );
  await watchlist.getByRole('button', { name: 'Rozpocznij' }).click();
  await startReq;
});

test('sync without a configured playlist surfaces the 400 in the error banner', async ({ page }) => {
  await mockReads(page, { videos: [], sessions: [] });
  await page.route('**/api/youtube-progress/sync', (route) =>
    route.fulfill({
      status: 400,
      contentType: 'application/json',
      body: JSON.stringify({ error: 'YouTube watchlist not configured. Set YOUTUBE_WATCHLIST_PLAYLIST_ID.' }),
    }),
  );

  await gotoPanel(page);
  await page.getByRole('button', { name: 'Synchronizuj' }).click();

  const banner = page.locator('#error-banner');
  await expect(banner).toBeVisible();
  await expect(banner).toHaveText(/not configured/i);
});
