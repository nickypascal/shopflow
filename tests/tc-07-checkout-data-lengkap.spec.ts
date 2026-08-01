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

  // Login sebagai pelanggan yang sudah memiliki alamat pengiriman.
  await page.goto('login.php');
  await page.locator('input[name="email"]').fill(email!);
  await page.locator('input[name="password"]').fill(password!);
  await page.getByRole('button', { name: 'Masuk sebagai Pengguna' }).click();

  await expect(page.getByText('Keluar', { exact: true })).toBeVisible();

  // Tambahkan satu produk sederhana ke keranjang.
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

  // Masuk ke checkout.
  await page.goto('cart.php');
  await page.getByRole('link', { name: 'Lanjut ke Checkout' }).click();

  await expect(page).toHaveURL(
    /\/shopflow-php\/checkout\.php(?:[?#].*)?$/,
  );
  await expect(
    page.getByRole('heading', {
      name: 'Lengkapi pengiriman dan pembayaran.',
    }),
  ).toBeVisible();

  // Akun pengujian harus memiliki alamat utama.
  const selectedAddress = page.locator(
    'input[name="address_id"]:checked',
  );
  await expect(selectedAddress).toHaveCount(1);

  // Metode pembayaran bawaan harus sudah terpilih.
  const selectedPayment = page.locator(
    'input[name="payment_method"]:checked',
  );
  await expect(selectedPayment).toHaveCount(1);

  // Tunggu daftar kurir dinamis tampil dan pastikan satu layanan dipilih.
  const selectedShipping = page.locator(
    'input[name="shipping_rate_id"]:checked',
  );
  await expect(selectedShipping).toHaveCount(1, { timeout: 10_000 });

  const checkoutButton = page.getByRole('button', {
    name: 'Buat Pesanan',
  });
  await expect(checkoutButton).toBeEnabled();

  // Proses checkout.
  await checkoutButton.click();

  // Sistem harus menampilkan halaman konfirmasi pesanan.
  await expect(page).toHaveURL(
    /\/shopflow-php\/order_success\.php\?id=\d+$/,
    { timeout: 15_000 },
  );
  await expect(page.locator('body')).toContainText('Checkout berhasil');
  await expect(
    page.getByRole('heading', {
      name: 'Terima kasih! Pesanan Anda sudah dibuat.',
    }),
  ).toBeVisible();
  await expect(page.locator('body')).toContainText('Nomor pesanan');
  await expect(
    page.getByRole('link', { name: 'Lihat Pesanan' }),
  ).toBeVisible();
});
