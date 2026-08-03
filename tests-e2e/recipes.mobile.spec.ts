import { test, expect, type Page } from '@playwright/test';

type Recipe = {
  id: string;
  title: string;
  servings: number;
  prepTimeMinutes: number | null;
  tags: string[];
  ingredients: { name: string; quantity: number; unit: string }[];
  steps: string[];
};

const PANCAKES: Recipe = {
  id: 'rec-1',
  title: 'Naleśniki',
  servings: 4,
  prepTimeMinutes: 30,
  tags: ['obiad'],
  ingredients: [
    { name: 'Mąka', quantity: 500, unit: 'g' },
    { name: 'Mleko', quantity: 250, unit: 'ml' },
  ],
  steps: ['Wymieszaj składniki'],
};

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

async function installRecipesBackend(page: Page, recipes: Recipe[] = [{ ...PANCAKES }]): Promise<void> {
  await page.route(/\/api\/recipes/, async (route) => {
    const url = new URL(route.request().url());
    const respond = (status: number, body?: unknown) =>
      route.fulfill(undefined === body
        ? { status }
        : { status, contentType: 'application/json', body: JSON.stringify(body) });

    if ('/api/recipes' === url.pathname) {
      return respond(200, recipes.map(toListItem));
    }

    const detailMatch = url.pathname.match(/^\/api\/recipes\/([^/]+)$/);
    const recipe = detailMatch ? recipes.find((r) => r.id === detailMatch[1]) : undefined;

    return recipe
      ? respond(200, { ...toListItem(recipe), ingredients: recipe.ingredients, steps: recipe.steps })
      : respond(404, { error: 'Not found.' });
  });
}

test.describe('Recipes — mobile', () => {
  test('renders the catalog on a phone viewport', async ({ page }) => {
    await installRecipesBackend(page);
    await page.goto('/recipes');

    await expect(page.locator('.app-title')).toHaveText('Przepisy');
    await expect(page.locator('.recipe-card')).toHaveCount(1);
  });

  test('opens a detail on a phone viewport', async ({ page }) => {
    await installRecipesBackend(page);
    await page.goto('/recipes');
    await page.locator('.recipe-card').click();

    await expect(page.locator('.recipe-detail')).toContainText('Naleśniki');
    await expect(page.locator('.recipe-ingredient').first()).toHaveText('500 g Mąka');
  });

  test('does not overflow horizontally', async ({ page }) => {
    await installRecipesBackend(page);
    await page.goto('/recipes');
    await expect(page.locator('.recipe-card')).toHaveCount(1);

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
    );
    expect(overflow).toBe(false);
  });

  test('is reachable from the navigation', async ({ page }) => {
    await installRecipesBackend(page);
    await page.goto('/series');

    await page.locator('nav.navbar a[href="/recipes"]').click();
    await expect(page).toHaveURL(/\/recipes$/);
  });
});
