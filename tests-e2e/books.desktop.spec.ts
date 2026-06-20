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

test('book detail view shows metadata and reading session history, back returns to list', async ({ page, request }) => {
  const { id, title } = await seedBook(request, { total_pages: 300 });

  const sessionRes = await request.post(`/api/books/${id}/reading-sessions`, {
    data: { pages_read: 42, date: '2025-03-15', notes: 'Chapter one' },
  });
  expect(sessionRes.ok(), `session log failed: ${sessionRes.status()}`).toBeTruthy();

  await gotoBooksList(page);

  const card = page.locator('.book-card', { hasText: title });
  await expect(card).toBeVisible();
  await card.getByRole('button', { name: 'View' }).click();

  const detail = page.locator('[data-books-target="detailView"]');
  await expect(detail).toBeVisible();
  await expect(detail.locator('.book-detail-title')).toHaveText(title);

  const sessionRow = detail.locator('.book-sessions-table tbody tr', { hasText: 'Chapter one' });
  await expect(sessionRow).toBeVisible();
  await expect(sessionRow).toContainText('2025-03-15');
  await expect(sessionRow).toContainText('42');

  await expect(page.locator('[data-books-target="list"]')).toBeHidden();

  await detail.getByRole('button', { name: /Back to list/ }).click();
  await expect(detail).toBeHidden();
  await expect(page.locator('[data-books-target="list"]')).toBeVisible();
});

test('edit book details from the detail view updates the book', async ({ page, request }) => {
  const { title } = await seedBook(request, { total_pages: 200 });

  await gotoBooksList(page);
  await page.locator('.book-card', { hasText: title }).getByRole('button', { name: 'View' }).click();

  const detail = page.locator('[data-books-target="detailView"]');
  await expect(detail.locator('.book-detail-title')).toHaveText(title);

  await detail.getByRole('button', { name: /Edit details/ }).click();
  const editModal = page.locator('[data-books-target="editBookModal"]');
  await expect(editModal).not.toHaveClass(/hidden/);
  await expect(page.locator('[data-books-target="editAuthorInput"]')).toHaveValue('E2E Author');

  const newTitle = `${title} (revised)`;
  await page.locator('[data-books-target="editTitleInput"]').fill(newTitle);
  await page.locator('[data-books-target="editBookForm"] button[type=submit]').click();

  await expect(editModal).toHaveClass(/hidden/);
  await expect(detail.locator('.book-detail-title')).toHaveText(newTitle);
});

test('add book in manual mode creates a book with full details', async ({ page }) => {
  await gotoBooksList(page);

  const isbn = uniqueIsbn();
  const title = uniqueTitle('E2E Manual Book');

  await page.getByRole('button', { name: '+ Add Book' }).click();
  const modal = page.locator('[data-books-target="addBookModal"]');
  await expect(modal).not.toHaveClass(/hidden/);

  const manualFields = page.locator('[data-books-target="manualFields"]');
  await expect(manualFields).toHaveClass(/hidden/);

  await modal.getByRole('radio', { name: /Enter details manually/ }).check();
  await expect(manualFields).not.toHaveClass(/hidden/);

  await page.locator('[data-books-target="isbnInput"]').fill(isbn);
  await page.locator('[data-books-target="titleInput"]').fill(title);
  await page.locator('[data-books-target="authorInput"]').fill('Manual Author');
  await page.locator('[data-books-target="publisherInput"]').fill('Manual Publisher');
  await page.locator('[data-books-target="yearInput"]').fill('2021');
  await page.locator('[data-books-target="totalPagesInput"]').fill('321');
  await page.locator('[data-books-target="addBookForm"] button[type=submit]').click();

  await expect(modal).toHaveClass(/hidden/);
  const card = page.locator('.book-card', { hasText: title });
  await expect(card).toBeVisible();
  await expect(card).toContainText('Manual Author');
});

test('delete book from the detail view removes it and returns to the list', async ({ page, request }) => {
  const { title } = await seedBook(request);

  await gotoBooksList(page);
  await page.locator('.book-card', { hasText: title }).getByRole('button', { name: 'View' }).click();

  const detail = page.locator('[data-books-target="detailView"]');
  await expect(detail.locator('.book-detail-title')).toHaveText(title);

  page.once('dialog', (dialog) => dialog.accept());
  await detail.getByRole('button', { name: /Delete/ }).click();

  await expect(detail).toBeHidden();
  await expect(page.locator('[data-books-target="list"]')).toBeVisible();
  await expect(page.locator('.book-card', { hasText: title })).toHaveCount(0);
});

test('export CSV downloads the books library', async ({ page, request }) => {
  await seedBook(request);
  await gotoBooksList(page);

  const downloadPromise = page.waitForEvent('download');
  await page.getByRole('button', { name: 'Export CSV' }).click();
  const download = await downloadPromise;
  expect(download.suggestedFilename()).toBe('books.csv');
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
