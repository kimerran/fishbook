import { Configuration, FishesApi } from '@/lib/api-client';

// The generated client embeds /api/v1/* in its paths (because the OpenAPI server is
// /api/v1). Route those through the Next.js iron-session proxy at /api/proxy/* — the
// proxy attaches the bearer token to backend calls. We rewrite /api/v1 → /api/proxy
// via a fetchApi shim so the generated paths reach the proxy.
// The generated client concatenates BASE_PATH (/api/v1) + path (/api/v1/foo) producing
// /api/v1/api/v1/foo. Rewrite both to a single /api/proxy/foo so the proxy reaches the
// backend at /api/v1/foo correctly.
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
export const fishesApi = new FishesApi(config);
