import {
  BackgroundResourceFromJSON,
  BackgroundsApi,
  Configuration,
  type BackgroundResourceEnvelope,
} from '@/lib/api-client';

// Reuse the slice 3 proxy-rewrite pattern so the generated client targets /api/proxy/*.
const proxiedFetch: typeof fetch = (input, init) => {
  if (typeof input === 'string' && input.startsWith('/api/v1/')) {
    input = '/api/proxy/' + input.slice('/api/v1/'.length);
  } else if (input instanceof URL && input.pathname.startsWith('/api/v1/')) {
    const u = new URL(input.toString());
    u.pathname = '/api/proxy/' + u.pathname.slice('/api/v1/'.length);
    input = u;
  } else if (input instanceof Request && input.url.includes('/api/v1/')) {
    const newUrl = input.url.replace('/api/v1/', '/api/proxy/');
    input = new Request(newUrl, input);
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
