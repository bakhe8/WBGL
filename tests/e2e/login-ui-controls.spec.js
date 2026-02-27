const { test, expect } = require('@playwright/test');

test('login shell exposes locale/direction/theme controls', async ({ page }) => {
  await page.goto('/views/login.php');

  await expect(page.locator('[data-wbgl-lang-toggle]')).toBeVisible();
  await expect(page.locator('[data-wbgl-direction-toggle]')).toBeVisible();
  await expect(page.locator('[data-wbgl-theme-toggle]')).toBeVisible();
});
