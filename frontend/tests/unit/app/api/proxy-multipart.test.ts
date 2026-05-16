import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('next/headers', () => ({
  cookies: async () => ({
    get: () => undefined,
    set: () => undefined,
    delete: () => undefined,
  }),
}));
vi.mock('iron-session', () => ({
  getIronSession: async () => ({ token: 'tok' }),
}));

describe('proxy multipart pass-through', () => {
  beforeEach(() => vi.resetModules());

  it('preserves Content-Type and body bytes', async () => {
    const fd = new FormData();
    fd.append('image', new File(['hello world bytes'], 'a.jpg', { type: 'image/jpeg' }));

    const req = new Request('http://localhost:3000/api/proxy/backgrounds/upload', {
      method: 'POST',
      body: fd,
    });
    const ct = req.headers.get('content-type') ?? '';
    expect(ct).toContain('multipart/form-data');

    const upstream = vi
      .fn()
      .mockResolvedValue(
        new Response('{}', { status: 201, headers: { 'content-type': 'application/json' } }),
      );
    vi.stubGlobal('fetch', upstream);

    const mod = (await import('@/app/api/proxy/[...path]/route')) as {
      POST: (
        r: Request,
        ctx: { params: Promise<{ path: string[] }> },
      ) => Promise<Response>;
    };
    await mod.POST(req, {
      params: Promise.resolve({ path: ['backgrounds', 'upload'] }),
    });

    expect(upstream).toHaveBeenCalled();
    const call = upstream.mock.calls[0] as [string, RequestInit & { headers: Record<string, string> }];
    const init = call[1];
    const hdrs = init.headers as Record<string, string>;
    const lookup = (key: string) => {
      const want = key.toLowerCase();
      for (const k of Object.keys(hdrs)) if (k.toLowerCase() === want) return hdrs[k];
      return undefined;
    };
    expect(lookup('content-type')).toContain('multipart/form-data');
    expect(lookup('authorization')).toBe('Bearer tok');
    expect((init.body as ArrayBuffer).byteLength).toBeGreaterThan(0);
  });
});
