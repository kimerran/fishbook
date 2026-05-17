import { Configuration, FishesApi } from '@/lib/api-client';

// Generated client targets the Next.js iron-session proxy at /api/proxy/*. The
// proxy attaches the bearer token and forwards to BACKEND_INTERNAL_URL which
// terminates in /api/v1. Controller path: annotations are bare, so the client
// emits /fishes (etc.) and the final URL becomes /api/proxy/fishes -> backend
// /api/v1/fishes.
const config = new Configuration({ basePath: '/api/proxy' });
export const fishesApi = new FishesApi(config);
