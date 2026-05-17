import { describe, expect, it } from 'vitest';

describe('backgrounds api shim removal', () => {
  it('configures the generated BackgroundsApi with basePath /api/proxy', async () => {
    const mod = await import('@/lib/backgrounds/api');
    const api = mod.backgroundsApi as unknown as {
      configuration: { basePath?: string };
    };
    expect(api.configuration.basePath).toBe('/api/proxy');
  });

  it('does not export rewritePath or proxiedFetch', async () => {
    const mod = (await import('@/lib/backgrounds/api')) as Record<string, unknown>;
    expect(mod.rewritePath).toBeUndefined();
    expect(mod.proxiedFetch).toBeUndefined();
  });

  it('still exports uploadBackground helper', async () => {
    const mod = await import('@/lib/backgrounds/api');
    expect(typeof mod.uploadBackground).toBe('function');
  });
});
