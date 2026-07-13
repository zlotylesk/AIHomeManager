import { test, expect, type Page } from '@playwright/test';

async function mockDashboard(page: Page, body: unknown): Promise<void> {
  await page.route('**/api/dashboard', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) }),
  );
}

async function gotoCockpit(page: Page): Promise<void> {
  await page.goto('/');
  await expect(page.locator('.app-title')).toHaveText('Kokpit — na dziś');
  await expect(page.locator('.loading')).toHaveCount(0, { timeout: 10_000 });
}

test('cockpit renders the widget cards on a mobile viewport', async ({ page }) => {
  await mockDashboard(page, {
    date: '2026-07-13',
    tasks: [{ id: 't-1', title: 'Standup', startsAt: '2026-07-13T09:00:00+02:00', endsAt: '2026-07-13T09:15:00+02:00' }],
    article: null,
    goals: [{ type: 'book_pages', target: 50, period: 'daily', currentStreak: 2, longestStreak: 4, lastActivityDate: '2026-07-12' }],
    recommendations: [],
    recentTracks: [{ artist: 'Artist', title: 'Track', playedAt: '2026-07-13T08:00:00+02:00', source: 'manual' }],
  });

  await gotoCockpit(page);

  await expect(page.locator('.dashboard-card')).toHaveCount(5);
  await expect(page.locator('.dashboard-card', { hasText: 'Zadania na dziś' })).toContainText('Standup');
  await expect(page.locator('.dashboard-card', { hasText: 'Cele i passy' })).toContainText('2 dni z rzędu');
});

test('cockpit degrades to empty states on mobile without breaking the layout', async ({ page }) => {
  await mockDashboard(page, {
    date: '2026-07-13',
    tasks: [],
    article: null,
    goals: [],
    recommendations: [],
    recentTracks: [],
  });

  await gotoCockpit(page);

  await expect(page.locator('.dashboard-card')).toHaveCount(5);
  await expect(page.locator('.dashboard-card .empty-state')).toHaveCount(5);
});
