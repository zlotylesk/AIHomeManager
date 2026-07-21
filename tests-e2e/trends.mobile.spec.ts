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

async function mockTrends(page: Page, seriesList: SeriesFixture[] = []): Promise<void> {
  await page.route('**/api/trends*', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        from: '2026-07-01',
        to: '2026-07-31',
        granularity: new URL(route.request().url()).searchParams.get('granularity') ?? 'week',
        series: seriesList.length > 0 ? seriesList : [
          series(),
          series({ metric: 'tasks_completion_rate', unit: 'percent', total: 210, average: 70, headline: 70, points: weeklyPoints([60, 70, 80]) }),
        ],
      }),
    }),
  );
}

async function gotoTrends(page: Page): Promise<void> {
  await page.goto('/insights');
  await expect(page.locator('.app-title')).toHaveText('Trendy');
  await expect(page.locator('.trends-loading')).toHaveCount(0, { timeout: 10_000 });
}

test.describe('trends dashboard on mobile', () => {
  test('stacks the metric cards and renders their charts', async ({ page }) => {
    await mockTrends(page);
    await gotoTrends(page);

    await expect(page.locator('.trends-card')).toHaveCount(2);
    await expect(page.locator('canvas.trends-chart')).toHaveCount(2);
    await expect(page.locator('[data-metric="books_pages_read"] .trends-card-headline')).toHaveText('90');
  });

  test('does not overflow horizontally', async ({ page }) => {
    await mockTrends(page);
    await gotoTrends(page);

    const overflows = await page.evaluate(
      () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    );
    expect(overflows).toBe(false);
  });

  test('the range switcher is usable and reloads the dashboard', async ({ page }) => {
    await mockTrends(page);
    await gotoTrends(page);

    const switcher = page.locator('[data-trends-target="granularity"]');
    await expect(switcher).toBeVisible();

    await switcher.selectOption('month');

    await expect(page.locator('.trends-card')).toHaveCount(2);
    await expect(page.locator('canvas.trends-chart')).toHaveCount(2);
  });

  test('an unavailable metric is called out on the small viewport too', async ({ page }) => {
    await mockTrends(page, [
      series(),
      series({ metric: 'music_tracks_played', total: 0, average: 0, headline: 0, points: [] }),
    ]);
    await gotoTrends(page);

    const broken = page.locator('[data-metric="music_tracks_played"]');
    await expect(broken).toHaveClass(/trends-card--unavailable/);
    await expect(broken.locator('.trends-card-headline')).toHaveText('Brak danych');
    await expect(page.locator('[data-metric="books_pages_read"] canvas')).toHaveCount(1);
  });

  test('is reachable from the navigation', async ({ page }) => {
    await mockTrends(page);
    await page.goto('/series');

    await page.click('a[href="/insights"]');

    await expect(page.locator('.app-title')).toHaveText('Trendy');
  });
});
