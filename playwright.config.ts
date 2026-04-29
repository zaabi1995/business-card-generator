import { defineConfig, devices } from '@playwright/test';

/**
 * Cardify E2E config — runs against the BASE_URL env var.
 * Defaults to production. Override for staging: BASE_URL=https://staging...
 */
export default defineConfig({
  testDir: './tests/e2e',
  timeout: 30_000,
  expect: { timeout: 10_000 },
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: process.env.CI ? [['list'], ['github']] : 'list',
  use: {
    baseURL: process.env.BASE_URL || 'https://cardify.om',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    ignoreHTTPSErrors: true,
    userAgent:
      'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 ' +
      '(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 CardifyE2E/1.0',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
    {
      // Cat U action 487 — Safari iOS latest. Playwright's webkit engine
      // tracks current Safari, so running this project exercises the
      // same rendering + JS pipeline iPhone users see.
      // Opt-in: `npx playwright test --project="Safari iOS"`.
      name: 'Safari iOS',
      use: { ...devices['iPhone 14'] },
    },
    {
      // Cat U action 488 — Chrome Android. Pixel 7 UA + 412×915 vp.
      name: 'Chrome Android',
      use: { ...devices['Pixel 7'] },
    },
  ],
});
