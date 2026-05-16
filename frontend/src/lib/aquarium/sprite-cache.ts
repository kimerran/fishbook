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

export function clearSpriteCacheForTests() {
  cache.clear();
  imageCache.clear();
}
