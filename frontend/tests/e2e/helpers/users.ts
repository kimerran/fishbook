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
  await page.getByLabel(/username/i).fill(user.username);
  await page.getByLabel(/email/i).fill(user.email);
  await page.getByLabel(/^password$/i).fill(user.password);
  await page.getByLabel(/confirm/i).fill(user.password);
  await page.getByRole('button', { name: /register|sign up/i }).click();
  await page.waitForURL(/\/(fish|onboarding)/);
  return user;
}
