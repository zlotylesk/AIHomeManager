import { test, expect, type Page, type Route } from '@playwright/test';

type Movie = {
  id: string;
  title: string;
  watched: boolean;
  watchedAt: string | null;
  rating: number | null;
  coverUrl: string | null;
  year: number | null;
  status: string | null;
  description: string | null;
  createdAt: string;
};

type BackendOptions = { import?: { status: number; body: unknown } };

function json(route: Route, status: number, body: unknown): Promise<void> {
  return route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
}

function noContent(route: Route): Promise<void> {
  return route.fulfill({ status: 204, body: '' });
}

/**
 * A tiny in-memory Movies API so the real Stimulus controller drives the whole
 * flow (create → watched → rating → filtered list → detail → import) against
 * deterministic data, with no MySQL/Trakt dependency. Only `/api/movies*` is
 * intercepted; everything else hits the live app (the /movies page is server-rendered).
 */
async function installMoviesBackend(page: Page, options: BackendOptions = {}): Promise<void> {
  const importResponse = options.import ?? { status: 202, body: { status: 'import_started' } };
  const movies: Movie[] = [];
  let seq = 0;

  await page.route(/\/api\/movies(\?|\/|$)/, async (route) => {
    const request = route.request();
    const method = request.method();
    const url = new URL(request.url());
    const path = url.pathname;
    const payload = (): Record<string, unknown> => JSON.parse(request.postData() ?? '{}');

    if ('/api/movies/import/trakt' === path && 'POST' === method) {
      return json(route, importResponse.status, importResponse.body);
    }

    if ('/api/movies' === path) {
      if ('POST' === method) {
        const data = payload();
        seq += 1;
        const id = `00000000-0000-4000-8000-${String(seq).padStart(12, '0')}`;
        movies.push({
          id,
          title: String(data.title ?? ''),
          watched: false,
          watchedAt: null,
          rating: null,
          coverUrl: (data.coverUrl as string | null) ?? null,
          year: (data.year as number | null) ?? null,
          status: (data.status as string | null) ?? null,
          description: (data.description as string | null) ?? null,
          createdAt: '2026-07-17T10:00:00+00:00',
        });
        return json(route, 201, { id });
      }

      const watched = url.searchParams.get('watched');
      let list = movies;
      if ('true' === watched) list = movies.filter((m) => m.watched);
      if ('false' === watched) list = movies.filter((m) => !m.watched);
      return json(route, 200, list);
    }

    const [id, action] = path.replace('/api/movies/', '').split('/');
    const movie = movies.find((m) => m.id === id);
    if (!movie) {
      return json(route, 404, { error: 'Movie not found.' });
    }

    if ('watched' === action && 'PATCH' === method) {
      movie.watched = Boolean(payload().watched);
      movie.watchedAt = movie.watched ? '2026-07-17T10:05:00+00:00' : null;
      return noContent(route);
    }

    if ('rating' === action && 'PATCH' === method) {
      const raw = payload().rating;
      movie.rating = null === raw ? null : Number(raw);
      return noContent(route);
    }

    if (!action && 'GET' === method) {
      return json(route, 200, movie);
    }

    if (!action && 'PATCH' === method) {
      const data = payload();
      if ('string' === typeof data.title) movie.title = data.title;
      if ('coverUrl' in data) movie.coverUrl = (data.coverUrl as string | null) ?? null;
      if ('year' in data) movie.year = (data.year as number | null) ?? null;
      if ('status' in data) movie.status = (data.status as string | null) ?? null;
      if ('description' in data) movie.description = (data.description as string | null) ?? null;
      return noContent(route);
    }

    if (!action && 'DELETE' === method) {
      movies.splice(movies.findIndex((m) => m.id === id), 1);
      return noContent(route);
    }

    return route.fulfill({ status: 405, body: '' });
  });
}

async function gotoMovies(page: Page): Promise<void> {
  await page.goto('/movies');
  await expect(page.locator('.app-title')).toHaveText('Filmy');
  await expect(page.locator('.loading')).toHaveCount(0, { timeout: 10_000 });
}

async function addMovie(page: Page, title: string, year?: string): Promise<void> {
  await page.locator('[data-action="click->movies#toggleAddForm"]').click();
  await page.locator('[data-movies-target="title"]').fill(title);
  if (year) {
    await page.locator('[data-movies-target="year"]').fill(year);
  }
  await page.locator('.movies-add button[type="submit"]').click();
}

test('shows an empty state when there are no movies', async ({ page }) => {
  await installMoviesBackend(page);
  await gotoMovies(page);

  await expect(page.locator('.empty-state')).toBeVisible();
  await expect(page.locator('.movie-card')).toHaveCount(0);
});

test('add → mark watched + rate → filtered list → detail (full flow)', async ({ page }) => {
  await installMoviesBackend(page);
  await gotoMovies(page);

  // Add a movie — it shows up on the list.
  await addMovie(page, 'Inception', '2010');
  await expect(page.locator('.movie-card')).toHaveCount(1);
  await expect(page.locator('.movie-card-title')).toHaveText('Inception');
  await expect(page.locator('.movie-card')).toContainText('Nieobejrzany');

  // Open its detail.
  await page.locator('.movie-card').click();
  await expect(page.locator('.movie-detail-title')).toHaveText('Inception');

  // Mark watched from the detail view.
  await page.locator('[data-action="click->movies#toggleWatched"]').click();
  await expect(page.locator('.movie-detail-watched')).toContainText('Obejrzany');

  // Rate it 8/10.
  await page.locator('.movie-rating-btn[data-rating="8"]').click();
  await expect(page.locator('.movie-rating-label')).toHaveText('Ocena: 8/10');

  // Back to the list, then narrow to the watched filter — the movie stays visible.
  await page.locator('[data-action="click->movies#backToList"]').click();
  await page.locator('.movie-filter[data-filter="watched"]').click();
  await expect(page.locator('.movie-card')).toHaveCount(1);
  await expect(page.locator('.movie-card')).toContainText('8/10');

  // The unwatched filter now hides it.
  await page.locator('.movie-filter[data-filter="unwatched"]').click();
  await expect(page.locator('.movie-card')).toHaveCount(0);
  await expect(page.locator('.empty-state')).toBeVisible();
});

test('import from Trakt shows a started banner (202)', async ({ page }) => {
  await installMoviesBackend(page);
  await gotoMovies(page);

  await page.locator('[data-action="click->movies#importFromTrakt"]').click();

  const banner = page.locator('#info-banner');
  await expect(banner).toBeVisible();
  await expect(banner).toContainText(/import/i);
});

test('import from Trakt prompts to connect when no token is stored (409)', async ({ page }) => {
  await installMoviesBackend(page, {
    import: { status: 409, body: { error: 'Trakt is not connected.', authUrl: '/auth/trakt' } },
  });
  await gotoMovies(page);

  await page.locator('[data-action="click->movies#importFromTrakt"]').click();

  const banner = page.locator('#error-banner');
  await expect(banner).toBeVisible();
  await expect(banner).toContainText('/auth/trakt');
});
