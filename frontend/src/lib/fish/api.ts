import { Configuration, FishesApi } from '@/lib/api-client';

// The generated client embeds /api/v1/* in its paths (because the OpenAPI server is
// /api/v1). Route those through the Next.js iron-session proxy at /api/proxy/* — the
// proxy attaches the bearer token to backend calls. We rewrite /api/v1 → /api/proxy
// via a fetchApi shim so the generated paths reach the proxy.
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
export const fishesApi = new FishesApi(config);
