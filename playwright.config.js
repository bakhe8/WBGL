const { defineConfig } = require('@playwright/test');

const baseURL = process.env.WBGL_BASE_URL || 'http://127.0.0.1:8089';

module.exports = defineConfig({
  testDir: './tests/e2e',
  timeout: 30 * 1000,
  expect: {
    timeout: 8 * 1000,
  },
  fullyParallel: true,
  workers: process.env.CI ? 1 : undefined,
  retries: process.env.CI ? 1 : 0,
  reporter: [
    ['list'],
    ['html', { open: 'never' }],
  ],
  use: {
    baseURL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
  },
  webServer: process.env.WBGL_BASE_URL
    ? undefined
    : {
        command: 'php -S 127.0.0.1:8089 -t .',
        url: 'http://127.0.0.1:8089/views/login.php',
        reuseExistingServer: !process.env.CI,
        timeout: 120 * 1000,
      },
});
