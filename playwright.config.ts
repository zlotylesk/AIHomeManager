import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.E2E_BASE_URL ?? 'http://localhost:8080';
const apiKey = process.env.E2E_API_KEY ?? 'e2e-test-key';

export default defineConfig({
  testDir: './tests-e2e',
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? 'github' : 'list',
  timeout: 30_000,
  expect: { timeout: 5_000 },
  use: {
    baseURL,
    extraHTTPHeaders: { 'X-API-Key': apiKey },
    trace: 'on-first-retry',
    // Block the PWA Service Worker by default (HMAI-347): once it gained a fetch
    // handler it intercepts `/api/*` and navigations, and a controlling worker
    // bypasses these specs' `page.route()` API stubs (the worker fetches from its
    // own context), so they would see real/empty data. The dedicated PWA specs
    // that actually exercise the worker opt back in with
    // `test.use({ serviceWorkers: 'allow' })` (HMAI-350).
    serviceWorkers: 'block',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'desktop-chromium',
      testMatch: /.*\.desktop\.spec\.ts$/,
      use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } },
    },
    {
      name: 'mobile-chromium',
      testMatch: /.*\.mobile\.spec\.ts$/,
      use: { ...devices['Pixel 5'] },
    },
  ],
});
