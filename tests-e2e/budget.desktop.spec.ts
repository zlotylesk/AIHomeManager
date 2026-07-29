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

type ReportCategory = {
  categoryId: string;
  categoryName: string;
  type: string;
  spentInCents: number;
  monthlyLimitInCents: number | null;
  monthlyLimitCurrency: string | null;
  percentUsed: number | null;
  overLimit: boolean;
};

type Report = {
  month: string;
  totalIncomeInCents: number;
  totalExpensesInCents: number;
  balanceInCents: number;
  categories: ReportCategory[];
};

type BudgetState = { categories: Category[]; transactions: Transaction[]; report: Report };

const CATEGORY_FOOD: Category = { id: 'cat-1', name: 'Jedzenie', type: 'expense', monthlyLimitAmountInCents: 50000, monthlyLimitCurrency: 'PLN' };
const CATEGORY_SALARY: Category = { id: 'cat-2', name: 'Wynagrodzenie', type: 'income', monthlyLimitAmountInCents: null, monthlyLimitCurrency: null };

const TX_GROCERIES: Transaction = { id: 'tx-1', amountInCents: 60000, currency: 'PLN', date: '2026-07-05', categoryId: 'cat-1', type: 'expense', description: 'Zakupy' };
const TX_SALARY: Transaction = { id: 'tx-2', amountInCents: 500000, currency: 'PLN', date: '2026-07-01', categoryId: 'cat-2', type: 'income', description: null };

function defaultReport(): Report {
  return {
    month: '2026-07',
    totalIncomeInCents: 500000,
    totalExpensesInCents: 60000,
    balanceInCents: 440000,
    categories: [
      { categoryId: 'cat-1', categoryName: 'Jedzenie', type: 'expense', spentInCents: 60000, monthlyLimitInCents: 50000, monthlyLimitCurrency: 'PLN', percentUsed: 120, overLimit: true },
      { categoryId: 'cat-2', categoryName: 'Wynagrodzenie', type: 'income', spentInCents: 500000, monthlyLimitInCents: null, monthlyLimitCurrency: null, percentUsed: null, overLimit: false },
    ],
  };
}

function defaultState(): BudgetState {
  return {
    categories: [{ ...CATEGORY_FOOD }, { ...CATEGORY_SALARY }],
    transactions: [{ ...TX_GROCERIES }, { ...TX_SALARY }],
    report: defaultReport(),
  };
}

/**
 * Stubs the whole `/api/budget/*` surface behind one route handler (the
 * Podcasts precedent) — dispatching internally by pathname+method avoids
 * Playwright's registration-order pitfall across several page.route() calls.
 */
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
      if ('POST' === method) {
        const body = request.postDataJSON() as { name: string; type: string };
        const id = `cat-new-${state.categories.length + 1}`;
        state.categories.push({ id, name: body.name, type: body.type, monthlyLimitAmountInCents: null, monthlyLimitCurrency: null });
        return respond(201, { id });
      }
      return respond(200, state.categories);
    }

    const limitMatch = path.match(/^\/api\/budget\/categories\/([^/]+)\/limit$/);
    if (limitMatch) {
      const body = request.postDataJSON() as { amountInCents: number | null; currency: string | null };
      state.categories = state.categories.map((c) =>
        c.id === limitMatch[1] ? { ...c, monthlyLimitAmountInCents: body.amountInCents, monthlyLimitCurrency: body.currency } : c);
      return respond(204);
    }

    const categoryMatch = path.match(/^\/api\/budget\/categories\/([^/]+)$/);
    if (categoryMatch) {
      const id = categoryMatch[1];
      if ('DELETE' === method) {
        state.categories = state.categories.filter((c) => c.id !== id);
        return respond(204);
      }
      if ('PATCH' === method) {
        const body = request.postDataJSON() as { name: string };
        state.categories = state.categories.map((c) => (c.id === id ? { ...c, name: body.name } : c));
        return respond(204);
      }
    }

    if ('/api/budget/transactions' === path) {
      if ('POST' === method) {
        const body = request.postDataJSON() as Omit<Transaction, 'id'>;
        const id = `tx-new-${state.transactions.length + 1}`;
        state.transactions.push({ id, ...body });
        return respond(201, { id });
      }

      let list = state.transactions;
      const categoryId = url.searchParams.get('categoryId');
      const type = url.searchParams.get('type');
      if (categoryId) {
        list = list.filter((t) => t.categoryId === categoryId);
      }
      if (type) {
        list = list.filter((t) => t.type === type);
      }
      return respond(200, list);
    }

    const txMatch = path.match(/^\/api\/budget\/transactions\/([^/]+)$/);
    if (txMatch) {
      const id = txMatch[1];
      if ('DELETE' === method) {
        state.transactions = state.transactions.filter((t) => t.id !== id);
        return respond(204);
      }
      if ('PATCH' === method) {
        const body = request.postDataJSON() as Omit<Transaction, 'id'>;
        state.transactions = state.transactions.map((t) => (t.id === id ? { ...t, ...body } : t));
        return respond(204);
      }
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

test.describe('Budget', () => {
  test('lists transactions and categories from stubbed reads', async ({ page }) => {
    await installBudgetBackend(page);
    await gotoBudget(page);

    await expect(page.locator('.budget-transaction-row')).toHaveCount(2);
    // The mock returns transactions in insertion order (groceries, then salary) —
    // the first row is the groceries transaction, joined to its category name.
    await expect(page.locator('.budget-transaction-row').first()).toContainText('Jedzenie');
    await expect(page.locator('.budget-category-row')).toHaveCount(2);
    await expect(page.locator('.budget-category-row', { hasText: 'Jedzenie' })).toContainText('Limit: 500.00 PLN');
  });

  test('shows the monthly report with totals and highlights an over-limit category', async ({ page }) => {
    await installBudgetBackend(page);
    await gotoBudget(page);

    await expect(page.locator('.budget-report-total--balance')).toContainText('4400.00 PLN');

    const overRow = page.locator('.budget-report-row--over');
    await expect(overRow).toHaveCount(1);
    await expect(overRow).toContainText('Jedzenie');
    await expect(overRow.locator('.budget-report-percent')).toContainText('100%');

    const noLimitRow = page.locator('.budget-report-row', { hasText: 'Wynagrodzenie' });
    await expect(noLimitRow.locator('.budget-report-nolimit')).toContainText('Bez limitu');
  });

  test('filters the transaction list by category', async ({ page }) => {
    await installBudgetBackend(page);
    await gotoBudget(page);

    await page.locator('[data-budget-target="filterCategory"]').selectOption('cat-1');

    await expect(page.locator('.budget-transaction-row')).toHaveCount(1);
    await expect(page.locator('.budget-transaction-row')).toContainText('Zakupy');
  });

  test('adds a new transaction and re-renders the list', async ({ page }) => {
    await installBudgetBackend(page);
    await gotoBudget(page);

    await page.locator('[data-budget-target="txAmount"]').fill('25.50');
    await page.locator('[data-budget-target="txCategory"]').selectOption('cat-1');
    await page.locator('[data-budget-target="txType"]').selectOption('expense');
    await page.locator('[data-budget-target="txDescription"]').fill('Kawa');
    await page.locator('.budget-transaction-form button[type="submit"]').click();

    await expect(page.locator('#info-banner')).toContainText('Transakcja dodana');
    await expect(page.locator('.budget-transaction-row')).toHaveCount(3);
    await expect(page.locator('.budget-transaction-row', { hasText: 'Kawa' })).toContainText('25.50 PLN');
  });

  test('edits a transaction inline', async ({ page }) => {
    await installBudgetBackend(page);
    await gotoBudget(page);

    // A stable data-id selector is used rather than hasText: once the edit
    // form replaces the row's contents with inputs, "Zakupy" is only an
    // input value (not a text node) and a hasText locator would stop matching.
    const row = page.locator('.budget-transaction-row[data-id="tx-1"]');
    await row.locator('.js-tx-edit').click();
    await expect(row.locator('.js-edit-amount')).toHaveValue('600.00');

    await row.locator('.js-edit-amount').fill('75.00');
    await row.locator('.js-edit-description').fill('Zakupy spożywcze');
    await row.getByRole('button', { name: 'Zapisz' }).click();

    await expect(page.locator('#info-banner')).toContainText('zaktualizowana');
    await expect(page.locator('.budget-transaction-row', { hasText: 'Zakupy spożywcze' })).toContainText('75.00 PLN');
  });

  test('deletes a transaction after confirmation', async ({ page }) => {
    await installBudgetBackend(page);
    await gotoBudget(page);

    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('.budget-transaction-row', { hasText: 'Zakupy' }).getByRole('button', { name: 'Usuń' }).click();

    await expect(page.locator('.budget-transaction-row')).toHaveCount(1);
  });

  test('creates a category', async ({ page }) => {
    await installBudgetBackend(page);
    await gotoBudget(page);

    await page.locator('[data-budget-target="categoryName"]').fill('Transport');
    await page.locator('[data-budget-target="categoryType"]').selectOption('expense');
    await page.locator('.budget-category-form button[type="submit"]').click();

    await expect(page.locator('#info-banner')).toContainText('Kategoria dodana');
    await expect(page.locator('.budget-category-row')).toHaveCount(3);
    await expect(page.locator('.budget-category-row', { hasText: 'Transport' })).toBeVisible();
  });

  test('sets a monthly limit for a category with no limit', async ({ page }) => {
    await installBudgetBackend(page);
    await gotoBudget(page);

    // A stable data-id selector survives the row's content being replaced by
    // the edit form (see the transaction-edit test for why hasText cannot).
    const salaryRow = page.locator('.budget-category-row[data-id="cat-2"]');
    await salaryRow.locator('.js-category-edit').click();
    await salaryRow.locator('.js-edit-limit').fill('1000');
    await salaryRow.getByRole('button', { name: 'Zapisz' }).click();

    await expect(salaryRow).toContainText('Limit: 1000.00 PLN');
  });

  test('clears an existing monthly limit', async ({ page }) => {
    await installBudgetBackend(page);
    await gotoBudget(page);

    const foodRow = page.locator('.budget-category-row[data-id="cat-1"]');
    await foodRow.locator('.js-category-edit').click();
    await foodRow.locator('.js-edit-limit').fill('');
    await foodRow.getByRole('button', { name: 'Zapisz' }).click();

    await expect(foodRow).toContainText('Bez limitu');
  });

  test('deletes a category after confirmation', async ({ page }) => {
    await installBudgetBackend(page);
    await gotoBudget(page);

    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('.budget-category-row', { hasText: 'Wynagrodzenie' }).getByRole('button', { name: 'Usuń' }).click();

    await expect(page.locator('.budget-category-row')).toHaveCount(1);
  });

  test('is reachable from the navigation', async ({ page }) => {
    await installBudgetBackend(page);
    await page.goto('/');

    await page.getByRole('link', { name: 'Budżet' }).click();

    await expect(page).toHaveURL(/\/budget$/);
    await expect(page.locator('.app-title')).toContainText('Budżet');
  });
});
