import { test, expect, type Page } from '@playwright/test';

type PreferenceFixture = {
  type: string;
  enabled: boolean;
  channels: string[];
  quietFrom: string | null;
  quietTo: string | null;
};

const preference = (overrides: Partial<PreferenceFixture> = {}): PreferenceFixture => ({
  type: 'task_due',
  enabled: true,
  channels: ['email', 'push'],
  quietFrom: null,
  quietTo: null,
  ...overrides,
});

async function mockApi(
  page: Page,
  preferences: PreferenceFixture[],
): Promise<Array<{ url: string; body: unknown }>> {
  const writes: Array<{ url: string; body: unknown }> = [];

  await page.route('**/api/notifications/preferences/**', (route) => {
    writes.push({ url: route.request().url(), body: route.request().postDataJSON() });

    return route.fulfill({ status: 204, body: '' });
  });

  await page.route('**/api/notifications/preferences', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(preferences) }),
  );

  await page.route('**/api/notifications/history*', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify([
        {
          id: 'n-1',
          type: 'task_due',
          channel: 'email',
          status: 'sent',
          payload: { title: 'Zapłacić czynsz' },
          createdAt: '2026-07-19T08:15:00+02:00',
          sentAt: '2026-07-19T08:15:03+02:00',
          failureReason: null,
        },
      ]),
    }),
  );

  return writes;
}

test('the settings panel renders on a phone viewport', async ({ page }) => {
  await mockApi(page, [preference(), preference({ type: 'daily_digest', channels: ['email'] })]);

  await page.goto('/notifications');

  await expect(page.locator('h1')).toHaveText('Powiadomienia');
  await expect(page.locator('.notification-preference')).toHaveCount(2);
  await expect(page.locator('.notification-history-item')).toHaveCount(1);
});

test('toggling a channel works on touch-sized controls', async ({ page }) => {
  const writes = await mockApi(page, [preference()]);
  await page.goto('/notifications');

  await page.locator('.notification-preference[data-type="task_due"] .js-channel[data-channel="email"]').uncheck();

  await expect.poll(() => writes.length).toBeGreaterThan(0);
  expect(writes[0].url).toContain('/preferences/task_due/channels/email');
  expect(writes[0].body).toEqual({ enabled: false });
});

test('the push toggle is present and states its availability', async ({ page }) => {
  await mockApi(page, [preference()]);
  await page.goto('/notifications');

  // Headless Chromium exposes the Push API but grants no permission, so the
  // button must be visible and labelled either way rather than silently absent.
  await expect(page.locator('#notification-push-toggle')).toBeVisible();
  await expect(page.locator('#notification-push-toggle')).not.toBeEmpty();
});
