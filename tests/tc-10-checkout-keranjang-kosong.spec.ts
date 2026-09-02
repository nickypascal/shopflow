import { test, expect } from '@playwright/test';

const email = process.env.SHOPFLOW_TEST_EMAIL;
const password = process.env.SHOPFLOW_TEST_PASSWORD;

test('TC-10 - checkout dengan keranjang kosong', async ({ page }) => {
  test.skip(
    !email || !password,
    'SHOPFLOW_TEST_EMAIL dan SHOPFLOW_TEST_PASSWORD belum diatur.',
  );

  await page.goto('login.php');
  await page.locator('input[name="email"]').fill(email!);
  await page.locator('input[name="password"]').fill(password!);
  await page.getByRole('button', { name: 'Masuk sebagai Pengguna' }).click();

  await expect(page.getByText('Keluar', { exact: true })).toBeVisible();

  // Setiap test Playwright menggunakan browser context baru sehingga
  // sesi keranjang dimulai dalam kondisi kosong.
  await page.goto('cart.php');
  await expect(
    page.getByRole('heading', { name: 'Keranjang masih kosong' }),
  ).toBeVisible();

  // Mencoba mengakses proses checkout secara langsung ketika keranjang kosong.
  await page.goto('checkout.php');

  await expect(page).toHaveURL(
    /\/(?:shopflow-php\/)?cart\.php(?:[?#].*)?$/,
  );
  await expect(page.locator('body')).toContainText(
    'Keranjang Anda masih kosong.',
  );
  await expect(
    page.getByRole('link', { name: 'Lanjut ke Checkout' }),
  ).toHaveCount(0);
});
