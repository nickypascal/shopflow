import { test, expect } from '@playwright/test';

const email = process.env.SHOPFLOW_TEST_EMAIL;
const password = process.env.SHOPFLOW_TEST_PASSWORD;

test('TC-01 - login pengguna dengan akun valid', async ({ page }) => {
  test.skip(
    !email || !password,
    'SHOPFLOW_TEST_EMAIL dan SHOPFLOW_TEST_PASSWORD belum diatur.',
  );

  const response = await page.goto('login.php');
  expect(response?.ok()).toBeTruthy();

  await page.locator('input[name="email"]').fill(email!);
  await page.locator('input[name="password"]').fill(password!);
  await page.getByRole('button', { name: 'Masuk sebagai Pengguna' }).click();

  await expect(page).toHaveURL(
    /\/(?:shopflow-php\/)?(?:index\.php)?(?:[?#].*)?$/,
  );
  await expect(page.locator('body')).toContainText('Login pengguna berhasil');
  await expect(page.getByText('Keluar', { exact: true })).toBeVisible();
});
