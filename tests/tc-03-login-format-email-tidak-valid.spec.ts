import { test, expect } from '@playwright/test';

const invalidEmail = 'customer@localhost';
const password = 'PasswordUji123!';

test('TC-03 - login pengguna dengan format email tidak valid', async ({ page }) => {
  const response = await page.goto('login.php');
  expect(response?.ok()).toBeTruthy();

  const emailInput = page.locator('input[name="email"]');
  await emailInput.fill(invalidEmail);

  // Nilai ini lolos validasi dasar input type="email" pada browser,
  // tetapi ditolak oleh validasi FILTER_VALIDATE_EMAIL pada aplikasi.
  await expect(emailInput).toHaveValue(invalidEmail);
  expect(await emailInput.evaluate((element: HTMLInputElement) => element.checkValidity())).toBeTruthy();

  await page.locator('input[name="password"]').fill(password);
  await page.getByRole('button', { name: 'Masuk sebagai Pengguna' }).click();

  await expect(page).toHaveURL(
    /\/(?:shopflow-php\/)?login\.php(?:[?#].*)?$/,
  );
  await expect(page.locator('body')).toContainText(
    'Masukkan alamat email yang valid.',
  );
  await expect(page.getByText('Keluar', { exact: true })).toHaveCount(0);
});
