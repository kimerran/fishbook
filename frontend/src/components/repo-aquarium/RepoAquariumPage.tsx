'use client';

import Link from 'next/link';
import { useState } from 'react';
import { AquariumCanvas } from '@/components/aquarium/AquariumCanvas';
import { useBreedsQuery } from '@/hooks/use-fish-queries';
import { useForkRepoMutation } from '@/hooks/use-fork-repo-mutation';

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

export function RepoAquariumPage(props: {
  owner: string;
  repo: string;
  stats: Stats;
  fish_set: FishDTO[];
  isAuthed: boolean;
}) {
  const { owner, repo, stats, fish_set, isAuthed } = props;
  const breedsQuery = useBreedsQuery();
  const breeds = (breedsQuery.data?.data ?? []).map((b) => ({
    id: b.id,
    vertical_band_preference: null,
  }));
  const fork = useForkRepoMutation(owner, repo);
  const [toast, setToast] = useState<string | null>(null);

  const onFork = async () => {
    try {
      const r = await fork.mutateAsync();
      setToast(`Added ${r.added} fish to your aquarium`);
    } catch {
      setToast('Could not fork — try again.');
    }
  };

  return (
    <div className="relative w-screen h-screen">
      <AquariumCanvas fishes={fish_set} breeds={breeds} readOnly />

      <aside className="fixed top-4 right-4 z-30 glass-md rounded-xl px-4 py-3 text-sm">
        <div className="font-medium">
          {owner}/{repo}
        </div>
        <div className="mt-1 grid grid-cols-3 gap-x-3 gap-y-1 text-xs">
          <span>⭐ {stats.stars}</span>
          <span>🍴 {stats.forks}</span>
          <span>🐛 {stats.issues}</span>
          <span>👀 {stats.watchers}</span>
          <span>👥 {stats.contributors}</span>
          <span>💬 {stats.language ?? '—'}</span>
        </div>
      </aside>

      <div className="fixed bottom-4 right-4 z-30">
        {isAuthed ? (
          <button
            type="button"
            onClick={onFork}
            disabled={fork.isPending}
            className="glass-md rounded-xl px-4 py-3 hover:bg-white/60 transition-colors"
          >
            {fork.isPending ? 'Forking…' : 'Fork to My Aquarium'}
          </button>
        ) : (
          <Link
            href={`/login?redirect=/${owner}/${repo}`}
            className="glass-md rounded-xl px-4 py-3 inline-block"
          >
            Sign in to fork
          </Link>
        )}
      </div>

      {toast && (
        <div
          role="status"
          className="fixed bottom-20 right-4 z-30 glass-md rounded-lg px-3 py-2 text-sm"
        >
          {toast}
        </div>
      )}
    </div>
  );
}
