'use client';

import { useAquariumStore } from '@/stores/aquarium-store';
import { useFishesQuery } from '@/hooks/use-fish-queries';
import { useEffect, useState } from 'react';

export function HoverTooltip() {
  const hoveredId = useAquariumStore((s) => s.hoveredFishId);
  const { data } = useFishesQuery();
  const [pos, setPos] = useState<{ x: number; y: number } | null>(null);

  useEffect(() => {
    if (!hoveredId) {
      return;
    }
    const onMove = (e: MouseEvent) => setPos({ x: e.clientX, y: e.clientY });
    window.addEventListener('mousemove', onMove);
    return () => window.removeEventListener('mousemove', onMove);
  }, [hoveredId]);

  if (!hoveredId || !pos) return null;
  const fish = data?.data?.find((f) => f.id === hoveredId);
  if (!fish) return null;

  return (
    <div
      className="pointer-events-none fixed z-50 px-3 py-1 rounded-full bg-white/20 backdrop-blur-md border border-white/20 text-on-surface font-label-caps text-[12px] tracking-[0.1em] uppercase"
      style={{ left: pos.x + 16, top: pos.y + 16 }}
      role="tooltip"
      data-testid="hover-tooltip"
    >
      {fish.nickname}
    </div>
  );
}
