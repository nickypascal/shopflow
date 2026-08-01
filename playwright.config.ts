import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests',

  // ShopFlow memakai satu database pengujian, sehingga test dijalankan berurutan.
  fullyParallel: false,
  workers: 1,

  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,

  timeout: 30_000,
  expect: {
    timeout: 5_000,
  },

  reporter: [
    ['list'],
    [
      'html',
      {
        outputFolder: 'playwright-report',
        open: 'never',
      },
    ],
  ],

  use: {
    // Lokal: http://localhost/shopflow-php/
    // GitHub Actions: diisi melalui SHOPFLOW_BASE_URL.
    baseURL:
      process.env.SHOPFLOW_BASE_URL ??
      'http://localhost/shopflow-php/',

    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',

    actionTimeout: 10_000,
    navigationTimeout: 15_000,
  },

  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
      },
    },
  ],

  outputDir: 'test-results',
});
