import { test, expect, type Page, type APIRequestContext } from '@playwright/test';


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

  await expect(page.locator('#info-banner')).toHaveText(/task created/i);
  const row = page.locator('#tasks-table tbody tr', { hasText: title });
  await expect(row).toBeVisible();
  await expect(row.locator('.status-badge--pending')).toHaveText('Pending');
});

test('completing a pending task flips its badge and removes the Complete button', async ({ page, request }) => {
  const title = uniqueTitle('E2E Complete');
  await seedTask(request, title);

  await gotoTasks(page);

  const row = page.locator('#tasks-table tbody tr', { hasText: title });
  await expect(row.locator('.status-badge--pending')).toHaveText('Pending');

  await row.locator('.js-task-complete').click();

  await expect(page.locator('#info-banner')).toHaveText(/task completed/i);
  const completedRow = page.locator('#tasks-table tbody tr', { hasText: title });
  await expect(completedRow.locator('.status-badge--completed')).toHaveText('Completed');
  await expect(completedRow.locator('.js-task-complete')).toHaveCount(0);
});

test('cancelling a pending task flips its badge and removes the action buttons', async ({ page, request }) => {
  const title = uniqueTitle('E2E Cancel');
  await seedTask(request, title);

  await gotoTasks(page);

  const row = page.locator('#tasks-table tbody tr', { hasText: title });
  await expect(row.locator('.status-badge--pending')).toHaveText('Pending');

  // Cancel goes through a confirm() dialog — auto-accept it before clicking.
  page.on('dialog', (dialog) => dialog.accept());
  await row.locator('.js-task-cancel').click();

  await expect(page.locator('#info-banner')).toHaveText(/task cancelled/i);
  const cancelledRow = page.locator('#tasks-table tbody tr', { hasText: title });
  await expect(cancelledRow.locator('.status-badge--cancelled')).toHaveText('Cancelled');
  await expect(cancelledRow.locator('.js-task-cancel')).toHaveCount(0);
  await expect(cancelledRow.locator('.js-task-complete')).toHaveCount(0);
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

test('editing a pending task pre-fills the form and updates its row in place', async ({ page, request }) => {
  const title = uniqueTitle('E2E Edit');
  await seedTask(request, title);

  await gotoTasks(page);

  const row = page.locator('#tasks-table tbody tr', { hasText: title });
  await row.locator('.js-task-edit').click();

  const modal = page.locator('#task-edit-modal');
  await expect(modal).toBeVisible();
  await expect(modal.locator('#edit-task-title')).toHaveValue(title);
  await expect(modal.locator('#edit-task-start')).not.toHaveValue('');
  await expect(modal.locator('#edit-task-end')).not.toHaveValue('');

  const newTitle = uniqueTitle('E2E Edited');
  await modal.locator('#edit-task-title').fill(newTitle);
  await modal.locator('[type=submit]').click();

  await expect(page.locator('#info-banner')).toHaveText(/task updated/i);
  await expect(modal).toBeHidden();
  await expect(page.locator('#tasks-table tbody tr', { hasText: newTitle })).toBeVisible();
  await expect(page.locator('#tasks-table tbody tr', { hasText: title })).toHaveCount(0);
});

test('deleting a task removes its row from the list', async ({ page, request }) => {
  const title = uniqueTitle('E2E Delete');
  await seedTask(request, title);

  await gotoTasks(page);

  const row = page.locator('#tasks-table tbody tr', { hasText: title });
  await expect(row).toBeVisible();

  // Delete goes through a confirm() dialog — auto-accept it before clicking.
  page.on('dialog', (dialog) => dialog.accept());
  await row.locator('.js-task-delete').click();

  await expect(page.locator('#info-banner')).toHaveText(/task deleted/i);
  await expect(page.locator('#tasks-table tbody tr', { hasText: title })).toHaveCount(0);
});

test('viewing a task opens a detail modal with its fields and closes', async ({ page, request }) => {
  const title = uniqueTitle('E2E Detail');
  await seedTask(request, title);

  await gotoTasks(page);

  const row = page.locator('#tasks-table tbody tr', { hasText: title });
  await row.locator('.js-task-view').click();

  const modal = page.locator('#task-detail-modal');
  await expect(modal).toBeVisible();
  await expect(modal.locator('#detail-title')).toHaveText(title);
  await expect(modal.locator('#detail-status .status-badge--pending')).toHaveText('Pending');
  await expect(modal.locator('#detail-duration')).toHaveText('1h 30m');
  await expect(modal.locator('#detail-google')).toHaveText('Not synced');

  await modal.locator('.js-detail-close').click();
  await expect(modal).toBeHidden();
});
