// Dynamic route: /{username}/{repo} for the public GitHub Repo Aquarium.
//
// Reserved-name interaction: Next.js routes static beats dynamic, so this
// segment will NEVER receive any of these path values at the *first* segment:
//   login, register, fish, onboarding, api, api-docs, _next, favicon.ico,
//   robots.txt, sitemap.xml.
// Backend defends in depth via RepoAquariumService::isReservedRoute() which
// returns 404 (not 400) for the same denylist plus `auth`, `admin`.
//
// We call the backend DIRECTLY via BACKEND_INTERNAL_URL (no iron-session proxy
// hop): this endpoint is public, no bearer is needed (decision §3.3).
import { cookies } from 'next/headers';
import { getIronSession } from 'iron-session';
import { notFound } from 'next/navigation';
import { isValidPathSegment } from '@/lib/repo-aquarium/validate';
import { sessionOptions, type SessionData } from '@/lib/session';
import { RepoAquariumPage } from '@/components/repo-aquarium/RepoAquariumPage';

type Params = { username: string; repo: string };

type Stats = {
  stars: number;
  forks: number;
  issues: number;
  watchers: number;
  contributors: number;
  language: string | null;
  age_days: number;
  fetched_at: string;
};
type FishDTO = {
  id: string;
  breed: string;
  color_hex: string;
  size: number;
  nickname: string;
  source: string;
  source_ref: string;
};

export default async function Page({ params }: { params: Promise<Params> }) {
  const { username, repo } = await params;
  if (!isValidPathSegment(username) || !isValidPathSegment(repo)) {
    notFound();
  }

  const base =
    process.env.BACKEND_INTERNAL_URL ?? 'http://backend:8000/api/v1';
  const url = `${base.replace(/\/$/, '')}/repos/${username}/${repo}/aquarium`;
  const res = await fetch(url, {
    cache: 'no-store',
    headers: { accept: 'application/json' },
  });

  if (res.status === 404) {
    notFound();
  }
  if (res.status === 403) {
    return (
      <main className="min-h-screen grid place-items-center">
        <div className="glass-md rounded-xl p-6 max-w-md text-center">
          This repository is private or temporarily unavailable.
        </div>
      </main>
    );
  }
  if (!res.ok) {
    throw new Error(`backend responded ${res.status}`);
  }

  const body = (await res.json()) as {
    data: { stats: Stats; fish_set: FishDTO[] };
  };
  const session = await getIronSession<SessionData>(
    await cookies(),
    sessionOptions,
  );
  const isAuthed = !!session?.token;

  return (
    <RepoAquariumPage
      owner={username}
      repo={repo}
      stats={body.data.stats}
      fish_set={body.data.fish_set}
      isAuthed={isAuthed}
    />
  );
}
