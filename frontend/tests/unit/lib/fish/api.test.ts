import { describe, expect, it } from 'vitest';

describe('fish api shim removal', () => {
  it('configures the generated FishesApi with basePath /api/proxy', async () => {
    const mod = await import('@/lib/fish/api');
    // The FishesApi instance must use /api/proxy as its basePath.
    // Access via the configuration that lives on the instance.
    const api = mod.fishesApi as unknown as {
      configuration: { basePath?: string };
    };
    expect(api.configuration.basePath).toBe('/api/proxy');
  });

  it('does not export rewritePath or proxiedFetch', async () => {
    const mod = (await import('@/lib/fish/api')) as Record<string, unknown>;
    expect(mod.rewritePath).toBeUndefined();
    expect(mod.proxiedFetch).toBeUndefined();
  });
});
