import type { Page } from '@playwright/test';
import stats from '../fixtures/repo-aquarium-stats.json' with { type: 'json' };

export async function mockGithubApi(
  page: Page,
  owner: string,
  repo: string,
): Promise<void> {
  await page.route(`https://api.github.com/repos/${owner}/${repo}`, (route) =>
    route.fulfill({
      status: 200,
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify(stats.repo),
    }),
  );
  await page.route(
    `https://api.github.com/repos/${owner}/${repo}/contributors?per_page=1&anon=true`,
    (route) =>
      route.fulfill({
        status: 200,
        headers: {
          'content-type': 'application/json',
          link: `<https://api.github.com/repositories/x/contributors?per_page=1&page=${stats.contributors_count}>; rel="last"`,
        },
        body: JSON.stringify([stats.contributors_first_entry]),
      }),
  );
}
