import { describe, expect, it } from 'vitest';
import { Fish } from '@/lib/aquarium/Fish';

const stableRng = () => 0.5;

function makeFish(hovered: boolean): Fish {
  const f = new Fish({
    id: 'x',
    breed: 'guppy',
    color_hex: '#FF6B9D',
    size: 12,
    nickname: 'X',
    prng: stableRng,
    viewport: { w: 800, h: 600 },
    verticalBandPreference: null,
  });
  f.hovered = hovered;
  // Force a far target so steering tries to max out the speed cap.
  f.target = { x: 10000, y: 10000 };
  return f;
}

describe('Fish hover slow-drift', () => {
  it('caps speed to 15% of maxSpeed when hovered', () => {
    const f = makeFish(true);
    for (let i = 0; i < 60; i++) f.update(16.67, [], { w: 800, h: 600 });
    const speed = Math.hypot(f.velocity.x, f.velocity.y);
    expect(speed).toBeLessThanOrEqual(f.maxSpeed * 0.15 + 0.01);
  });

  it('runs at full maxSpeed when not hovered', () => {
    const f = makeFish(false);
    for (let i = 0; i < 60; i++) f.update(16.67, [], { w: 800, h: 600 });
    const speed = Math.hypot(f.velocity.x, f.velocity.y);
    expect(speed).toBeGreaterThan(f.maxSpeed * 0.5);
  });

  it('still re-picks target when hovered', () => {
    const f = makeFish(true);
    const initialTarget = { ...f.target };
    for (let i = 0; i < 500; i++) f.update(16.67, [], { w: 800, h: 600 });
    expect(f.target).not.toEqual(initialTarget);
  });
});
