import {
  BackgroundResourceFromJSON,
  BackgroundsApi,
  Configuration,
  type BackgroundResourceEnvelope,
} from '@/lib/api-client';

// Reuse the slice 3 proxy-rewrite pattern so the generated client targets /api/proxy/*.
// The generated client concatenates BASE_PATH + path producing a double /api/v1/api/v1
// prefix. Collapse both to a single /api/proxy so the upstream proxy lands at /api/v1/*.
const rewritePath = (p: string): string => {
  let out = p;
  while (out.startsWith('/api/v1/')) out = '/' + out.slice('/api/v1/'.length);
  return '/api/proxy' + out;
};

const proxiedFetch: typeof fetch = (input, init) => {
  if (typeof input === 'string' && input.startsWith('/api/v1/')) {
    input = rewritePath(input);
  } else if (input instanceof URL && input.pathname.startsWith('/api/v1/')) {
    const u = new URL(input.toString());
    u.pathname = rewritePath(u.pathname);
    input = u;
  } else if (input instanceof Request && input.url.includes('/api/v1/')) {
    const u = new URL(input.url);
    u.pathname = rewritePath(u.pathname);
    input = new Request(u.toString(), input);
  }
  return fetch(input, init);
};

const config = new Configuration({ fetchApi: proxiedFetch });
export const backgroundsApi = new BackgroundsApi(config);

// The generated multipart upload through typescript-fetch is awkward; bypass it
// for the upload endpoint and post FormData directly through the proxy. The
// browser sets Content-Type with the multipart boundary automatically — do NOT
// set it manually.
export async function uploadBackground(
  file: File,
): Promise<BackgroundResourceEnvelope> {
  const fd = new FormData();
  fd.append('image', file);
  const r = await fetch('/api/proxy/backgrounds/upload', {
    method: 'POST',
    body: fd,
  });
  if (!r.ok) {
    let message = `Upload failed (${r.status})`;
    try {
      const body = (await r.json()) as { message?: string };
      if (body?.message) message = body.message;
    } catch {
      /* ignore */
    }
    throw new Error(message);
  }
  const body = (await r.json()) as { data: unknown };
  return { data: BackgroundResourceFromJSON(body.data) } as BackgroundResourceEnvelope;
}
