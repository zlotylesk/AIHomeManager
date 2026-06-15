import { test, expect, type Page, type APIRequestContext } from '@playwright/test';

// The Tasks panel is plain Twig + vanilla JS (not Stimulus). On load it calls
// GET /api/tasks and renders the rows into #tasks-table. The render test seeds a
// real task through POST /api/tasks (the create endpoint carries the X-API-Key
// from playwright.config); the empty/error tests route-mock the read so they
// stay hermetic and independent of DB state — same pattern as the Books specs.

const uniqueTitle = (prefix: string) => `${prefix} ${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;

async function seedTask(request: APIRequestContext, title: string): Promise<string> {
  const res = await request.post('/api/tasks', {
    data: { title, start: '2026-06-01T09:00:00+02:00', end: '2026-06-01T10:30:00+02:00' },
  });
  expect(res.ok(), `task seed failed: ${res.status()}`).toBeTruthy();
  const { id } = await res.json();
  return id;
}

async function gotoTasks(page: Page): Promise<void> {
  await page.goto('/tasks');
  await expect(page.locator('.app-title')).toHaveText('Tasks');
  // loadTasks() flips the spinner off once the read resolves.
  await expect(page.locator('#tasks-loading')).toBeHidden({ timeout: 10_000 });
}

test('task list renders a seeded task with its status badge', async ({ page, request }) => {
  const title = uniqueTitle('E2E Task');
  await seedTask(request, title);

  await gotoTasks(page);

  const row = page.locator('#tasks-table tbody tr', { hasText: title });
  await expect(row).toBeVisible();
  await expect(row.locator('.status-badge--pending')).toHaveText('Pending');
});

test('a task created through the New Task form appears in the list', async ({ page }) => {
  const title = uniqueTitle('E2E Created');

  await gotoTasks(page);

  await page.fill('#task-title', title);
  await page.fill('#task-start', '2026-07-01T09:00');
  await page.fill('#task-end', '2026-07-01T10:30');
  await page.click('#form-create-task [type=submit]');

  // On success the form resets, the info banner shows, and loadTasks() re-runs.
  await expect(page.locator('#info-banner')).toHaveText(/task created/i);
  const row = page.locator('#tasks-table tbody tr', { hasText: title });
  await expect(row).toBeVisible();
  await expect(row.locator('.status-badge--pending')).toHaveText('Pending');
});

test('empty list renders the empty-state placeholder, not a spinner', async ({ page }) => {
  await page.route('**/api/tasks', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: '[]' }),
  );

  await gotoTasks(page);

  await expect(page.locator('#tasks-empty')).toBeVisible();
  await expect(page.locator('#tasks-table')).toBeHidden();
});

test('a failing list request surfaces in the shared error banner', async ({ page }) => {
  await page.route('**/api/tasks', (route) =>
    route.fulfill({
      status: 500,
      contentType: 'application/json',
      body: JSON.stringify({ error: 'Internal server error.' }),
    }),
  );

  await page.goto('/tasks');

  const banner = page.locator('#error-banner');
  await expect(banner).toBeVisible();
  await expect(banner).toHaveText(/internal server error/i);
});
