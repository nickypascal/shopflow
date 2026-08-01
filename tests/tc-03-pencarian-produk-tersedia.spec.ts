import { test, expect } from '@playwright/test';

const productName =
  process.env.SHOPFLOW_TEST_PRODUCT_AVAILABLE ?? 'Laptop Premium 14';

test('TC-03 - pencarian produk tersedia', async ({ page }) => {
  const response = await page.goto('index.php');
  expect(response?.ok()).toBeTruthy();

  await page.locator('input[name="q"]').fill(productName);
  await page.getByRole('button', { name: 'Cari Produk' }).click();

  await expect(page).toHaveURL(/index\.php\?.*q=/);
  await expect(
    page.getByRole('heading', { name: 'Hasil Pencarian' }),
  ).toBeVisible();
  await expect(page.locator('.result-count')).toContainText('produk ditemukan');
  await expect(
    page.locator('.product-card').filter({ hasText: productName }),
  ).toBeVisible();
});
