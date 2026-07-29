import { test, expect, type Page } from '@playwright/test';

type Category = {
  id: string;
  name: string;
  type: string;
  monthlyLimitAmountInCents: number | null;
  monthlyLimitCurrency: string | null;
};

type Transaction = {
  id: string;
  amountInCents: number;
  currency: string;
  date: string;
  categoryId: string;
  type: string;
  description: string | null;
};

type Report = {
  month: string;
  totalIncomeInCents: number;
  totalExpensesInCents: number;
  balanceInCents: number;
  categories: Array<{
    categoryId: string;
    categoryName: string;
    type: string;
    spentInCents: number;
    monthlyLimitInCents: number | null;
    monthlyLimitCurrency: string | null;
    percentUsed: number | null;
    overLimit: boolean;
  }>;
};

type BudgetState = { categories: Category[]; transactions: Transaction[]; report: Report };

function defaultState(): BudgetState {
  return {
    categories: [
      { id: 'cat-1', name: 'Jedzenie', type: 'expense', monthlyLimitAmountInCents: 50000, monthlyLimitCurrency: 'PLN' },
    ],
    transactions: [
      { id: 'tx-1', amountInCents: 60000, currency: 'PLN', date: '2026-07-05', categoryId: 'cat-1', type: 'expense', description: 'Zakupy' },
    ],
    report: {
      month: '2026-07',
      totalIncomeInCents: 0,
      totalExpensesInCents: 60000,
      balanceInCents: -60000,
      categories: [
        { categoryId: 'cat-1', categoryName: 'Jedzenie', type: 'expense', spentInCents: 60000, monthlyLimitInCents: 50000, monthlyLimitCurrency: 'PLN', percentUsed: 120, overLimit: true },
      ],
    },
  };
}

// Mirrors the desktop spec's single combined route handler (the Podcasts
// precedent) — see budget.desktop.spec.ts for why this shape avoids
// Playwright's route-registration-order pitfall.
async function installBudgetBackend(page: Page, state: BudgetState = defaultState()): Promise<void> {
  await page.route(/\/api\/budget\//, async (route) => {
    const request = route.request();
    const method = request.method();
    const url = new URL(request.url());
    const path = url.pathname;
    const respond = (status: number, body?: unknown) =>
      route.fulfill(undefined === body
        ? { status }
        : { status, contentType: 'application/json', body: JSON.stringify(body) });

    if ('/api/budget/categories' === path) {
      return respond(200, state.categories);
    }

    if ('/api/budget/transactions' === path) {
      if ('POST' === method) {
        const body = request.postDataJSON() as Omit<Transaction, 'id'>;
        const id = `tx-new-${state.transactions.length + 1}`;
        state.transactions.push({ id, ...body });
        return respond(201, { id });
      }
      return respond(200, state.transactions);
    }

    if ('/api/budget/report' === path) {
      return respond(200, state.report);
    }

    return respond(404, { error: 'Not found.' });
  });
}

async function gotoBudget(page: Page): Promise<void> {
  await page.goto('/budget');
  await expect(page.locator('.app-title')).toHaveText('Budżet');
  await expect(page.locator('.loading')).toHaveCount(0, { timeout: 10_000 });
}

test('budget view renders transactions, categories and the report on a mobile viewport', async ({ page }) => {
  await installBudgetBackend(page);
  await gotoBudget(page);

  await expect(page.locator('.budget-transaction-row')).toHaveCount(1);
  await expect(page.locator('.budget-category-row')).toHaveCount(1);
  await expect(page.locator('.budget-report-row--over')).toHaveCount(1);
  await expect(page.locator('.budget-report-total--balance')).toContainText('-600.00 PLN');
});

test('does not overflow horizontally', async ({ page }) => {
  await installBudgetBackend(page);
  await gotoBudget(page);

  const overflows = await page.evaluate(
    () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
  );
  expect(overflows).toBe(false);
});

test('adding a transaction on mobile re-renders the list', async ({ page }) => {
  await installBudgetBackend(page);
  await gotoBudget(page);

  await page.locator('[data-budget-target="txAmount"]').fill('12.00');
  await page.locator('[data-budget-target="txCategory"]').selectOption('cat-1');
  await page.locator('[data-budget-target="txType"]').selectOption('expense');
  await page.locator('.budget-transaction-form button[type="submit"]').click();

  await expect(page.locator('#info-banner')).toContainText('Transakcja dodana');
  await expect(page.locator('.budget-transaction-row')).toHaveCount(2);
});

test('is reachable from the navigation on mobile', async ({ page }) => {
  await installBudgetBackend(page);
  await page.goto('/');

  await page.getByRole('link', { name: 'Budżet' }).click();

  await expect(page).toHaveURL(/\/budget$/);
  await expect(page.locator('.app-title')).toContainText('Budżet');
});
