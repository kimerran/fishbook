import { Configuration, FishesApi } from '@/lib/api-client';

// Routes through the Next.js iron-session proxy at /api/proxy/* — the proxy attaches
// the bearer token to backend calls. basePath is the proxy root.
const config = new Configuration({ basePath: '/api/proxy' });
export const fishesApi = new FishesApi(config);
