const { test, expect } = require('@playwright/test');

test('guest is redirected away from protected view', async ({ page }) => {
  await page.goto('/views/settings.php');
  await expect(page).toHaveURL(/\/views\/login\.php(\?.*)?$/);
});
