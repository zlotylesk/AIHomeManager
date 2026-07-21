import { test, expect, type Page } from '@playwright/test';

type PointFixture = { bucketStart: string; value: number };

type SeriesFixture = {
  metric: string;
  unit: string;
  total: number;
  average: number;
  headline: number;
  points: PointFixture[];
};

type TrendsFixture = {
  from: string;
  to: string;
  granularity: string;
  series: SeriesFixture[];
};

const weeklyPoints = (values: number[]): PointFixture[] =>
  values.map((value, index) => ({
    bucketStart: `2026-07-${String(6 + index * 7).padStart(2, '0')}`,
    value,
  }));

const series = (overrides: Partial<SeriesFixture> = {}): SeriesFixture => ({
  metric: 'books_pages_read',
  unit: 'count',
  total: 90,
  average: 30,
  headline: 90,
  points: weeklyPoints([40, 30, 20]),
  ...overrides,
});

const trends = (overrides: Partial<TrendsFixture> = {}): TrendsFixture => ({
  from: '2026-07-01',
  to: '2026-07-31',
  granularity: 'week',
  series: [
    series(),
    series({ metric: 'series_episodes_watched', total: 6, average: 2, headline: 6, points: weeklyPoints([3, 2, 1]) }),
    series({ metric: 'youtube_minutes_watched', unit: 'minutes', total: 75, average: 25, headline: 75, points: weeklyPoints([30, 25, 20]) }),
    series({ metric: 'music_tracks_played', total: 30, average: 10, headline: 30, points: weeklyPoints([10, 10, 10]) }),
    series({ metric: 'tasks_completion_rate', unit: 'percent', total: 210, average: 70, headline: 70, points: weeklyPoints([60, 70, 80]) }),
  ],
  ...overrides,
});

/**
 * The page itself is server-rendered; only the API read is stubbed, so the
 * Stimulus controller, the lazily-loaded Chart.js chunk and the real DOM all
 * take part (the Goals/Movies/Podcasts route-stub precedent).
 */
async function mockTrends(page: Page, byGranularity: Record<string, TrendsFixture>): Promise<void> {
  await page.route('**/api/trends*', (route) => {
    const granularity = new URL(route.request().url()).searchParams.get('granularity') ?? 'week';

    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(byGranularity[granularity] ?? byGranularity.week),
    });
  });
}

async function gotoTrends(page: Page): Promise<void> {
  await page.goto('/insights');
  await expect(page.locator('.app-title')).toHaveText('Trendy');
  await expect(page.locator('.trends-loading')).toHaveCount(0, { timeout: 10_000 });
}

test('renders one card per metric with its headline figure', async ({ page }) => {
  await mockTrends(page, { week: trends() });
  await gotoTrends(page);

  await expect(page.locator('.trends-card')).toHaveCount(5);
  await expect(page.locator('[data-metric="books_pages_read"] .trends-card-title')).toHaveText('Tempo czytania');
  await expect(page.locator('[data-metric="books_pages_read"] .trends-card-headline')).toHaveText('90');
  await expect(page.locator('[data-metric="youtube_minutes_watched"] .trends-card-headline')).toHaveText('75 min');
});

test('draws a chart canvas for every readable metric', async ({ page }) => {
  await mockTrends(page, { week: trends() });
  await gotoTrends(page);

  await expect(page.locator('canvas.trends-chart')).toHaveCount(5);

  // Chart.js sizes the canvas once it has rendered — a zero-width canvas would
  // mean the lazily-loaded chunk never ran.
  const width = await page.locator('[data-metric="books_pages_read"] canvas').evaluate(
    (canvas) => (canvas as HTMLCanvasElement).width,
  );
  expect(width).toBeGreaterThan(0);
});

/**
 * "łącznie" vs "średnio" is the difference between a total and an average; a
 * summed completion rate on a dashboard would be nonsense.
 */
test('names which fold the headline figure is', async ({ page }) => {
  await mockTrends(page, { week: trends() });
  await gotoTrends(page);

  await expect(page.locator('[data-metric="books_pages_read"] .trends-card-caption')).toHaveText('łącznie w okresie');
  await expect(page.locator('[data-metric="tasks_completion_rate"] .trends-card-caption')).toHaveText('średnio w okresie');
  await expect(page.locator('[data-metric="tasks_completion_rate"] .trends-card-headline')).toHaveText('70%');
});

test('switching the range reloads the dashboard with monthly buckets', async ({ page }) => {
  await mockTrends(page, {
    week: trends(),
    month: trends({
      granularity: 'month',
      series: [series({ headline: 250, total: 250, points: [{ bucketStart: '2026-07-01', value: 250 }] })],
    }),
  });
  await gotoTrends(page);

  await expect(page.locator('.trends-card')).toHaveCount(5);

  await page.selectOption('[data-trends-target="granularity"]', 'month');

  await expect(page.locator('.trends-card')).toHaveCount(1);
  await expect(page.locator('[data-metric="books_pages_read"] .trends-card-headline')).toHaveText('250');
});

/**
 * A metric that could not be read comes back with an empty point list. It must
 * not look like a quiet week — and it must not take the other cards down.
 */
test('an unavailable metric is called out without blanking the rest', async ({ page }) => {
  await mockTrends(page, {
    week: trends({
      series: [
        series(),
        series({ metric: 'music_tracks_played', total: 0, average: 0, headline: 0, points: [] }),
      ],
    }),
  });
  await gotoTrends(page);

  const broken = page.locator('[data-metric="music_tracks_played"]');
  await expect(broken).toHaveClass(/trends-card--unavailable/);
  await expect(broken.locator('.trends-card-headline')).toHaveText('Brak danych');
  await expect(broken.locator('.trends-card-empty')).toBeVisible();
  await expect(broken.locator('canvas')).toHaveCount(0);

  await expect(page.locator('[data-metric="books_pages_read"] .trends-card-headline')).toHaveText('90');
  await expect(page.locator('[data-metric="books_pages_read"] canvas')).toHaveCount(1);
});

test('an idle metric still charts its zeroes rather than reporting a failure', async ({ page }) => {
  await mockTrends(page, {
    week: trends({
      series: [series({ total: 0, average: 0, headline: 0, points: weeklyPoints([0, 0, 0]) })],
    }),
  });
  await gotoTrends(page);

  const idle = page.locator('[data-metric="books_pages_read"]');
  await expect(idle).toHaveClass(/trends-card--idle/);
  await expect(idle.locator('.trends-card-headline')).toHaveText('0');
  await expect(idle.locator('canvas')).toHaveCount(1);
});

test('a failing API surfaces an error instead of an endless spinner', async ({ page }) => {
  await page.route('**/api/trends*', (route) => route.fulfill({ status: 500, contentType: 'application/json', body: '{"error":"boom"}' }));

  await page.goto('/insights');

  await expect(page.locator('.trends-error')).toBeVisible();
  await expect(page.locator('.trends-loading')).toHaveCount(0);
});

test('the dashboard is reachable from the module navigation', async ({ page }) => {
  await mockTrends(page, { week: trends() });
  await page.goto('/series');

  await page.click('a[href="/insights"]');

  await expect(page.locator('.app-title')).toHaveText('Trendy');
});
