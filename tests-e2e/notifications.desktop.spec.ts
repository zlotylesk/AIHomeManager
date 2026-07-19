import { test, expect, type Page } from '@playwright/test';

type PreferenceFixture = {
  type: string;
  enabled: boolean;
  channels: string[];
  quietFrom: string | null;
  quietTo: string | null;
};

type NotificationFixture = {
  id: string;
  type: string;
  channel: string;
  status: string;
  payload: Record<string, unknown>;
  createdAt: string;
  sentAt: string | null;
  failureReason: string | null;
};

const preference = (overrides: Partial<PreferenceFixture> = {}): PreferenceFixture => ({
  type: 'task_due',
  enabled: true,
  channels: ['email', 'push'],
  quietFrom: null,
  quietTo: null,
  ...overrides,
});

const notification = (overrides: Partial<NotificationFixture> = {}): NotificationFixture => ({
  id: 'n-1',
  type: 'task_due',
  channel: 'email',
  status: 'sent',
  payload: { title: 'Zapłacić czynsz' },
  createdAt: '2026-07-19T08:15:00+02:00',
  sentAt: '2026-07-19T08:15:03+02:00',
  failureReason: null,
  ...overrides,
});

/**
 * The panel is server-rendered but filled from the API, so the reads are stubbed
 * (the Goals/Dashboard route-stub precedent). Writes are captured rather than
 * executed, which is what lets the assertions check *what the panel sent*.
 */
async function mockApi(
  page: Page,
  data: { preferences?: PreferenceFixture[]; history?: NotificationFixture[] },
): Promise<Array<{ method: string; url: string; body: unknown }>> {
  const writes: Array<{ method: string; url: string; body: unknown }> = [];

  await page.route('**/api/notifications/preferences/**', (route) => {
    writes.push({
      method: route.request().method(),
      url: route.request().url(),
      body: route.request().postDataJSON(),
    });

    return route.fulfill({ status: 204, body: '' });
  });

  await page.route('**/api/notifications/preferences', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(data.preferences ?? []),
    }),
  );

  await page.route('**/api/notifications/history*', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(data.history ?? []),
    }),
  );

  return writes;
}

async function gotoNotifications(page: Page): Promise<void> {
  await page.goto('/notifications');
  await expect(page.locator('h1')).toHaveText('Powiadomienia');
}

test('renders one row per notification type with its channels', async ({ page }) => {
  await mockApi(page, {
    preferences: [
      preference({ type: 'task_due', channels: ['email', 'push'] }),
      preference({ type: 'article_daily', enabled: false, channels: ['email'] }),
    ],
  });

  await gotoNotifications(page);

  await expect(page.locator('.notification-preference')).toHaveCount(2);

  const taskDue = page.locator('.notification-preference[data-type="task_due"]');
  await expect(taskDue).toContainText('Termin zadania');
  await expect(taskDue.locator('.js-type-enabled')).toBeChecked();
  await expect(taskDue.locator('.js-channel[data-channel="push"]')).toBeChecked();

  const article = page.locator('.notification-preference[data-type="article_daily"]');
  await expect(article.locator('.js-type-enabled')).not.toBeChecked();
  await expect(article.locator('.js-channel[data-channel="push"]')).not.toBeChecked();
});

test('turning a type off sends the toggle to the API', async ({ page }) => {
  const writes = await mockApi(page, { preferences: [preference()] });
  await gotoNotifications(page);

  await page.locator('.notification-preference[data-type="task_due"] .js-type-enabled').uncheck();

  await expect.poll(() => writes.length).toBeGreaterThan(0);
  expect(writes[0].method).toBe('PATCH');
  expect(writes[0].url).toContain('/preferences/task_due/enabled');
  expect(writes[0].body).toEqual({ enabled: false });
});

test('turning one channel off leaves the other alone', async ({ page }) => {
  const writes = await mockApi(page, { preferences: [preference()] });
  await gotoNotifications(page);

  await page.locator('.notification-preference[data-type="task_due"] .js-channel[data-channel="push"]').uncheck();

  await expect.poll(() => writes.length).toBeGreaterThan(0);
  expect(writes[0].url).toContain('/preferences/task_due/channels/push');
  expect(writes[0].body).toEqual({ enabled: false });
  await expect(page.locator('.notification-preference[data-type="task_due"] .js-channel[data-channel="email"]')).toBeChecked();
});

test('an overnight quiet window is spelled out rather than left ambiguous', async ({ page }) => {
  await mockApi(page, { preferences: [preference({ quietFrom: '22:00', quietTo: '07:00' })] });
  await gotoNotifications(page);

  await expect(page.locator('.notification-preference[data-type="task_due"] .notification-quiet')).toContainText('przez noc');
});

/**
 * A silently dropped half-range would persist as "no quiet hours", so the panel
 * must refuse to send it.
 */
test('a half-stated quiet range is refused before it reaches the API', async ({ page }) => {
  const writes = await mockApi(page, { preferences: [preference()] });
  await gotoNotifications(page);

  const row = page.locator('.notification-preference[data-type="task_due"]');
  await row.locator('.js-quiet-from').fill('22:00');
  await row.locator('.js-save-quiet').click();

  await expect(page.locator('#error-banner')).toBeVisible();
  expect(writes.filter((write) => write.url.includes('quiet-hours'))).toHaveLength(0);
});

test('a complete quiet range is sent', async ({ page }) => {
  const writes = await mockApi(page, { preferences: [preference()] });
  await gotoNotifications(page);

  const row = page.locator('.notification-preference[data-type="task_due"]');
  await row.locator('.js-quiet-from').fill('22:00');
  await row.locator('.js-quiet-to').fill('07:00');
  await row.locator('.js-save-quiet').click();

  await expect.poll(() => writes.filter((write) => write.url.includes('quiet-hours')).length).toBe(1);
  expect(writes.find((write) => write.url.includes('quiet-hours'))?.body).toEqual({ from: '22:00', to: '07:00' });
});

test('the history lists delivered and failed notifications', async ({ page }) => {
  await mockApi(page, {
    preferences: [preference()],
    history: [
      notification({ id: 'n-1', status: 'sent' }),
      notification({ id: 'n-2', type: 'article_daily', channel: 'push', status: 'failed', sentAt: null, failureReason: 'gone' }),
    ],
  });

  await gotoNotifications(page);

  await expect(page.locator('.notification-history-item')).toHaveCount(2);
  await expect(page.locator('.notification-history-item').first()).toContainText('Termin zadania');
  await expect(page.locator('.notification-history-item').first()).toContainText('Wysłane');
  await expect(page.locator('.notification-history-item').nth(1)).toContainText('Błąd');
});

test('an empty history shows an empty state rather than a blank panel', async ({ page }) => {
  await mockApi(page, { preferences: [preference()], history: [] });
  await gotoNotifications(page);

  await expect(page.locator('#notification-history .empty-state')).toBeVisible();
});

test('the notifications page is reachable from the navigation', async ({ page }) => {
  await mockApi(page, { preferences: [preference()] });
  await page.goto('/');

  await page.locator('nav a', { hasText: 'Powiadomienia' }).click();

  await expect(page).toHaveURL(/\/notifications$/);
  await expect(page.locator('h1')).toHaveText('Powiadomienia');
});
