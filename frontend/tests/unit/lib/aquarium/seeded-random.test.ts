import { describe, it, expect } from 'vitest';
import { mulberry32, hashStringToSeed } from '@/lib/aquarium/seeded-random';

describe('seeded-random', () => {
  it('is deterministic for the same seed', () => {
    const a = mulberry32(1234);
    const b = mulberry32(1234);
    for (let i = 0; i < 5; i++) expect(a()).toBe(b());
  });
  it('diverges for different seeds', () => {
    expect(mulberry32(1)()).not.toBe(mulberry32(2)());
  });
  it('hashes a stable seed from a string', () => {
    expect(hashStringToSeed('fish-7')).toBe(hashStringToSeed('fish-7'));
    expect(hashStringToSeed('fish-7')).not.toBe(hashStringToSeed('fish-8'));
  });
});
