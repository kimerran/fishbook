import { describe, it, expect } from 'vitest';
import { Fish } from '@/lib/aquarium/Fish';
import { mulberry32 } from '@/lib/aquarium/seeded-random';

const vp = { w: 1000, h: 600 };

function mk(opts: Partial<ConstructorParameters<typeof Fish>[0]> = {}) {
  return new Fish({
    id: 'f1',
    breed: 'guppy',
    color_hex: '#FF6B9D',
    size: 12,
    nickname: 'Blub',
    prng: mulberry32(1234),
    viewport: vp,
    verticalBandPreference: null,
    ...opts,
  });
}

describe('Fish', () => {
  it('initializes inside the viewport', () => {
    const f = mk();
    expect(f.position.x).toBeGreaterThanOrEqual(0);
    expect(f.position.x).toBeLessThanOrEqual(vp.w);
  });

  it('is deterministic given the same seed', () => {
    const a = mk();
    const b = mk();
    expect(a.position).toEqual(b.position);
  });

  it('clamps bottom-dweller target.y below 60% of viewport.h', () => {
    const f = mk({ verticalBandPreference: 'bottom' });
    for (let i = 0; i < 50; i++) f.pickNewTarget(vp);
    expect(f.target.y).toBeGreaterThan(vp.h * 0.6);
  });

  it('reports an AABB that contains its position', () => {
    const f = mk();
    const aabb = f.aabb();
    expect(f.position.x).toBeGreaterThanOrEqual(aabb.x);
    expect(f.position.x).toBeLessThanOrEqual(aabb.x + aabb.w);
  });

  it('reduces max speed as size grows', () => {
    const small = mk({ size: 6 });
    const big = mk({ size: 20 });
    expect(small.maxSpeed).toBeGreaterThan(big.maxSpeed);
  });

  it('eats the nearest pellet within feedingRadius', () => {
    const f = mk();
    f.position = { x: 100, y: 100 };
    const pellet = {
      id: 'p1',
      position: { x: 102, y: 100 },
      eaten: false,
      createdAt: 0,
    } as any;
    f.update(16, [pellet], vp);
    expect(pellet.eaten).toBe(true);
    expect(f.eatingUntil).toBeGreaterThan(0);
  });
});
