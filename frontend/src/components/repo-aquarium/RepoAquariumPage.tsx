'use client';

import Link from 'next/link';
import { useState } from 'react';
import { AquariumCanvas } from '@/components/aquarium/AquariumCanvas';
import { BackgroundLayer } from '@/components/aquarium/BackgroundLayer';
import { AddFishDialog } from '@/components/manage/AddFishDialog';
import { BackgroundPanel } from '@/components/manage/BackgroundPanel';
import { FishManagerModal } from '@/components/manage/FishManagerModal';
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
  const [manageOpen, setManageOpen] = useState(false);
  const [addOpen, setAddOpen] = useState(false);
  const [backgroundOpen, setBackgroundOpen] = useState(false);

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
      {isAuthed && <BackgroundLayer />}
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
        <div className="mt-3 pt-3 border-t border-white/20 text-xs leading-relaxed">
          <div className="font-medium mb-1">How your aquarium is built</div>
          <ul className="space-y-0.5">
            <li>⭐ → guppies, neons, mollies</li>
            <li>🍴 → zebra danios</li>
            <li>🐛 → otocinclus</li>
            <li>👀 → platy</li>
            <li>👥 → endlers (1 each)</li>
            <li>age → cory catfish</li>
            <li>💬 language tints colors</li>
          </ul>
        </div>
      </aside>

      <div className="fixed bottom-4 right-4 z-30 flex flex-wrap gap-2 justify-end">
        {isAuthed ? (
          <>
            <button
              type="button"
              onClick={() => setAddOpen(true)}
              className="px-6 py-3 rounded-full bg-white/20 border border-white/20 font-label-caps text-[12px] tracking-[0.1em] uppercase"
            >
              Add fish
            </button>
            <button
              type="button"
              onClick={() => setManageOpen(true)}
              className="px-6 py-3 rounded-full bg-white/20 border border-white/20 font-label-caps text-[12px] tracking-[0.1em] uppercase"
            >
              Manage
            </button>
            <button
              type="button"
              onClick={() => setBackgroundOpen(true)}
              className="px-6 py-3 rounded-full bg-primary/30 border border-white/40 text-on-primary-container font-label-caps text-[12px] tracking-[0.1em] uppercase"
            >
              Background
            </button>
            <button
              type="button"
              onClick={onFork}
              disabled={fork.isPending}
              className="glass-md rounded-xl px-4 py-3 hover:bg-white/60 transition-colors"
            >
              {fork.isPending ? 'Forking…' : 'Fork to My Aquarium'}
            </button>
          </>
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

      {isAuthed && (
        <>
          <FishManagerModal open={manageOpen} onOpenChange={setManageOpen} />
          <AddFishDialog open={addOpen} onOpenChange={setAddOpen} />
          <BackgroundPanel open={backgroundOpen} onOpenChange={setBackgroundOpen} />
        </>
      )}
    </div>
  );
}
