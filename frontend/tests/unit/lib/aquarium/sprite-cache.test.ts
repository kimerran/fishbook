import { beforeAll, beforeEach, describe, expect, it } from 'vitest';
import {
  getCachedSprite,
  preloadSprites,
  __resetSpriteCacheForTests,
} from '@/lib/aquarium/sprite-cache';

beforeAll(() => {
  // Mock Image.onload to fire synchronously after .src is set so getTintedSprite resolves.
  Object.defineProperty(globalThis.Image.prototype, 'src', {
    set(value: string) {
      (this as unknown as { _src: string })._src = value;
      queueMicrotask(() => {
        const cb = (this as unknown as { onload?: () => void }).onload;
        cb?.();
      });
    },
    get() {
      return (this as unknown as { _src: string })._src ?? '';
    },
    configurable: true,
  });

  // happy-dom returns null from canvas.getContext('2d'); stub a minimal 2d context.
  const stubCtx = {
    drawImage: () => {},
    fillRect: () => {},
    set globalCompositeOperation(_v: string) {},
    set fillStyle(_v: string) {},
  } as unknown as CanvasRenderingContext2D;
  HTMLCanvasElement.prototype.getContext = function () {
    return stubCtx;
  } as unknown as HTMLCanvasElement['getContext'];
});

beforeEach(() => __resetSpriteCacheForTests());

describe('sprite-cache', () => {
  it('returns null on cache miss synchronously', () => {
    expect(getCachedSprite('guppy', '#FF6B9D')).toBeNull();
  });

  it('caches after preloadSprites', async () => {
    await preloadSprites([{ breed: 'guppy', color_hex: '#FF6B9D' }]);
    expect(getCachedSprite('guppy', '#FF6B9D')).not.toBeNull();
  });

  it('is idempotent across repeated preload calls', async () => {
    await preloadSprites([{ breed: 'guppy', color_hex: '#FF6B9D' }]);
    const first = getCachedSprite('guppy', '#FF6B9D');
    await preloadSprites([{ breed: 'guppy', color_hex: '#FF6B9D' }]);
    expect(getCachedSprite('guppy', '#FF6B9D')).toBe(first);
  });

  it('deduplicates a fish list', async () => {
    await preloadSprites([
      { breed: 'guppy', color_hex: '#FF6B9D' },
      { breed: 'guppy', color_hex: '#FF6B9D' },
      { breed: 'molly', color_hex: '#1F2937' },
    ]);
    expect(getCachedSprite('guppy', '#FF6B9D')).not.toBeNull();
    expect(getCachedSprite('molly', '#1F2937')).not.toBeNull();
  });
});
