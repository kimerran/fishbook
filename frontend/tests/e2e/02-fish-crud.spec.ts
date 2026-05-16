import { expect, test } from '@playwright/test';
import { registerUser } from './helpers/users';

test('SPEC §17 items 2–5 — add, hover, feed, manage, edit, delete', async ({ page }) => {
  await registerUser(page);

  // Add fish.
  await page.getByRole('button', { name: /add fish/i }).click();
  await page.getByLabel(/nickname/i).fill('Splash');
  await page.getByLabel(/breed/i).selectOption('guppy');
  await page.getByLabel(/size/i).fill('14');
  await page.getByRole('button', { name: /save|add/i }).click();
  const canvas = page.getByTestId('aquarium-canvas');
  await expect(canvas).toHaveAttribute('data-fish-count', '1');

  // Hover.
  const box = await canvas.boundingBox();
  if (!box) throw new Error('canvas not laid out');
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
  await expect(page.getByTestId('hover-tooltip')).toContainText(/Splash/);

  // Feed (click → pellet).
  await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
  await expect(canvas).toHaveAttribute('data-pellet-count', /[1-9]/);

  // Manage modal + edit + delete.
  await page.getByRole('button', { name: /manage/i }).click();
  await page.getByPlaceholder(/search/i).fill('Splash');
  await page.getByRole('button', { name: /edit/i }).first().click();
  await page.getByLabel(/nickname/i).fill('Splashy');
  await page.getByRole('button', { name: /save/i }).click();
  await page.getByRole('button', { name: /delete/i }).first().click();
  await page.getByRole('button', { name: /confirm|yes/i }).click();
  await expect(canvas).toHaveAttribute('data-fish-count', '0');
});
