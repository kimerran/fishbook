'use client';

import { useState } from 'react';
import { AquariumCanvas } from '@/components/aquarium/AquariumCanvas';
import { BackgroundLayer } from '@/components/aquarium/BackgroundLayer';
import { HoverTooltip } from '@/components/aquarium/HoverTooltip';
import { AddFishDialog } from '@/components/manage/AddFishDialog';
import { BackgroundPanel } from '@/components/manage/BackgroundPanel';
import { FishManagerModal } from '@/components/manage/FishManagerModal';
import { useBreedsQuery, useFishesQuery } from '@/hooks/use-fish-queries';

export function FishPageClient({ initialEmpty }: { initialEmpty: boolean }) {
  const [manageOpen, setManageOpen] = useState(false);
  const [addOpen, setAddOpen] = useState(initialEmpty);
  const [backgroundOpen, setBackgroundOpen] = useState(false);
  const { data: fishes } = useFishesQuery();
  const { data: breeds } = useBreedsQuery();

  const fishDtos = (fishes?.data ?? []).map((f) => ({
    id: f.id,
    breed: f.breed,
    color_hex: f.colorHex,
    size: f.size,
    nickname: f.nickname,
  }));
  const breedDtos = (breeds?.data ?? []).map((b) => ({
    id: b.id,
    vertical_band_preference: b.verticalBandPreference ?? null,
  }));

  return (
    <>
      <BackgroundLayer />
      <AquariumCanvas fishes={fishDtos} breeds={breedDtos} />
      <HoverTooltip />
      <div className="fixed bottom-6 right-6 flex gap-2 z-10">
        <button
          onClick={() => setAddOpen(true)}
          className="px-6 py-3 rounded-full bg-white/20 border border-white/20 font-label-caps text-[12px] tracking-[0.1em] uppercase"
        >
          Add fish
        </button>
        <button
          onClick={() => setManageOpen(true)}
          className="px-6 py-3 rounded-full bg-white/20 border border-white/20 font-label-caps text-[12px] tracking-[0.1em] uppercase"
        >
          Manage
        </button>
        <button
          onClick={() => setBackgroundOpen(true)}
          className="px-6 py-3 rounded-full bg-primary/30 border border-white/40 text-on-primary-container font-label-caps text-[12px] tracking-[0.1em] uppercase"
        >
          Background
        </button>
      </div>
      <FishManagerModal open={manageOpen} onOpenChange={setManageOpen} />
      <AddFishDialog open={addOpen} onOpenChange={setAddOpen} />
      <BackgroundPanel open={backgroundOpen} onOpenChange={setBackgroundOpen} />
    </>
  );
}
