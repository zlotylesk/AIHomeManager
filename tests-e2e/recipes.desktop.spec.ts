import { test, expect, type Page } from '@playwright/test';

type Ingredient = { name: string; quantity: number; unit: string };

type Recipe = {
  id: string;
  title: string;
  servings: number;
  prepTimeMinutes: number | null;
  tags: string[];
  ingredients: Ingredient[];
  steps: string[];
};

type RecipesState = { recipes: Recipe[]; planned: Set<string> };

const PANCAKES: Recipe = {
  id: 'rec-1',
  title: 'Naleśniki',
  servings: 4,
  prepTimeMinutes: 30,
  tags: ['obiad'],
  ingredients: [
    { name: 'Mąka', quantity: 500, unit: 'g' },
    { name: 'Mleko', quantity: 250, unit: 'ml' },
    { name: 'Jajko', quantity: 2, unit: 'piece' },
    { name: 'Cebula', quantity: 0.5, unit: 'piece' },
  ],
  steps: ['Wymieszaj składniki', 'Usmaż na patelni'],
};

const SOUP: Recipe = {
  id: 'rec-2',
  title: 'Zupa pomidorowa',
  servings: 6,
  prepTimeMinutes: null,
  tags: ['zupa'],
  ingredients: [{ name: 'Pomidory', quantity: 1, unit: 'kg' }],
  steps: [],
};

function defaultState(): RecipesState {
  return { recipes: [{ ...PANCAKES }, { ...SOUP }], planned: new Set<string>() };
}

function toListItem(recipe: Recipe) {
  return {
    id: recipe.id,
    title: recipe.title,
    servings: recipe.servings,
    prepTimeMinutes: recipe.prepTimeMinutes,
    tags: recipe.tags,
    ingredientCount: recipe.ingredients.length,
  };
}

/**
 * Stubs the whole `/api/recipes*` surface behind one route handler
 * (the Budget/Podcasts precedent) — dispatching internally by pathname+method
 * avoids Playwright's registration-order pitfall across several page.route()
 * calls. The `/recipes` page itself is server-rendered, so only the API is
 * faked.
 */
async function installRecipesBackend(page: Page, state: RecipesState = defaultState()): Promise<void> {
  await page.route(/\/api\/recipes/, async (route) => {
    const request = route.request();
    const method = request.method();
    const url = new URL(request.url());
    const path = url.pathname;
    const respond = (status: number, body?: unknown) =>
      route.fulfill(undefined === body
        ? { status }
        : { status, contentType: 'application/json', body: JSON.stringify(body) });

    if ('/api/recipes' === path) {
      if ('POST' === method) {
        const body = request.postDataJSON() as Omit<Recipe, 'id'>;
        const id = `rec-new-${state.recipes.length + 1}`;
        state.recipes.push({ id, ...body });
        return respond(201, { id });
      }

      const tag = url.searchParams.get('tag');
      const phrase = url.searchParams.get('phrase');
      const matching = state.recipes.filter((recipe) => {
        const byTag = !tag || recipe.tags.some((t) => t.toLowerCase() === tag.toLowerCase());
        const byPhrase = !phrase || recipe.title.toLowerCase().includes(phrase.toLowerCase());
        return byTag && byPhrase;
      });

      return respond(200, matching.map(toListItem));
    }

    const detailMatch = path.match(/^\/api\/recipes\/([^/]+)$/);
    if (detailMatch) {
      const id = detailMatch[1];
      const recipe = state.recipes.find((r) => r.id === id);

      if ('DELETE' === method) {
        if (state.planned.has(id)) {
          return respond(409, { error: 'Recipe is planned.' });
        }
        state.recipes = state.recipes.filter((r) => r.id !== id);
        return respond(204);
      }

      if ('PUT' === method) {
        const body = request.postDataJSON() as Omit<Recipe, 'id'>;
        state.recipes = state.recipes.map((r) => (r.id === id ? { id, ...body } : r));
        return respond(204);
      }

      if (!recipe) {
        return respond(404, { error: 'Not found.' });
      }

      return respond(200, { ...toListItem(recipe), ingredients: recipe.ingredients, steps: recipe.steps });
    }

    return respond(404, { error: 'Unhandled.' });
  });
}

test.describe('Recipes — desktop', () => {
  test('lists recipes with their ingredient count, prep time and servings', async ({ page }) => {
    await installRecipesBackend(page);
    await page.goto('/recipes');

    const cards = page.locator('.recipe-card');
    await expect(cards).toHaveCount(2);
    await expect(cards.first()).toContainText('Naleśniki');
    await expect(cards.first()).toContainText('4 składniki · 30 min · 4 porcje');
  });

  test('a recipe without a prep time skips it instead of showing zero', async ({ page }) => {
    await installRecipesBackend(page);
    await page.goto('/recipes');

    const soup = page.locator('.recipe-card', { hasText: 'Zupa pomidorowa' });
    await expect(soup).toContainText('1 składnik · 6 porcji');
    await expect(soup).not.toContainText('0 min');
  });

  test('opens a detail with ingredients formatted per unit', async ({ page }) => {
    await installRecipesBackend(page);
    await page.goto('/recipes');
    await page.locator('.recipe-card', { hasText: 'Naleśniki' }).click();

    const detail = page.locator('.recipe-detail');
    await expect(detail).toContainText('Naleśniki');
    await expect(detail).toContainText('4 porcje · 30 min');
    await expect(detail.locator('.recipe-ingredient').nth(0)).toHaveText('500 g Mąka');
    await expect(detail.locator('.recipe-ingredient').nth(2)).toHaveText('2 szt. Jajko');
    // A recipe states what it states. Rounding half an onion up to a whole one
    // is the SHOPPING rule ("you cannot buy half an egg") and must not leak
    // into the view of the recipe itself — the edit form, which shows the
    // stored value, would go on saying 0.5 while this said 1.
    await expect(detail.locator('.recipe-ingredient').nth(3)).toHaveText('0.5 szt. Cebula');
    await expect(detail.locator('.recipe-step')).toHaveCount(2);
  });

  test('a recipe with no steps says so rather than rendering nothing', async ({ page }) => {
    await installRecipesBackend(page);
    await page.goto('/recipes');
    await page.locator('.recipe-card', { hasText: 'Zupa pomidorowa' }).click();

    await expect(page.locator('.recipe-detail')).toContainText('Bez opisanych kroków.');
  });

  test('creates a recipe with dynamically added ingredient and step rows', async ({ page }) => {
    const state = defaultState();
    await installRecipesBackend(page, state);
    await page.goto('/recipes');

    await page.locator('[data-recipes-target="title"]').fill('Omlet');
    await page.locator('[data-recipes-target="servings"]').fill('2');
    await page.locator('[data-recipes-target="tags"]').fill('śniadanie, szybkie');

    await page.locator('.js-ing-name').first().fill('Jajko');
    await page.locator('.js-ing-quantity').first().fill('3');
    await page.locator('.js-ing-unit').first().selectOption('piece');

    await page.getByRole('button', { name: '+ Składnik' }).click();
    await page.locator('.js-ing-name').nth(1).fill('Masło');
    await page.locator('.js-ing-quantity').nth(1).fill('20');
    await page.locator('.js-ing-unit').nth(1).selectOption('g');

    await page.locator('.js-step-text').first().fill('Roztrzep jajka');
    await page.getByRole('button', { name: '+ Krok' }).click();
    await page.locator('.js-step-text').nth(1).fill('Smaż 3 minuty');

    await page.getByRole('button', { name: 'Zapisz przepis' }).click();

    await expect(page.locator('.recipe-card', { hasText: 'Omlet' })).toBeVisible();

    const created = state.recipes.find((r) => r.title === 'Omlet');
    expect(created).toBeDefined();
    expect(created?.ingredients).toEqual([
      { name: 'Jajko', quantity: 3, unit: 'piece' },
      { name: 'Masło', quantity: 20, unit: 'g' },
    ]);
    expect(created?.steps).toEqual(['Roztrzep jajka', 'Smaż 3 minuty']);
    expect(created?.tags).toEqual(['śniadanie', 'szybkie']);
    expect(created?.servings).toBe(2);
  });

  test('editing pre-fills the form and sends a full replace including servings', async ({ page }) => {
    const state = defaultState();
    await installRecipesBackend(page, state);
    await page.goto('/recipes');

    await page.locator('.recipe-card', { hasText: 'Naleśniki' }).click();
    await page.getByRole('button', { name: '✎ Edytuj' }).click();

    await expect(page.locator('[data-recipes-target="title"]')).toHaveValue('Naleśniki');
    await expect(page.locator('[data-recipes-target="servings"]')).toHaveValue('4');
    await expect(page.locator('.recipe-ingredient-row')).toHaveCount(4);

    await page.locator('[data-recipes-target="title"]').fill('Naleśniki z serem');
    await page.getByRole('button', { name: 'Zapisz przepis' }).click();

    await expect(page.locator('.recipe-card', { hasText: 'Naleśniki z serem' })).toBeVisible();

    const updated = state.recipes.find((r) => r.id === 'rec-1');
    expect(updated?.title).toBe('Naleśniki z serem');
    // The update is a full replace and the API requires servings there, so the
    // form must always send them — a defaulted value would rescale the whole
    // shopping list.
    expect(updated?.servings).toBe(4);
    expect(updated?.ingredients).toHaveLength(4);
  });

  test('filters by tag and by phrase, and tells an empty match from an empty catalog', async ({ page }) => {
    await installRecipesBackend(page);
    await page.goto('/recipes');

    await page.locator('[data-recipes-target="filterTag"]').fill('zupa');
    await page.locator('[data-recipes-target="filterTag"]').blur();
    await expect(page.locator('.recipe-card')).toHaveCount(1);
    await expect(page.locator('.recipe-card')).toContainText('Zupa pomidorowa');

    await page.locator('[data-recipes-target="filterTag"]').fill('nieistniejacy');
    await page.locator('[data-recipes-target="filterTag"]').blur();
    await expect(page.locator('.empty-state')).toContainText('Żaden przepis nie pasuje do filtrów.');

    // Clearing the box is not a search for the empty string.
    await page.locator('[data-recipes-target="filterTag"]').fill('');
    await page.locator('[data-recipes-target="filterTag"]').blur();
    await expect(page.locator('.recipe-card')).toHaveCount(2);
  });

  test('deleting a planned recipe explains the conflict instead of failing generically', async ({ page }) => {
    const state = defaultState();
    state.planned.add('rec-1');
    await installRecipesBackend(page, state);
    page.on('dialog', (dialog) => dialog.accept());

    await page.goto('/recipes');
    await page.locator('.recipe-card', { hasText: 'Naleśniki' }).click();
    await page.getByRole('button', { name: '🗑 Usuń' }).click();

    await expect(page.locator('#error-banner')).toContainText('przepis jest zaplanowany w kalendarzu');
    expect(state.recipes).toHaveLength(2);
  });

  test('deletes an unplanned recipe', async ({ page }) => {
    const state = defaultState();
    await installRecipesBackend(page, state);
    page.on('dialog', (dialog) => dialog.accept());

    await page.goto('/recipes');
    await page.locator('.recipe-card', { hasText: 'Zupa pomidorowa' }).click();
    await page.getByRole('button', { name: '🗑 Usuń' }).click();

    await expect(page.locator('.recipe-card')).toHaveCount(1);
    expect(state.recipes.map((r) => r.id)).toEqual(['rec-1']);
  });

  test('is reachable from the navigation', async ({ page }) => {
    await installRecipesBackend(page);
    await page.goto('/series');

    await page.locator('nav.navbar a[href="/recipes"]').click();
    await expect(page).toHaveURL(/\/recipes$/);
    await expect(page.locator('.app-title')).toHaveText('Przepisy');
  });
});
