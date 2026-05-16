import { expect, test } from '@playwright/test';
import { mockGithubApi } from './helpers/repo-mock';
import { registerUser } from './helpers/users';

test('SPEC §17 items 8–9 — view repo aquarium then fork (authed)', async ({ page }) => {
  await mockGithubApi(page, 'vercel', 'next.js');

  // Unauthed visit.
  await page.goto('/vercel/next.js');
  await expect(page.getByTestId('aquarium-canvas')).toBeVisible();
  await expect(page.getByRole('link', { name: /sign in to fork/i })).toBeVisible();

  // Register, then revisit + fork.
  await registerUser(page);
  await mockGithubApi(page, 'vercel', 'next.js');
  await page.goto('/vercel/next.js');
  await page.getByRole('button', { name: /fork to my aquarium/i }).click();
  await expect(page.getByText(/added \d+ fish/i)).toBeVisible();

  await page.goto('/fish');
  await expect(page.getByTestId('aquarium-canvas')).toHaveAttribute(
    'data-fish-count',
    /[1-9]\d*/,
  );
});
