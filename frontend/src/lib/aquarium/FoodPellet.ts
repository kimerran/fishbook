export const MAX_LIFETIME_MS = 10_000;
const SINK_VY = 30; // px / s

export class FoodPellet {
  id: string;
  position: { x: number; y: number };
  eaten = false;
  createdAt: number;

  constructor(at: { x: number; y: number }) {
    this.id = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
    this.position = { x: at.x, y: at.y };
    this.createdAt = typeof performance !== 'undefined' ? performance.now() : Date.now();
  }

  update(dtMs: number) {
    this.position.y += SINK_VY * (dtMs / 1000);
  }

  isExpired(now = performance.now()) {
    return now - this.createdAt > MAX_LIFETIME_MS;
  }
}
