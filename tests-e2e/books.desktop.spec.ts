import { test, expect, type Page, type APIRequestContext } from '@playwright/test';

const uniqueIsbn = () => {
  const body = `978${String(Date.now()).slice(-6)}${Math.floor(Math.random() * 1000).toString().padStart(3, '0')}`.slice(0, 12);
  let sum = 0;
  for (let i = 0; i < 12; i++) {
    sum += parseInt(body[i], 10) * (i % 2 === 0 ? 1 : 3);
  }
  const check = (10 - (sum % 10)) % 10;
  return body + String(check);
};

const uniqueTitle = (prefix: string) => `${prefix} ${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;

async function seedBook(request: APIRequestContext, overrides: Record<string, unknown> = {}): Promise<{ id: string; title: string; isbn: string }> {
  const isbn = (overrides.isbn as string | undefined) ?? uniqueIsbn();
  const title = (overrides.title as string | undefined) ?? uniqueTitle('E2E Book');
  const payload = {
    isbn,
    title,
    author: 'E2E Author',
    publisher: 'E2E Publisher',
    year: 2024,
    total_pages: 200,
    ...overrides,
  };
  const res = await request.post('/api/books', { data: payload });
  expect(res.ok(), `book seed failed: ${res.status()}`).toBeTruthy();
  const { id } = await res.json();
  return { id, title, isbn };
}

async function gotoBooksList(page: Page): Promise<void> {
  await page.goto('/books');
  await expect(page.locator('.app-title')).toHaveText('Books');
  await expect(page.locator('[data-books-target="list"] .loading')).toHaveCount(0, { timeout: 10_000 });
}

test('list loads and renders seeded book', async ({ page, request }) => {
  const { title } = await seedBook(request);

  await gotoBooksList(page);
  await expect(page.locator('.book-card', { hasText: title })).toBeVisible();
});

test('add book modal opens, cancel closes it without a page reload', async ({ page }) => {
  await gotoBooksList(page);

  const sentinel = Math.random().toString(36).slice(2);
  await page.evaluate((s) => { (window as unknown as Record<string, string>).__e2eSentinel = s; }, sentinel);

  const modal = page.locator('[data-books-target="addBookModal"]');
  await expect(modal).toHaveClass(/hidden/);

  await page.getByRole('button', { name: '+ Add Book' }).click();
  await expect(modal).not.toHaveClass(/hidden/);

  await modal.getByRole('button', { name: 'Cancel' }).click();
  await expect(modal).toHaveClass(/hidden/);

  const survived = await page.evaluate(() => (window as unknown as Record<string, string>).__e2eSentinel);
  expect(survived, 'sentinel must survive — full page reload would clear it').toBe(sentinel);
});

test('API error surfaces in the shared error banner', async ({ page }) => {
  await gotoBooksList(page);

  await page.route('**/api/books', async (route) => {
    if (route.request().method() === 'POST') {
      await route.fulfill({
        status: 422,
        contentType: 'application/json',
        body: JSON.stringify({ error: 'ISBN is malformed.' }),
      });
      return;
    }
    await route.fallback();
  });

  await page.getByRole('button', { name: '+ Add Book' }).click();
  await page.locator('[data-books-target="isbnInput"]').fill('9780000000000');
  await page.locator('[data-books-target="addBookForm"] button[type=submit]').click();

  const banner = page.locator('#error-banner');
  await expect(banner).toBeVisible();
  await expect(banner).toHaveText(/isbn is malformed/i);
});
