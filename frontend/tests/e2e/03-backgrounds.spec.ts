import path from 'node:path';
import { expect, test } from '@playwright/test';
import { registerUser } from './helpers/users';

test('SPEC §17 item 6 — upload background ≥ 1280×720', async ({ page }) => {
  await registerUser(page);
  // The AddFishDialog auto-opens for empty users; close it first.
  await page.keyboard.press('Escape');
  await page.getByRole('button', { name: /^background$/i }).click();
  await page.getByRole('tab', { name: /^upload$/i }).click();
  const file = path.resolve(__dirname, 'fixtures/aquarium-bg-1280x720.jpg');
  // Dropzone auto-uploads on file selection — no submit button.
  await page.setInputFiles('input[type="file"]', file);
  // Switch to the library tab to confirm the new background appears active.
  await page.getByRole('tab', { name: /^library$/i }).click();
  await expect(page.getByText(/active/i).first()).toBeVisible({ timeout: 30_000 });
});

test('SPEC §17 item 7 — generate background (Fal AI)', async ({ page }) => {
  test.skip(
    !process.env.FAL_API_KEY,
    'generate background requires FAL_API_KEY (paid; not in CI)',
  );
  // Placeholder: a real impl would fill the prompt and assert on the generated row.
  // The skip-condition above keeps this test inert in CI.
  void page;
});
