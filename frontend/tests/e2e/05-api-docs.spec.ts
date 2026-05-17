import { expect, test } from '@playwright/test';

test('/api-docs renders operations from openapi.json', async ({ page }) => {
  await page.goto('/api-docs');
  // swagger-ui-react renders /fishes path (among others) once the spec loads.
  await expect(page.getByText('/fishes', { exact: false }).first()).toBeVisible({
    timeout: 20_000,
  });
});
