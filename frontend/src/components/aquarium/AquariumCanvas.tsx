'use client';

import { useEffect, useRef } from 'react';
import { Fish } from '@/lib/aquarium/Fish';
import { FoodPellet } from '@/lib/aquarium/FoodPellet';
import { getCachedSprite, preloadSprites } from '@/lib/aquarium/sprite-cache';
import { hashStringToSeed, mulberry32 } from '@/lib/aquarium/seeded-random';
import { useAquariumStore } from '@/stores/aquarium-store';

type FishDTO = {
  id: string;
  breed: string;
  color_hex: string;
  size: number;
  nickname: string;
};
type Breed = { id: string; vertical_band_preference?: 'bottom' | null };

export function AquariumCanvas({
  fishes,
  breeds,
  // readOnly is consumed by surrounding chrome (manager dock, AddFishDialog
  // trigger). Food-drop + hover remain enabled regardless (decision §3.4).
  readOnly: _readOnly = false,
}: {
  fishes: FishDTO[];
  breeds: Breed[];
  readOnly?: boolean;
}) {
  void _readOnly;
  const canvasRef = useRef<HTMLCanvasElement>(null);
  const fishMapRef = useRef(new Map<string, Fish>());
  const pelletsRef = useRef<FoodPellet[]>([]);
  const rafIdRef = useRef<number>(0);
  const lastTimeRef = useRef<number>(0);
  const viewportRef = useRef({ w: 0, h: 0 });

  // Sync server list ↔ internal Fish[]
  useEffect(() => {
    const breedById = new Map(breeds.map((b) => [b.id, b]));
    const seen = new Set<string>();
    for (const dto of fishes) {
      seen.add(dto.id);
      const existing = fishMapRef.current.get(dto.id);
      if (!existing) {
        const b = breedById.get(dto.breed);
        fishMapRef.current.set(
          dto.id,
          new Fish({
            id: dto.id,
            breed: dto.breed,
            color_hex: dto.color_hex,
            size: dto.size,
            nickname: dto.nickname,
            prng: mulberry32(hashStringToSeed(dto.id)),
            viewport: viewportRef.current.w
              ? viewportRef.current
              : { w: window.innerWidth, h: window.innerHeight },
            verticalBandPreference: b?.vertical_band_preference ?? null,
          }),
        );
      } else {
        existing.color_hex = dto.color_hex;
        existing.size = dto.size;
        existing.nickname = dto.nickname;
      }
    }
    for (const id of Array.from(fishMapRef.current.keys())) {
      if (!seen.has(id)) fishMapRef.current.delete(id);
    }
    void preloadSprites(fishes.map((f) => ({ breed: f.breed, color_hex: f.color_hex })));
  }, [fishes, breeds]);

  // RAF loop — refs only.
  useEffect(() => {
    const canvas = canvasRef.current!;
    const ctx = canvas.getContext('2d')!;
    const ro = new ResizeObserver(() => {
      canvas.width = canvas.clientWidth;
      canvas.height = canvas.clientHeight;
      viewportRef.current = { w: canvas.width, h: canvas.height };
    });
    ro.observe(canvas);

    const mq = window.matchMedia('(prefers-reduced-motion: reduce)');
    const isHidden = () => document.visibilityState === 'hidden';

    const drawFallbackCircle = (
      c: CanvasRenderingContext2D,
      f: Fish,
    ): void => {
      c.fillStyle = f.color_hex;
      c.beginPath();
      c.arc(f.position.x, f.position.y, f.size + 4, 0, Math.PI * 2);
      c.fill();
    };

    const tick = (now: number): void => {
      const dt = lastTimeRef.current ? Math.min(50, now - lastTimeRef.current) : 16;
      lastTimeRef.current = now;
      const { paused } = useAquariumStore.getState();

      if (!paused && !mq.matches && !isHidden()) {
        for (const f of fishMapRef.current.values())
          f.update(dt, pelletsRef.current, viewportRef.current);
        for (const p of pelletsRef.current) p.update(dt);
        pelletsRef.current = pelletsRef.current.filter((p) => !p.eaten && !p.isExpired(now));
      }

      ctx.clearRect(0, 0, canvas.width, canvas.height);
      for (const f of fishMapRef.current.values()) {
        const sprite = getCachedSprite(f.breed, f.color_hex);
        if (sprite) {
          f.render(ctx, sprite);
        } else {
          drawFallbackCircle(ctx, f);
        }
      }
      ctx.fillStyle = 'rgba(245, 158, 11, 0.9)';
      for (const p of pelletsRef.current) {
        ctx.beginPath();
        ctx.arc(p.position.x, p.position.y, 3, 0, Math.PI * 2);
        ctx.fill();
      }
      rafIdRef.current = requestAnimationFrame(tick);
    };
    rafIdRef.current = requestAnimationFrame(tick);

    const onMouseDown = (e: MouseEvent) => {
      const r = canvas.getBoundingClientRect();
      const x = e.clientX - r.left;
      const y = e.clientY - r.top;
      pelletsRef.current.push(new FoodPellet({ x, y }));
      useAquariumStore.getState().addFood(x, y);
    };
    const onMouseMove = (e: MouseEvent) => {
      const r = canvas.getBoundingClientRect();
      const x = e.clientX - r.left;
      const y = e.clientY - r.top;
      let hovered: string | null = null;
      for (const f of fishMapRef.current.values()) {
        const a = f.aabb();
        if (x >= a.x && x <= a.x + a.w && y >= a.y && y <= a.y + a.h) {
          hovered = f.id;
          break;
        }
      }
      const prev = useAquariumStore.getState().hoveredFishId;
      if (prev !== hovered) useAquariumStore.getState().setHovered(hovered);
      for (const f of fishMapRef.current.values()) f.hovered = f.id === hovered;
    };
    canvas.addEventListener('mousedown', onMouseDown);
    canvas.addEventListener('mousemove', onMouseMove);

    return () => {
      cancelAnimationFrame(rafIdRef.current);
      ro.disconnect();
      canvas.removeEventListener('mousedown', onMouseDown);
      canvas.removeEventListener('mousemove', onMouseMove);
    };
  }, []);

  return (
    <canvas
      ref={canvasRef}
      className="block w-screen h-screen"
      data-testid="aquarium-canvas"
      aria-hidden="true"
    />
  );
}
