import { test, expect } from '@playwright/test';

const unavailableProduct =
  process.env.SHOPFLOW_TEST_PRODUCT_UNAVAILABLE ?? 'Produk Tidak Tersedia 999';

test('TC-04 - pencarian produk tidak tersedia', async ({ page }) => {
  const response = await page.goto('index.php');
  expect(response?.ok()).toBeTruthy();

  await page.locator('input[name="q"]').fill(unavailableProduct);
  await page.getByRole('button', { name: 'Cari Produk' }).click();

  await expect(page).toHaveURL(/index\.php\?.*q=/);
  await expect(
    page.getByRole('heading', { name: 'Hasil Pencarian' }),
  ).toBeVisible();
  await expect(page.locator('.result-count')).toHaveText('0 produk ditemukan');
  await expect(
    page.getByRole('heading', { name: 'Produk tidak ditemukan' }),
  ).toBeVisible();
  await expect(
    page.getByRole('link', { name: 'Tampilkan Semua' }),
  ).toBeVisible();
  await expect(page.locator('.product-card')).toHaveCount(0);
});
