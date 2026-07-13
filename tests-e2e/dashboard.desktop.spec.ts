import { test, expect, type Page } from '@playwright/test';

type DashboardFixture = {
  date: string;
  tasks: Array<{ id: string; title: string; startsAt: string; endsAt: string }>;
  article: { title: string; url: string; category: string | null; estimatedReadTime: number | null; isRead: boolean } | null;
  goals: Array<{ type: string; target: number; period: string; currentStreak: number; longestStreak: number; lastActivityDate: string | null }>;
  recommendations: Array<{ kind: string; title: string; coverUrl: string | null; detail: string | null }>;
  recentTracks: Array<{ artist: string; title: string; playedAt: string; source: string }>;
};

const fullCockpit: DashboardFixture = {
  date: '2026-07-13',
  tasks: [{ id: 't-1', title: 'Standup', startsAt: '2026-07-13T09:00:00+02:00', endsAt: '2026-07-13T09:15:00+02:00' }],
  article: { title: 'Article of the day', url: 'https://example.test/a', category: 'tech', estimatedReadTime: 5, isRead: false },
  goals: [{ type: 'book_pages', target: 50, period: 'daily', currentStreak: 3, longestStreak: 9, lastActivityDate: '2026-07-12' }],
  recommendations: [{ kind: 'series', title: 'Ongoing Show', coverUrl: null, detail: '2020' }],
  recentTracks: [{ artist: 'Artist', title: 'Track', playedAt: '2026-07-13T08:00:00+02:00', source: 'manual' }],
};

const emptyCockpit: DashboardFixture = {
  date: '2026-07-13',
  tasks: [],
  article: null,
  goals: [],
  recommendations: [],
  recentTracks: [],
};

async function mockDashboard(page: Page, data: DashboardFixture): Promise<void> {
  await page.route('**/api/dashboard', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(data) }),
  );
}

async function gotoCockpit(page: Page): Promise<void> {
  await page.goto('/');
  await expect(page.locator('.app-title')).toHaveText('Kokpit — na dziś');
  await expect(page.locator('.loading')).toHaveCount(0, { timeout: 10_000 });
}

test('cockpit renders every widget card from the stubbed read-model', async ({ page }) => {
  await mockDashboard(page, fullCockpit);
  await gotoCockpit(page);

  await expect(page.locator('.dashboard-card')).toHaveCount(5);

  const tasks = page.locator('.dashboard-card', { hasText: 'Zadania na dziś' });
  await expect(tasks).toContainText('Standup');
  await expect(tasks).toContainText('09:00–09:15');

  const article = page.locator('.dashboard-card', { hasText: 'Artykuł dnia' });
  await expect(article.locator('.dashboard-article-title')).toHaveText('Article of the day');
  await expect(article).toContainText('~5 min czytania');
  await expect(article).toContainText('Do przeczytania');

  const goals = page.locator('.dashboard-card', { hasText: 'Cele i passy' });
  await expect(goals).toContainText('Strony książek');
  await expect(goals).toContainText('3 dni z rzędu');

  const recommendations = page.locator('.dashboard-card', { hasText: 'Rekomendacje' });
  await expect(recommendations).toContainText('Serial');
  await expect(recommendations).toContainText('Ongoing Show');

  const tracks = page.locator('.dashboard-card', { hasText: 'Ostatnio słuchane' });
  await expect(tracks).toContainText('Artist — Track');
  await expect(tracks).toContainText('Ręcznie');
});

test('cockpit shows a per-widget empty state when there is no data', async ({ page }) => {
  await mockDashboard(page, emptyCockpit);
  await gotoCockpit(page);

  // The grid stays intact — five cards, each degrading to its own empty state.
  await expect(page.locator('.dashboard-card')).toHaveCount(5);
  await expect(page.locator('.dashboard-card .empty-state')).toHaveCount(5);
  await expect(page.locator('.dashboard-item')).toHaveCount(0);
});

test('cockpit keeps the module navigation available', async ({ page }) => {
  await mockDashboard(page, emptyCockpit);
  await gotoCockpit(page);

  await expect(page.locator('nav.navbar a[href="/series"]')).toBeVisible();
  await expect(page.locator('nav.navbar a[href="/tasks"]')).toBeVisible();
  await expect(page.locator('nav.navbar a[href="/goals"]')).toBeVisible();
});
