import { test, expect } from '@playwright/test';

const email = process.env.SHOPFLOW_TEST_NO_ADDRESS_EMAIL;
const password = process.env.SHOPFLOW_TEST_NO_ADDRESS_PASSWORD;

const productName =
  process.env.SHOPFLOW_TEST_SIMPLE_PRODUCT ?? 'Kursi Kerja Nyaman';

test('TC-08 - checkout tanpa alamat pengiriman', async ({ page }) => {
  test.skip(
    !email || !password,
    'SHOPFLOW_TEST_NO_ADDRESS_EMAIL dan SHOPFLOW_TEST_NO_ADDRESS_PASSWORD belum diatur.',
  );

  await page.goto('login.php');

  await page.locator('input[name="email"]').fill(email!);
  await page.locator('input[name="password"]').fill(password!);

  await page
    .getByRole('button', { name: 'Masuk sebagai Pengguna' })
    .click();

  await page.waitForLoadState('networkidle');


  await expect(page).not.toHaveURL(
    /\/(?:shopflow-php\/)?login\.php(?:[?#].*)?$/,
  );

  await page.goto('index.php');

  await page.locator('input[name="q"]').fill(productName);

  await page
    .getByRole('button', { name: 'Cari Produk' })
    .click();

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

  await page
    .getByRole('link', { name: 'Lanjut ke Checkout' })
    .click();

  await expect(page).toHaveURL(
    /\/(?:shopflow-php\/)?checkout\.php(?:[?#].*)?$/,
  );

  await expect(
    page
      .getByText(
        /alamat belum tersedia|belum memiliki alamat|tambahkan alamat/i,
      )
      .first(),
  ).toBeVisible();

  await expect(
    page.locator('input[name="address_id"]:checked'),
  ).toHaveCount(0);

  const checkoutButton = page.getByRole('button', {
    name: 'Buat Pesanan',
  });

  await expect(checkoutButton).toBeDisabled();

  await expect(page.locator('body')).toContainText(productName);

  await expect(page.locator('body')).not.toContainText(
    /checkout berhasil|pesanan berhasil dibuat/i,
  );
});