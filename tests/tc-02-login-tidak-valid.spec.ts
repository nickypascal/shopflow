import { test, expect } from '@playwright/test';

const email = process.env.SHOPFLOW_TEST_EMAIL;
const invalidPassword = 'PASSWORD_SALAH_PLAYWRIGHT_2026!';

test('TC-02 - login pengguna dengan kata sandi tidak valid', async ({ page }) => {
  test.skip(
    !email,
    'SHOPFLOW_TEST_EMAIL belum diatur.',
  );

  const response = await page.goto('login.php');
  expect(response?.ok()).toBeTruthy();

  await page.locator('input[name="email"]').fill(email!);
  await page.locator('input[name="password"]').fill(invalidPassword);
  await page.getByRole('button', { name: 'Masuk sebagai Pengguna' }).click();

  await expect(page).toHaveURL(/\/shopflow-php\/login\.php(?:[?#].*)?$/);
  await expect(page.locator('body')).toContainText(
    'Email atau kata sandi pengguna salah.',
  );
  await expect(page.getByText('Keluar', { exact: true })).toHaveCount(0);
});
