import type { FoodPellet } from './FoodPellet';

export type Vec = { x: number; y: number };
export type FishInit = {
  id: string;
  breed: string;
  color_hex: string;
  size: number;
  nickname: string;
  prng: () => number;
  viewport: { w: number; h: number };
  verticalBandPreference: 'bottom' | null;
};

const FEEDING_RADIUS = 200;
const EATING_DISTANCE = 12;
const MAX_ACCEL = 200; // px / s^2
const BASE_MAX_SPEED = 120; // px / s for size=1; scales inversely with size
const TARGET_MIN_MS = 1500;
const TARGET_MAX_MS = 4500;

export class Fish {
  id: string;
  breed: string;
  color_hex: string;
  size: number;
  nickname: string;
  position: Vec;
  velocity: Vec = { x: 0, y: 0 };
  target: Vec;
  nextTargetAt: number;
  bobPhase: number;
  eatingUntil = 0;
  hovered = false;
  readonly maxSpeed: number;
  readonly verticalBandPreference: 'bottom' | null;
  private readonly prng: () => number;

  constructor(init: FishInit) {
    this.id = init.id;
    this.breed = init.breed;
    this.color_hex = init.color_hex;
    this.size = init.size;
    this.nickname = init.nickname;
    this.prng = init.prng;
    this.verticalBandPreference = init.verticalBandPreference;
    this.maxSpeed = BASE_MAX_SPEED * (12 / Math.max(6, init.size));
    this.position = this.randomPoint(init.viewport);
    this.target = this.randomPoint(init.viewport);
    this.nextTargetAt = TARGET_MIN_MS + this.prng() * (TARGET_MAX_MS - TARGET_MIN_MS);
    this.bobPhase = this.prng() * Math.PI * 2;
  }

  private randomPoint(vp: { w: number; h: number }): Vec {
    const x = vp.w * 0.05 + this.prng() * vp.w * 0.9;
    let y = vp.h * 0.05 + this.prng() * vp.h * 0.9;
    if (this.verticalBandPreference === 'bottom') {
      y = vp.h * 0.6 + this.prng() * vp.h * 0.35;
    }
    return { x, y };
  }

  pickNewTarget(vp: { w: number; h: number }) {
    this.target = this.randomPoint(vp);
    this.nextTargetAt = TARGET_MIN_MS + this.prng() * (TARGET_MAX_MS - TARGET_MIN_MS);
  }

  aabb(): { x: number; y: number; w: number; h: number } {
    const w = 2 * this.size + 20;
    const h = this.size + 14;
    return { x: this.position.x - w / 2, y: this.position.y - h / 2, w, h };
  }

  update(dtMs: number, food: FoodPellet[], vp: { w: number; h: number }) {
    const dt = dtMs / 1000;

    // Pick a pellet target if one is close enough.
    let chase: Vec | null = null;
    let bestD2 = FEEDING_RADIUS * FEEDING_RADIUS;
    for (const p of food) {
      if (p.eaten) continue;
      const dx = p.position.x - this.position.x;
      const dy = p.position.y - this.position.y;
      const d2 = dx * dx + dy * dy;
      if (d2 < bestD2) {
        bestD2 = d2;
        chase = p.position;
      }
      if (d2 < EATING_DISTANCE * EATING_DISTANCE) {
        p.eaten = true;
        this.eatingUntil =
          (typeof performance !== 'undefined' ? performance.now() : Date.now()) + 400;
      }
    }
    const aim = chase ?? this.target;

    if (!this.hovered) {
      this.nextTargetAt -= dtMs;
      if (this.nextTargetAt <= 0) this.pickNewTarget(vp);
    }

    // Seek with capped accel.
    const dx = aim.x - this.position.x;
    const dy = aim.y - this.position.y;
    const dist = Math.hypot(dx, dy) || 1;
    const ax = (dx / dist) * MAX_ACCEL;
    const ay = (dy / dist) * MAX_ACCEL;
    this.velocity.x += ax * dt;
    this.velocity.y += ay * dt;

    const speed = Math.hypot(this.velocity.x, this.velocity.y);
    if (speed > this.maxSpeed) {
      this.velocity.x = (this.velocity.x / speed) * this.maxSpeed;
      this.velocity.y = (this.velocity.y / speed) * this.maxSpeed;
    }

    this.position.x += this.velocity.x * dt;
    this.position.y += this.velocity.y * dt + Math.sin(this.bobPhase) * 0.4;
    this.bobPhase += dt * 4;
  }

  render(ctx: CanvasRenderingContext2D, sprite: CanvasImageSource) {
    const flipped = this.velocity.x < 0;
    ctx.save();
    ctx.translate(this.position.x, this.position.y);
    if (flipped) ctx.scale(-1, 1);
    const pulse = this.eatingUntil > performance.now() ? 1.1 : 1;
    const w = (2 * this.size + 20) * pulse;
    const h = (this.size + 14) * pulse;
    ctx.drawImage(sprite, -w / 2, -h / 2, w, h);
    ctx.restore();
  }
}
