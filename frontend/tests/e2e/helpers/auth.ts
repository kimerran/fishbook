import type { Page } from '@playwright/test';
import type { TestUser } from './users';

export async function loginAs(page: Page, user: TestUser): Promise<void> {
  await page.goto('/login');
  await page.locator('input[name="username"]').fill(user.username);
  await page.locator('input[name="password"]').fill(user.password);
  await page.getByRole('button', { name: /log in|sign in/i }).click();
  await page.waitForURL(/\/fish/, { timeout: 15_000 });
}

export async function logout(page: Page): Promise<void> {
  await page.getByRole('button', { name: /log out|sign out/i }).click();
  await page.waitForURL(/\/(login|$)/);
}
