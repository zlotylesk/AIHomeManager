import { test, expect } from '@playwright/test';

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

test('books page renders without horizontal overflow at 393px (Pixel 5)', async ({ page, request }) => {
  const title = uniqueTitle('E2E Mobile Book');
  const seed = await request.post('/api/books', {
    data: {
      isbn: uniqueIsbn(),
      title,
      author: 'Mobile Author',
      publisher: 'Mobile Publisher',
      year: 2024,
      total_pages: 200,
    },
  });
  expect(seed.ok(), `seed failed: ${seed.status()}`).toBeTruthy();

  await page.goto('/books');
  await expect(page.locator('.app-title')).toHaveText('Books');
  await expect(page.locator('.book-card', { hasText: title })).toBeVisible();

  const layout = await page.evaluate(() => {
    const clientWidth = document.documentElement.clientWidth;
    let worst = { sel: '', right: 0 };
    for (const el of document.querySelectorAll<HTMLElement>('body *')) {
      const r = el.getBoundingClientRect();
      if (r.width === 0 || r.height === 0) continue;
      if (r.right > worst.right) {
        worst = { sel: el.tagName.toLowerCase() + (el.className ? '.' + String(el.className).trim().split(/\s+/).join('.') : ''), right: r.right };
      }
    }
    return {
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth,
      worstSel: worst.sel,
      worstRight: Math.round(worst.right * 100) / 100,
    };
  });

  expect(layout.scrollWidth, 'document must not scroll horizontally').toBeLessThanOrEqual(layout.clientWidth);
  expect(layout.worstRight, `"${layout.worstSel}" extends past the ${layout.clientWidth}px viewport`).toBeLessThanOrEqual(layout.clientWidth + 2);
});

test('book detail view renders without horizontal overflow at 393px (Pixel 5)', async ({ page, request }) => {
  const title = uniqueTitle('E2E Mobile Detail');
  const seed = await request.post('/api/books', {
    data: {
      isbn: uniqueIsbn(),
      title,
      author: 'Mobile Author',
      publisher: 'Mobile Publisher',
      year: 2024,
      total_pages: 300,
    },
  });
  expect(seed.ok(), `seed failed: ${seed.status()}`).toBeTruthy();
  const { id } = await seed.json();

  const session = await request.post(`/api/books/${id}/reading-sessions`, {
    data: { pages_read: 50, date: '2025-03-15', notes: 'A fairly long note to stress the table layout on mobile' },
  });
  expect(session.ok(), `session failed: ${session.status()}`).toBeTruthy();

  await page.goto('/books');
  await expect(page.locator('.app-title')).toHaveText('Books');
  await page.locator('.book-card', { hasText: title }).getByRole('button', { name: 'View' }).click();
  await expect(page.locator('.book-detail-title')).toHaveText(title);

  const layout = await page.evaluate(() => {
    const clientWidth = document.documentElement.clientWidth;
    let worst = { sel: '', right: 0 };
    for (const el of document.querySelectorAll<HTMLElement>('body *')) {
      const r = el.getBoundingClientRect();
      if (r.width === 0 || r.height === 0) continue;
      if (r.right > worst.right) {
        worst = { sel: el.tagName.toLowerCase() + (el.className ? '.' + String(el.className).trim().split(/\s+/).join('.') : ''), right: r.right };
      }
    }
    return {
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth,
      worstSel: worst.sel,
      worstRight: Math.round(worst.right * 100) / 100,
    };
  });

  expect(layout.scrollWidth, 'document must not scroll horizontally').toBeLessThanOrEqual(layout.clientWidth);
  expect(layout.worstRight, `"${layout.worstSel}" extends past the ${layout.clientWidth}px viewport`).toBeLessThanOrEqual(layout.clientWidth + 2);
});
