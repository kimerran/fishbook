import {
  BackgroundResourceFromJSON,
  BackgroundsApi,
  Configuration,
  type BackgroundResourceEnvelope,
} from '@/lib/api-client';

// Generated client targets the Next.js iron-session proxy at /api/proxy/*.
const config = new Configuration({ basePath: '/api/proxy' });
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
