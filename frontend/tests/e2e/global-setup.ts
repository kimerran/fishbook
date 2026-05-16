import waitOn from 'wait-on';

export default async function globalSetup(): Promise<void> {
  await waitOn({
    resources: [
      'http://localhost:3000',
      'http://localhost:8000/api/v1/health',
    ],
    timeout: 120_000,
    interval: 1_000,
  });
}
