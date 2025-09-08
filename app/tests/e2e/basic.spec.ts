import { test, expect } from '@playwright/test';

test('loads home page', async ({ page }) => {
  await page.goto('/');
  await expect(page.getByText('Search')).toBeVisible();
});
