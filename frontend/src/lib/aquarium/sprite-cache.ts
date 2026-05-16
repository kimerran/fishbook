const cache = new Map<string, HTMLCanvasElement>();
const imageCache = new Map<string, HTMLImageElement>();

function loadImage(src: string): Promise<HTMLImageElement> {
  const hit = imageCache.get(src);
  if (hit) return Promise.resolve(hit);
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => {
      imageCache.set(src, img);
      resolve(img);
    };
    img.onerror = reject;
    img.src = src;
  });
}

export async function getTintedSprite(
  breed: string,
  colorHex: string,
): Promise<HTMLCanvasElement> {
  const key = `${breed}:${colorHex}`;
  const hit = cache.get(key);
  if (hit) return hit;
  const base = await loadImage(`/sprites/fish/${breed}.svg`);
  const off = document.createElement('canvas');
  off.width = base.naturalWidth || 80;
  off.height = base.naturalHeight || 40;
  const c = off.getContext('2d')!;
  c.drawImage(base, 0, 0, off.width, off.height);
  c.globalCompositeOperation = 'source-in';
  c.fillStyle = colorHex;
  c.fillRect(0, 0, off.width, off.height);
  cache.set(key, off);
  return off;
}

export function getCachedSprite(
  breed: string,
  colorHex: string,
): CanvasImageSource | null {
  const key = `${breed}:${colorHex}`;
  return cache.get(key) ?? null;
}

export async function preloadSprites(
  items: ReadonlyArray<{ breed: string; color_hex: string }>,
): Promise<void> {
  const seen = new Set<string>();
  const promises: Array<Promise<unknown>> = [];
  for (const { breed, color_hex } of items) {
    const key = `${breed}:${color_hex}`;
    if (seen.has(key)) continue;
    seen.add(key);
    if (!cache.has(key)) promises.push(getTintedSprite(breed, color_hex));
  }
  await Promise.all(promises);
}

export function clearSpriteCacheForTests() {
  cache.clear();
  imageCache.clear();
}

export function __resetSpriteCacheForTests(): void {
  cache.clear();
  imageCache.clear();
}
