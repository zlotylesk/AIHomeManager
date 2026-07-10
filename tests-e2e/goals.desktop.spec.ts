import { test, expect, type Page } from '@playwright/test';

type GoalFixture = {
  goalId: string;
  type: string;
  period: string;
  target: number;
  achieved: number;
  percent: number;
  met: boolean;
};

type StreakFixture = {
  type: string;
  currentLength: number;
  longestLength: number;
  lastActivityDate: string | null;
};

const goal = (overrides: Partial<GoalFixture> = {}): GoalFixture => ({
  goalId: '11111111-1111-4111-8111-111111111111',
  type: 'book_pages',
  period: 'daily',
  target: 50,
  achieved: 30,
  percent: 60,
  met: false,
  ...overrides,
});

async function mockReads(
  page: Page,
  data: { goals?: GoalFixture[]; streaks?: StreakFixture[] },
): Promise<void> {
  await page.route('**/api/goals/streaks', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(data.streaks ?? []) }),
  );
  await page.route('**/api/goals', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(data.goals ?? []) }),
  );
}

async function gotoGoals(page: Page): Promise<void> {
  await page.goto('/goals');
  await expect(page.locator('.app-title')).toHaveText('Cele i streaki');
  await expect(page.locator('.loading')).toHaveCount(0, { timeout: 10_000 });
}

test('renders goal cards with progress and streaks from stubbed reads', async ({ page }) => {
  await mockReads(page, {
    goals: [
      goal({ type: 'book_pages', period: 'daily', target: 50, achieved: 30, percent: 60, met: false }),
      goal({ goalId: '22222222-2222-4222-8222-222222222222', type: 'articles_read', period: 'weekly', target: 5, achieved: 5, percent: 100, met: true }),
    ],
    streaks: [
      { type: 'book_pages', currentLength: 3, longestLength: 7, lastActivityDate: '2026-07-10' },
    ],
  });

  await gotoGoals(page);

  await expect(page.locator('.goal-card')).toHaveCount(2);
  await expect(page.locator('.goal-card').first()).toContainText('Strony książek');
  await expect(page.locator('.goal-card').first()).toContainText('30 / 50');
  await expect(page.locator('.goal-card').first()).toContainText('60%');
  await expect(page.locator('.goal-card--met')).toHaveCount(1);

  await expect(page.locator('.streak-card')).toHaveCount(1);
  await expect(page.locator('.streak-card')).toContainText('3 dni z rzędu');
  await expect(page.locator('.streak-card')).toContainText('Rekord: 7 dni');
});

test('shows an empty state when there are no goals', async ({ page }) => {
  await mockReads(page, { goals: [], streaks: [] });
  await gotoGoals(page);

  await expect(page.locator('.goals-list .empty-state')).toBeVisible();
  await expect(page.locator('.goal-card')).toHaveCount(0);
});

test('clicking Edytuj reveals the inline edit form', async ({ page }) => {
  await mockReads(page, { goals: [goal()], streaks: [] });
  await gotoGoals(page);

  await page.locator('.goal-card .js-goal-edit').click();

  await expect(page.locator('.goal-edit-form')).toBeVisible();
  await expect(page.locator('.goal-edit-form .js-edit-target')).toHaveValue('50');
});

test('creating a goal posts to the API and re-renders the list', async ({ page }) => {
  let goals: GoalFixture[] = [];
  await page.route('**/api/goals/streaks', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify([]) }),
  );
  await page.route('**/api/goals', (route) => {
    if ('POST' === route.request().method()) {
      goals = [goal({ goalId: '33333333-3333-4333-8333-333333333333', type: 'series_episodes', period: 'weekly', target: 4, achieved: 0, percent: 0, met: false })];
      return route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ id: '33333333-3333-4333-8333-333333333333' }) });
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(goals) });
  });

  await gotoGoals(page);
  await expect(page.locator('.goal-card')).toHaveCount(0);

  await page.locator('[data-goals-target="type"]').selectOption('series_episodes');
  await page.locator('[data-goals-target="target"]').fill('4');
  await page.locator('[data-goals-target="period"]').selectOption('weekly');
  await page.locator('.goal-create-form button[type="submit"]').click();

  await expect(page.locator('.goal-card')).toHaveCount(1);
  await expect(page.locator('.goal-card')).toContainText('Odcinki seriali');
});
