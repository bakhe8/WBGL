const { test, expect } = require('@playwright/test');

const e2ePassword = process.env.WBGL_E2E_PASSWORD || 'E2E#WBGL2026!';

async function login(page, username, password) {
  await page.goto('/views/login.php');
  await page.fill('#username', username);
  await page.fill('#password', password);
  await Promise.all([
    page.waitForURL(/\/index\.php(\?.*)?$/),
    page.click('#submit-btn'),
  ]);
}

test.describe('role scope workflow visibility', () => {
  test('data entry keeps full filter surfaces', async ({ page }) => {
    await login(page, 'e2e_data_entry', e2ePassword);

    await expect(page.locator('.status-filter-link.status-filter-link--all')).toBeVisible();
    await expect(page.locator('.status-filter-link.status-filter-link--ready')).toBeVisible();
    await expect(page.locator('.status-filter-link.status-filter-link--actionable')).toBeVisible();
    await expect(page.locator('.status-filter-link.status-filter-link--pending')).toBeVisible();
    await expect(page.locator('.status-filter-link.status-filter-link--released')).toBeVisible();
  });

  test('auditor is task-only and cannot access broad filter controls', async ({ page }) => {
    await login(page, 'e2e_auditor', e2ePassword);

    await page.goto('/index.php?filter=released');

    await expect(page.locator('.status-filter-link')).toHaveCount(0);
    await expect(page.locator('.status-filter-value.status-filter-value--actionable')).toBeVisible();
    await expect(page.locator('.status-filter-link.status-filter-link--released')).toHaveCount(0);
  });
});
