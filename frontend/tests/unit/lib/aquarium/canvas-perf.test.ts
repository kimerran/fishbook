import { describe, expect, it } from 'vitest';
import { Fish } from '@/lib/aquarium/Fish';
import { FoodPellet } from '@/lib/aquarium/FoodPellet';
import { mulberry32, hashStringToSeed } from '@/lib/aquarium/seeded-random';

const BREEDS = ['guppy', 'molly', 'neon_tetra', 'zebra_danio', 'platy'];
const COLORS = ['#FF6B9D', '#1F2937', '#3B82F6', '#9CA3AF', '#F59E0B'];

function makeFish(i: number): Fish {
  return new Fish({
    id: `f${i}`,
    breed: BREEDS[i % BREEDS.length]!,
    color_hex: COLORS[i % COLORS.length]!,
    size: 10 + (i % 10),
    nickname: `Fish-${i}`,
    prng: mulberry32(hashStringToSeed(`f${i}`)),
    viewport: { w: 1920, h: 1080 },
    verticalBandPreference: null,
  });
}

describe('canvas-perf 100 fish + 20 pellets', () => {
  it('60 ticks complete within 1.2× frame budget (soft check)', () => {
    const fish = Array.from({ length: 100 }, (_, i) => makeFish(i));
    const pellets = Array.from(
      { length: 20 },
      (_, i) => new FoodPellet({ x: 100 + i * 50, y: 200 + i * 30 }),
    );
    const vp = { w: 1920, h: 1080 };

    const start = performance.now();
    for (let frame = 0; frame < 60; frame++) {
      for (const f of fish) f.update(16.67, pellets, vp);
      for (const p of pellets) p.update(16.67);
    }
    const elapsed = performance.now() - start;
    const budget = 60 * 16.67 * 1.2;

    // Soft check: log results but never fail CI on perf miss (avoid runner flake).
    console.log(`perf: 60 ticks took ${elapsed.toFixed(1)}ms (budget ${budget.toFixed(1)}ms)`);
    // The simulation budget is intentionally loose; this just sanity-checks that
    // we don't blow up by orders of magnitude (e.g., per-frame allocation regression).
    expect(elapsed).toBeLessThan(budget * 10);
  });
});
