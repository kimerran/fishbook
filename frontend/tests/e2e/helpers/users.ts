import type { Page } from '@playwright/test';

export type TestUser = { username: string; email: string; password: string };

export async function registerUser(
  page: Page,
  overrides: Partial<TestUser> = {},
): Promise<TestUser> {
  const stamp = Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
  const user: TestUser = {
    username: overrides.username ?? `e2e_${stamp}`,
    email: overrides.email ?? `e2e-${stamp}@fishbook.test`,
    password: overrides.password ?? 'CorrectHorseBatteryStaple9!',
  };

  await page.goto('/register');
  await page.locator('input[name="username"]').fill(user.username);
  await page.locator('input[name="email"]').fill(user.email);
  await page.locator('input[name="password"]').fill(user.password);
  await page.locator('input[name="password_confirmation"]').fill(user.password);
  await page.getByRole('button', { name: /register|sign up|create/i }).click();
  await page.waitForURL(/\/(fish|onboarding)/, { timeout: 15_000 });
  return user;
}
