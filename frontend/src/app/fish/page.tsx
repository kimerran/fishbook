import { serverFetch } from '@/lib/server-fetch';
import { FishPageClient } from './_client';

type ListResponse = { data?: unknown[] } | null;

export default async function FishPage() {
  // Server-side existence check to drive the empty-state CTA. The client owns the
  // live cache via TanStack Query (generated client returns camelCase shapes).
  const list = (await serverFetch('/fishes?per_page=1')) as ListResponse;
  const initialEmpty = (list?.data?.length ?? 0) === 0;
  return <FishPageClient initialEmpty={initialEmpty} />;
}
