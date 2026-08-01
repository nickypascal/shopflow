import { test, expect } from '@playwright/test';

const email = process.env.SHOPFLOW_TEST_EMAIL;
const password = process.env.SHOPFLOW_TEST_PASSWORD;
const productName =
  process.env.SHOPFLOW_TEST_SIMPLE_PRODUCT ?? 'Kursi Kerja Nyaman';

test('TC-07 - checkout dengan data lengkap', async ({ page }) => {
  test.skip(
    !email || !password,
    'SHOPFLOW_TEST_EMAIL dan SHOPFLOW_TEST_PASSWORD belum diatur.',
  );

  await page.goto('login.php');
  await page.locator('input[name="email"]').fill(email!);
  await page.locator('input[name="password"]').fill(password!);
  await page.getByRole('button', { name: 'Masuk sebagai Pengguna' }).click();

  await expect(page.getByText('Keluar', { exact: true })).toBeVisible();

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

  await page.goto('cart.php');
  await page.getByRole('link', { name: 'Lanjut ke Checkout' }).click();

  await expect(page).toHaveURL(
    /\/(?:shopflow-php\/)?checkout\.php(?:[?#].*)?$/,
  );
  await expect(
    page.getByRole('heading', {
      name: 'Lengkapi pengiriman dan pembayaran.',
    }),
  ).toBeVisible();

  await expect(
    page.locator('input[name="address_id"]:checked'),
  ).toHaveCount(1);

  await expect(
    page.locator('input[name="payment_method"]:checked'),
  ).toHaveCount(1);

  await expect(
    page.locator('input[name="shipping_rate_id"]:checked'),
  ).toHaveCount(1, { timeout: 10_000 });

  const checkoutButton = page.getByRole('button', {
    name: 'Buat Pesanan',
  });
  await expect(checkoutButton).toBeEnabled();

  await checkoutButton.click();

  await expect(page).toHaveURL(
    /\/(?:shopflow-php\/)?order_success\.php\?id=\d+$/,
    { timeout: 15_000 },
  );
  await expect(page.locator('body')).toContainText('Checkout berhasil');
  await expect(page.locator('body')).toContainText('Nomor pesanan');
});
