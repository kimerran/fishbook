import path from 'node:path';
import { expect, test } from '@playwright/test';
import { registerUser } from './helpers/users';

test('SPEC §17 item 6 — upload background ≥ 1280×720', async ({ page }) => {
  await registerUser(page);
  await page.getByRole('button', { name: /backgrounds/i }).click();
  await page.getByRole('tab', { name: /upload/i }).click();
  const file = path.resolve(__dirname, 'fixtures/aquarium-bg-1280x720.jpg');
  await page.setInputFiles('input[type="file"]', file);
  await page.getByRole('button', { name: /upload/i }).click();
  await expect(page.getByText(/active/i)).toBeVisible();
});

test.skip(
  !process.env.FAL_API_KEY,
  'SPEC §17 item 7 — generate background requires FAL_API_KEY (paid; not in CI)',
);
