import type { Page } from '@playwright/test';
import type { TestUser } from './users';

export async function loginAs(page: Page, user: TestUser): Promise<void> {
  await page.goto('/login');
  await page.getByLabel(/username/i).fill(user.username);
  await page.getByLabel(/password/i).fill(user.password);
  await page.getByRole('button', { name: /log in|sign in/i }).click();
  await page.waitForURL(/\/fish/);
}

export async function logout(page: Page): Promise<void> {
  await page.getByRole('button', { name: /log out|sign out/i }).click();
  await page.waitForURL(/\/(login|$)/);
}
