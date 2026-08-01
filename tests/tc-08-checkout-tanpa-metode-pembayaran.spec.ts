import { test, expect } from '@playwright/test';

const email = process.env.SHOPFLOW_TEST_EMAIL;
const password = process.env.SHOPFLOW_TEST_PASSWORD;
const productName =
  process.env.SHOPFLOW_TEST_SIMPLE_PRODUCT ?? 'Kursi Kerja Nyaman';

test('TC-08 - checkout tanpa metode pembayaran', async ({ page }) => {
  test.skip(
    !email || !password,
    'SHOPFLOW_TEST_EMAIL dan SHOPFLOW_TEST_PASSWORD belum diatur.',
  );

  // Login sebagai pelanggan.
  await page.goto('login.php');
  await page.locator('input[name="email"]').fill(email!);
  await page.locator('input[name="password"]').fill(password!);
  await page.getByRole('button', { name: 'Masuk sebagai Pengguna' }).click();

  await expect(page.getByText('Keluar', { exact: true })).toBeVisible();

  // Tambahkan produk ke keranjang.
  await page.goto('index.php');
  await page.locator('input[name="q"]').fill(productName);
  await page.getByRole('button', { name: 'Cari Produk' }).click();

  const productCard = page
    .locator('.product-card')
    .filter({ hasText: productName });

  await expect(productCard).toBeVisible();

  const addToCartButton = productCard.getByRole('button', {
    name: '+ Keranjang',
  });

  await expect(addToCartButton).toBeEnabled();
  await addToCartButton.click();

  await expect(page.locator('body')).toContainText(
    `${productName} ditambahkan ke keranjang.`,
  );

  // Masuk ke halaman checkout.
  await page.goto('cart.php');
  await page.getByRole('link', { name: 'Lanjut ke Checkout' }).click();

  await expect(page).toHaveURL(
    /\/shopflow-php\/checkout\.php(?:[?#].*)?$/,
  );

  // Pastikan metode pembayaran tersedia.
  const paymentInputs = page.locator('input[name="payment_method"]');
  await expect(paymentInputs.first()).toBeVisible();

  // Kosongkan pilihan metode pembayaran hanya pada browser pengujian.
  await page.evaluate(() => {
    document
      .querySelectorAll<HTMLInputElement>('input[name="payment_method"]')
      .forEach((input) => {
        input.checked = false;
      });
  });

  await expect(
    page.locator('input[name="payment_method"]:checked'),
  ).toHaveCount(0);

  const checkoutButton = page.getByRole('button', {
    name: 'Buat Pesanan',
  });

  await checkoutButton.click();

  // Checkout tidak boleh diproses tanpa metode pembayaran.
  await expect(page).toHaveURL(
    /\/shopflow-php\/checkout\.php(?:[?#].*)?$/,
  );

  const paymentMissing = await paymentInputs.first().evaluate(
    (input: HTMLInputElement) => input.validity.valueMissing,
  );

  expect(paymentMissing).toBeTruthy();
  await expect(page.locator('body')).toContainText(productName);
  await expect(page.locator('body')).not.toContainText('Checkout berhasil');
});
