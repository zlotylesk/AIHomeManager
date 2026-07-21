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
  episodeCount: 3,
  listenedEpisodeCount: 2,
  lastListenedAt: '2026-07-20T19:00:00+00:00',
  createdAt: '2026-07-01T10:00:00+00:00',
};

const QUIET_SHOW = {
  ...SHOW,
  id: 'pod-2',
  title: 'Cisza w eterze',
  publisher: null,
  description: null,
  episodeCount: 0,
  listenedEpisodeCount: 0,
  lastListenedAt: null,
};

const DETAIL = {
  ...SHOW,
  episodes: [
    {
      id: 'ep-1',
      title: 'Odcinek pierwszy',
      publishedAt: '2026-07-01T06:00:00+00:00',
      durationMs: 1_800_000,
      listened: true,
      resumePositionMs: 1_700_000,
      fullyPlayed: true,
    },
    {
      id: 'ep-2',
      title: 'Odcinek drugi',
      publishedAt: '2026-06-24T06:00:00+00:00',
      durationMs: 2_400_000,
      listened: true,
      resumePositionMs: 600_000,
      fullyPlayed: false,
    },
    {
      id: 'ep-3',
      title: 'Odcinek trzeci',
      publishedAt: null,
      durationMs: 1_200_000,
      listened: false,
      resumePositionMs: 0,
      fullyPlayed: false,
    },
  ],
  sessions: [
    { id: 's-1', episodeId: 'ep-1', episodeTitle: 'Odcinek pierwszy', listenedAt: '2026-07-20T19:00:00+00:00', resumePositionMs: 1_700_000, fullyPlayed: true },
    { id: 's-2', episodeId: 'ep-1', episodeTitle: 'Odcinek pierwszy', listenedAt: '2026-07-20T08:00:00+00:00', resumePositionMs: 600_000, fullyPlayed: false },
    { id: 's-3', episodeId: 'ep-2', episodeTitle: 'Odcinek drugi', listenedAt: '2026-07-18T12:00:00+00:00', resumePositionMs: 600_000, fullyPlayed: false },
  ],
};

/**
 * Stubs only `/api/podcasts*` so the real Stimulus controller drives the whole
 * flow against deterministic data, with no MySQL or Spotify dependency. The
 * `/podcasts` page itself is server-rendered (the Movies/Goals precedent).
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
      return json(route, 200, [SHOW, QUIET_SHOW]);
    }

    if ('/api/podcasts/pod-1' === path) {
      return json(route, 200, DETAIL);
    }

    return json(route, 404, { error: 'Podcast not found.' });
  });
}

test.describe('Podcasts', () => {
  test('lists followed shows with their listening counters', async ({ page }) => {
    await installPodcastsBackend(page);
    await page.goto('/podcasts');

    const cards = page.locator('.podcast-card');
    await expect(cards).toHaveCount(2);
    await expect(cards.first()).toContainText('Radio Naukowe');
    await expect(cards.first()).toContainText('2/3 odsłuchanych');
  });

  // A show nobody has listened to must still render, with honest zeroes rather
  // than being hidden or showing a bogus date.
  test('renders a never-listened show without inventing a date', async ({ page }) => {
    await installPodcastsBackend(page);
    await page.goto('/podcasts');

    const quiet = page.locator('.podcast-card', { hasText: 'Cisza w eterze' });
    await expect(quiet).toContainText('0/0 odsłuchanych');
    await expect(quiet).toContainText('Jeszcze nieodsłuchany');
  });

  // The whole module rests on listens being derived, so the page must not imply
  // a precision the data does not have.
  test('states that the listening time is an upper bound', async ({ page }) => {
    await installPodcastsBackend(page);
    await page.goto('/podcasts');

    await expect(page.locator('.podcasts-caveat')).toContainText('nie później');
  });

  test('opens a show and shows per-episode progress', async ({ page }) => {
    await installPodcastsBackend(page);
    await page.goto('/podcasts');

    await page.locator('.podcast-card', { hasText: 'Radio Naukowe' }).click();

    const episodes = page.locator('.podcast-episode');
    await expect(episodes).toHaveCount(3);
    await expect(episodes.nth(0)).toContainText('Odsłuchany w całości');
    await expect(episodes.nth(1)).toContainText('W trakcie');
    await expect(episodes.nth(2)).toContainText('Nieodsłuchany');
    await expect(episodes.nth(0)).toContainText('30 min');
  });

  test('groups the listening history by day, newest first', async ({ page }) => {
    await installPodcastsBackend(page);
    await page.goto('/podcasts');
    await page.locator('.podcast-card', { hasText: 'Radio Naukowe' }).click();

    const groups = page.locator('.podcast-session-group');
    await expect(groups).toHaveCount(2);
    await expect(groups.first().locator('.podcast-session')).toHaveCount(2);
  });

  test('returns to the list from the detail', async ({ page }) => {
    await installPodcastsBackend(page);
    await page.goto('/podcasts');
    await page.locator('.podcast-card', { hasText: 'Radio Naukowe' }).click();

    await expect(page.locator('.podcast-detail')).toBeVisible();
    await page.getByRole('button', { name: '← Wróć' }).click();

    await expect(page.locator('.podcast-card').first()).toBeVisible();
  });

  test('sync reports that the sweep started', async ({ page }) => {
    await installPodcastsBackend(page);
    await page.goto('/podcasts');

    await page.getByRole('button', { name: 'Synchronizuj' }).click();

    await expect(page.locator('#info-banner')).toContainText('Synchronizacja uruchomiona');
  });

  // A disconnected account must point the user at the authorization flow rather
  // than failing silently.
  test('sync offers the Spotify authorization link when not connected', async ({ page }) => {
    await installPodcastsBackend(page, {
      sync: { status: 409, body: { error: 'Spotify is not connected.', authUrl: '/auth/spotify' } },
    });
    await page.goto('/podcasts');

    await page.getByRole('button', { name: 'Synchronizuj' }).click();

    await expect(page.locator('#error-banner')).toContainText('/auth/spotify');
  });

  test('is reachable from the navigation', async ({ page }) => {
    await installPodcastsBackend(page);
    await page.goto('/');

    await page.getByRole('link', { name: 'Podcasty' }).click();

    await expect(page).toHaveURL(/\/podcasts$/);
    await expect(page.locator('.app-title')).toContainText('Podcasty');
  });
});
