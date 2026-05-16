import { describe, it, expect } from 'vitest';
import { FoodPellet, MAX_LIFETIME_MS } from '@/lib/aquarium/FoodPellet';

describe('FoodPellet', () => {
  it('sinks over time', () => {
    const p = new FoodPellet({ x: 100, y: 100 });
    p.update(100); // 100 ms
    expect(p.position.y).toBeGreaterThan(100);
  });
  it('expires after MAX_LIFETIME_MS', () => {
    const p = new FoodPellet({ x: 0, y: 0 });
    p.createdAt =
      (typeof performance !== 'undefined' ? performance.now() : Date.now()) -
      MAX_LIFETIME_MS -
      1;
    expect(p.isExpired()).toBe(true);
  });
});
