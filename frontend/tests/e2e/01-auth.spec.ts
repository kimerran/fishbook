import { expect, test } from '@playwright/test';
import { registerUser } from './helpers/users';

test('SPEC §17 item 1 — register, log in, see empty aquarium', async ({ page }) => {
  await registerUser(page);
  await expect(page).toHaveURL(/\/fish/);
  const canvas = page.getByTestId('aquarium-canvas');
  await expect(canvas).toBeVisible();
  await expect(canvas).toHaveAttribute('data-fish-count', '0');
});
