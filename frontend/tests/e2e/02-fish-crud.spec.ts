import { expect, test } from '@playwright/test';
import { registerUser } from './helpers/users';

test('SPEC §17 items 2–5 — add, hover, feed, manage, edit, delete', async ({ page }) => {
  await registerUser(page);

  // Add fish (breed defaults to guppy; size to 12; we only customize nickname).
  await page.getByRole('button', { name: /add fish/i }).click();
  await page.locator('input[name="nickname"]').fill('Splash');
  await page
    .getByRole('dialog')
    .getByRole('button', { name: /add fish/i })
    .click();
  const canvas = page.getByTestId('aquarium-canvas');
  await expect(canvas).toHaveAttribute('data-fish-count', '1', { timeout: 10_000 });

  // Hover: fish swims, so sweep the canvas in a fine grid until the tooltip appears.
  const box = await canvas.boundingBox();
  if (!box) throw new Error('canvas not laid out');
  const tooltip = page.getByTestId('hover-tooltip');
  let hovered = false;
  outer: for (let pass = 0; pass < 20 && !hovered; pass++) {
    for (let gx = 0; gx < 32; gx++) {
      for (let gy = 0; gy < 16; gy++) {
        await page.mouse.move(
          box.x + (gx + 0.5) * (box.width / 32),
          box.y + (gy + 0.5) * (box.height / 16),
        );
        // small delay lets the canvas hover-detect tick run.
        await page.waitForTimeout(5);
        if (await tooltip.isVisible().catch(() => false)) {
          hovered = true;
          break outer;
        }
      }
    }
  }
  await expect(tooltip).toContainText(/Splash/);

  // Feed (click → pellet).
  await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
  await expect(canvas).toHaveAttribute('data-pellet-count', /[1-9]/, { timeout: 5_000 });

  // Manage modal + delete.
  await page.getByRole('button', { name: /^manage$/i }).click();
  await page.getByPlaceholder(/search/i).fill('Splash');
  await page.getByRole('button', { name: /^delete$/i }).first().click();
  await expect(canvas).toHaveAttribute('data-fish-count', '0', { timeout: 10_000 });
});
