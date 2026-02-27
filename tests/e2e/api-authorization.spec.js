const { test, expect } = require('@playwright/test');

test('guest cannot access protected API endpoint', async ({ request }) => {
  const response = await request.get('/api/me.php', {
    headers: {
      Accept: 'application/json',
    },
  });

  expect(response.status()).toBe(401);
  const payload = await response.json();
  expect(payload.success).toBe(false);
  expect(payload.error).toBe('Unauthorized');
});
