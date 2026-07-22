import { test, expect, type Page } from '@playwright/test';

/*
 * Installability + offline gate for the PWA (HMAI-350).
 *
 * A green Encore build is NOT proof the PWA works — a bad manifest, an inactive
 * Service Worker or a missing offline fallback all compile fine (the ESM Stimulus
 * bootstrap regression is the standing precedent: green build, dead feature). Only
 * a real browser exercising the real worker against the real server catches that,
 * so these specs opt back into the Service Worker that the default context blocks
 * (HMAI-347) and run on the mobile viewport.
 *
 * The worker is only emitted by a *production* Encore build (`npm run build`),
 * which is exactly what CI serves — so these run in CI, never against a dev build.
 */
test.use({ serviceWorkers: 'allow' });

// Wait until the worker actually *controls* the page (clientsClaim), not merely
// until it is `active`. `registration.active` flips true while the worker is
// still `activating` and precaching the app-shell — reloading in that window
// races `clients.claim()` and lands an *uncontrolled* navigation, so the SW
// never intercepts it. Waiting for a non-null `controller` is the only reliable
// "the SW is now in charge" signal; every cache-populating step comes after it.
async function waitForControllingWorker(page: Page): Promise<void> {
    await page.waitForFunction(
        () => 'serviceWorker' in navigator && Boolean(navigator.serviceWorker.controller),
        undefined,
        { timeout: 30_000 },
    );
}

test('the web app manifest is present and installable-shaped', async ({ page }) => {
    await page.goto('/');

    const href = await page.locator('link[rel="manifest"]').getAttribute('href');
    expect(href).toBeTruthy();

    // Fetched through the API request context, so it bypasses the SW and hits the
    // static file directly.
    const response = await page.request.get(new URL(href as string, page.url()).toString());
    expect(response.ok()).toBeTruthy();

    const manifest = await response.json();
    expect(manifest.name).toBe('AIHomeManager');
    expect(manifest.short_name).toBeTruthy();
    expect(manifest.display).toBe('standalone');
    expect(manifest.start_url).toBe('/');

    const sizes = (manifest.icons ?? []).map((icon: { sizes: string }) => icon.sizes);
    expect(sizes).toContain('192x192');
    expect(sizes).toContain('512x512');
});

test('the service worker registers, activates and controls the page', async ({ page }) => {
    await page.goto('/');

    // A non-null controller proves the worker registered, reached `activated` and
    // claimed this client — the three states the title names, in one signal.
    await waitForControllingWorker(page);

    const info = await page.evaluate(async () => {
        const registration = await navigator.serviceWorker.getRegistration();

        return {
            scope: registration?.scope,
            controlled: Boolean(navigator.serviceWorker.controller),
        };
    });

    expect(info.controlled).toBe(true);
    expect(info.scope).toBe(new URL('/', page.url()).toString());
});

test('a visited view opens offline while an unvisited one shows the offline page', async ({ page, context }) => {
    await page.goto('/');
    await waitForControllingWorker(page);

    // The first navigation loaded *before* the worker took control, so it never
    // went through the SW. This second, now-controlled load lands the cockpit
    // shell in the `pages` runtime cache and its /api/dashboard read in `api-reads`.
    await page.reload();
    await expect(page.locator('.app-title')).toHaveText('Kokpit — na dziś');

    await context.setOffline(true);

    // A previously-visited view still opens from cache — not the browser error page.
    await page.reload();
    await expect(page.locator('.app-title')).toHaveText('Kokpit — na dziś');

    // A never-visited navigation falls through the SW to the precached offline page.
    await page.goto('/tasks');
    await expect(page.locator('.offline-card')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Jesteś offline' })).toBeVisible();

    await context.setOffline(false);
});

test('an offline write is queued (synthetic 202), never lost', async ({ page, context }) => {
    await page.goto('/');
    await waitForControllingWorker(page);

    // The whole offline-write guarantee only exists when the browser exposes
    // Background Sync (the SW's queue+replay trigger); a browser without it is
    // served an honest "needs a connection" 503 instead. Chromium has it, so this
    // proves the queueing branch of HMAI-348 rather than the graceful-degrade one.
    const backgroundSyncSupported = await page.evaluate(async () => {
        const registration = await navigator.serviceWorker.getRegistration();

        return Boolean(registration) && 'sync' in (registration as ServiceWorkerRegistration);
    });
    expect(backgroundSyncSupported).toBe(true);

    await context.setOffline(true);

    // A write that cannot reach the network must never surface as a lost request:
    // the SW enqueues it in Background Sync and answers with the synthetic
    // 202 {queued:true} the page turns into "saved when back online". The payload
    // never reaches the server (the 202 is minted offline), so its shape is moot.
    const queued = await page.evaluate(async () => {
        const response = await fetch('/api/goals', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ type: 'series_episodes_watched', target: 1, period: 'day' }),
        });

        return { status: response.status, body: await response.json() };
    });

    expect(queued.status).toBe(202);
    expect(queued.body.queued).toBe(true);

    await context.setOffline(false);
});
