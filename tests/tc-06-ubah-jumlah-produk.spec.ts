import { test, expect } from '@playwright/test';

const email = process.env.SHOPFLOW_TEST_EMAIL;
const password = process.env.SHOPFLOW_TEST_PASSWORD;
const productName =
  process.env.SHOPFLOW_TEST_SIMPLE_PRODUCT ?? 'Kursi Kerja Nyaman';

test('TC-06 - mengubah jumlah produk di keranjang', async ({ page }) => {
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

  const cartItem = page
    .locator('.cart-item')
    .filter({ hasText: productName });

  await expect(cartItem).toBeVisible();

  const quantityInput = cartItem.locator('input[name="quantity"]');
  const itemTotal = cartItem.locator('.cart-item-total > strong');

  await expect(quantityInput).toHaveValue('1');
  const totalBefore = (await itemTotal.textContent())?.trim() ?? '';

  await quantityInput.fill('2');
  await cartItem.getByRole('button', { name: 'Perbarui' }).click();

  await expect(page).toHaveURL(
    /\/(?:shopflow-php\/)?cart\.php(?:[?#].*)?$/,
  );
  await expect(page.locator('body')).toContainText('Jumlah produk diperbarui.');

  const updatedCartItem = page
    .locator('.cart-item')
    .filter({ hasText: productName });

  const updatedQuantityInput = updatedCartItem.locator(
    'input[name="quantity"]',
  );
  const updatedItemTotal = updatedCartItem.locator(
    '.cart-item-total > strong',
  );

  await expect(updatedQuantityInput).toHaveValue('2');

  const totalAfter = (await updatedItemTotal.textContent())?.trim() ?? '';
  expect(totalAfter).not.toBe(totalBefore);

  const summarySubtotal = page
    .locator('.summary-card .summary-row')
    .filter({ hasText: 'Subtotal' })
    .first()
    .locator('strong');

  await expect(summarySubtotal).toHaveText(totalAfter);
});
