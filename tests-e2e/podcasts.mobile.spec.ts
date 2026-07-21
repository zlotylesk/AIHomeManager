import { test, expect, type Page, type Route } from '@playwright/test';

type SyncOptions = { sync?: { status: number; body: unknown } };

function json(route: Route, status: number, body: unknown): Promise<void> {
  return route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
}

const SHOW = {
  id: 'pod-1',
  title: 'Radio Naukowe',
  publisher: 'Karolina Głowacka',
  coverUrl: null,
  description: 'Nauka po ludzku.',
  episodeCount: 2,
  listenedEpisodeCount: 1,
  lastListenedAt: '2026-07-20T19:00:00+00:00',
  createdAt: '2026-07-01T10:00:00+00:00',
};

const DETAIL = {
  ...SHOW,
  episodes: [
    {
      id: 'ep-1',
      title: 'Odcinek pierwszy',
      publishedAt: '2026-07-01T06:00:00+00:00',
      durationMs: 5_400_000,
      listened: true,
      resumePositionMs: 2_700_000,
      fullyPlayed: false,
    },
    {
      id: 'ep-2',
      title: 'Odcinek drugi',
      publishedAt: null,
      durationMs: null,
      listened: false,
      resumePositionMs: 0,
      fullyPlayed: false,
    },
  ],
  sessions: [
    { id: 's-1', episodeId: 'ep-1', episodeTitle: 'Odcinek pierwszy', listenedAt: '2026-07-20T19:00:00+00:00', resumePositionMs: 2_700_000, fullyPlayed: false },
  ],
};

/**
 * The same stubbed Podcasts API as the desktop spec, so the Stimulus controller
 * drives the list → detail → sync flow on the Pixel 5 viewport against
 * deterministic data (no MySQL/Spotify).
 */
async function installPodcastsBackend(page: Page, options: SyncOptions = {}): Promise<void> {
  const syncResponse = options.sync ?? { status: 202, body: { status: 'sync_started' } };

  await page.route(/\/api\/podcasts(\?|\/|$)/, async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;

    if ('/api/podcasts/sync' === path && 'POST' === request.method()) {
      return json(route, syncResponse.status, syncResponse.body);
    }

    if ('/api/podcasts' === path) {
      return json(route, 200, [SHOW]);
    }

    if ('/api/podcasts/pod-1' === path) {
      return json(route, 200, DETAIL);
    }

    return json(route, 404, { error: 'Podcast not found.' });
  });
}

test.describe('Podcasts (mobile)', () => {
  test('renders the list without overflowing the viewport', async ({ page }) => {
    await installPodcastsBackend(page);
    await page.goto('/podcasts');

    await expect(page.locator('.podcast-card')).toHaveCount(1);

    const overflows = await page.evaluate(
      () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    );
    expect(overflows).toBe(false);
  });

  test('opens a show and shows episode progress', async ({ page }) => {
    await installPodcastsBackend(page);
    await page.goto('/podcasts');

    await page.locator('.podcast-card', { hasText: 'Radio Naukowe' }).click();

    const episodes = page.locator('.podcast-episode');
    await expect(episodes).toHaveCount(2);
    await expect(episodes.nth(0)).toContainText('W trakcie — 50%');
    await expect(episodes.nth(0)).toContainText('1 h 30 min');
    // An unknown duration must degrade to a dash rather than "0 min".
    await expect(episodes.nth(1)).toContainText('—');
  });

  test('shows the listening history grouped by day', async ({ page }) => {
    await installPodcastsBackend(page);
    await page.goto('/podcasts');
    await page.locator('.podcast-card', { hasText: 'Radio Naukowe' }).click();

    await expect(page.locator('.podcast-session-group')).toHaveCount(1);
    await expect(page.locator('.podcast-session').first()).toContainText('Odcinek pierwszy');
  });

  test('sync reports that the sweep started', async ({ page }) => {
    await installPodcastsBackend(page);
    await page.goto('/podcasts');

    await page.getByRole('button', { name: 'Synchronizuj' }).click();

    await expect(page.locator('#info-banner')).toContainText('Synchronizacja uruchomiona');
  });

  test('sync offers the Spotify authorization link when not connected', async ({ page }) => {
    await installPodcastsBackend(page, {
      sync: { status: 409, body: { error: 'Spotify is not connected.', authUrl: '/auth/spotify' } },
    });
    await page.goto('/podcasts');

    await page.getByRole('button', { name: 'Synchronizuj' }).click();

    await expect(page.locator('#error-banner')).toContainText('/auth/spotify');
  });
});
