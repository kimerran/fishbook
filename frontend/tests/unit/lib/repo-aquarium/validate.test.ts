import { describe, expect, it } from 'vitest';
import { isValidPathSegment } from '@/lib/repo-aquarium/validate';

describe('isValidPathSegment', () => {
  it('accepts valid', () => {
    expect(isValidPathSegment('vercel')).toBe(true);
    expect(isValidPathSegment('next.js')).toBe(true);
    expect(isValidPathSegment('foo_bar-baz.1')).toBe(true);
  });
  it('rejects invalid', () => {
    expect(isValidPathSegment('')).toBe(false);
    expect(isValidPathSegment('a'.repeat(101))).toBe(false);
    expect(isValidPathSegment('foo$bar')).toBe(false);
    expect(isValidPathSegment('a/b')).toBe(false);
    // dot-only allowed by SPEC regex; backend defends in depth via 404
    expect(isValidPathSegment('..')).toBe(true);
  });
});
