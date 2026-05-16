import { cookies } from 'next/headers';
import { getIronSession } from 'iron-session';
import { sessionOptions, type SessionData } from '@/lib/session';

export async function serverFetch(path: string): Promise<unknown | null> {
  const session = await getIronSession<SessionData>(await cookies(), sessionOptions);
  const base = process.env.BACKEND_INTERNAL_URL ?? 'http://backend:8000/api/v1';
  const r = await fetch(`${base.replace(/\/$/, '')}${path}`, {
    headers: session.token
      ? { Authorization: `Bearer ${session.token}`, accept: 'application/json' }
      : { accept: 'application/json' },
    cache: 'no-store',
  });
  if (!r.ok) return null;
  return r.json();
}
