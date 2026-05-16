# Fishbook — Slice 3: Fish CRUD + Aquarium Canvas (Design)

**Date:** 2026-05-16
**Slice:** 3 of 7 (Fish CRUD + Canvas)
**Status:** Approved — ready for implementation plan
**Depends on:** Slice 2 (Auth) — `slice-2-auth` tag.

Behaviour governed by [`SPEC.md`](../../../SPEC.md) §1 (routes, layout, fish movement), §2.2 (Fishes API), §2.6 (cross-cutting), §3 (`fishes` table), §4 (breed catalog), §6 (acceptance bits), §12 (testing), §16 (security), §17 (acceptance items 1–7). Engineering practice by [`AGENT.md`](../../../AGENT.md) §3 (frontend canvas rules, no React state in RAF), §4 (security), §5 (testing), §6 (perf budgets). Visual language by [`BRAND.md`](../../../BRAND.md). When this file is ambiguous, those win.

---

## 1. Context

Slice 2 left us with a working auth surface: register, login, `/auth/me`, logout, Google OAuth (gated), iron-session cookie + Next.js proxy, brand-styled `/login` and `/register` pages, and `FishPolicy`/`BackgroundPolicy` **stubs** (every method returns `false`, `viewAny`/`view` return `true`) registered in the providers. `/fish` exists only as a 307 redirect target — the page itself does not render.

Slice 3 makes the full authed `/fish` experience real. After this slice:

- The `fishes` table exists with all SPEC §3 columns, constraints, and indexes.
- A user can `POST/GET/PATCH/DELETE /api/v1/fishes` (owner-scoped via the now-real `FishPolicy`) and `GET /api/v1/fishes/breeds` (public).
- The `/fish` page renders a full-viewport canvas at 60 fps with the user's fish swimming via a deterministic seeded steering model.
- Clicking the canvas drops a `FoodPellet`; the nearest fish swims to it and eats it (visual only — no server round-trip).
- Hovering a fish reveals its nickname in a floating tooltip.
- A "Manage Fishes" glass dock button opens a Radix dialog with search, breed filter, sort, edit, and delete.
- An "Add Fish" dialog with breed cards, color picker, size slider, and nickname input creates a fish that appears on the canvas immediately (optimistic).
- SPEC §17 acceptance items **2, 3, 4, 5** are satisfied; item 1 (empty-state aquarium) is satisfied by the empty-state CTA.

It deliberately stops there. Backgrounds, GitHub-repo aquariums, Playwright E2E for the canvas, Sentry, real performance profiling, and the `/api-docs` Swagger UI page are all later slices.

---

## 2. Scope

### In

**Backend**

- **`config/fish_breeds.php`** — the static catalog from SPEC §4 (10 breeds). Each entry: `id`, `label`, `min_size`, `max_size`, `default_color`, `sprite_key`, optional `vertical_band_preference` (set on `otocinclus`/`cory_catfish`).
- **Migration `2026_05_16_000000_create_fishes_table.php`** — bigserial `id`; `user_id` FK → users(id) `ON DELETE CASCADE`; `nickname varchar(40)`; `breed varchar(40)`; `color_hex char(7)`; `size smallint`; `source varchar(20) NOT NULL DEFAULT 'manual'`; `source_ref varchar(255) NULL`; `timestamps` + `softDeletes`. Indexes: `(user_id, deleted_at)`, `(user_id, breed)`, `(user_id, created_at)`. CHECK constraints (raw `DB::statement`): `color_hex ~ '^#[0-9A-Fa-f]{6}$'`, `size BETWEEN 1 AND 100`, `source IN ('manual','github_repo')`.
- **`Fish` Eloquent model** — `$fillable = ['user_id','nickname','breed','color_hex','size','source','source_ref']`; `$casts = ['size' => 'integer']`; `belongsTo(User::class)`; scope `forUser(int $userId)`. No business logic.
- **`FishFactory`** — random fields drawn from a Faker seeded with `1234` at the start of each test (AGENT.md §5 determinism); uses the `BreedCatalog` to clamp size into the breed's range.
- **`FishResource`** — named OpenAPI schema `FishResource`. Exposes `id` (string-cast for JS bigint safety), `nickname`, `breed`, `color_hex`, `size`, `source`, `source_ref`, `created_at`, `updated_at`.
- **`FishBreedResource`** — named OpenAPI schema. Returned by `/fishes/breeds`.
- **`App\Services\Fish\BreedCatalog`** — reads `config('fish_breeds')`. Methods: `all(): array`, `find(string $id): ?array`, `clampSize(string $breed, int $size): int`, `validate(string $breed, int $size, string $colorHex): array` (returns `[]` on success, `['field' => ['message']]` otherwise). Constructor-injected `Repository $config` for testability.
- **`StoreFishRequest`** — `nickname: required|string|min:1|max:40` (trim via `prepareForValidation`); `breed: required|string` validated against `BreedCatalog::find()` in a `Rule::closure`; `color_hex: required|regex:/^#[0-9A-Fa-f]{6}$/`; `size: required|integer` validated against the breed's min/max (`Rule::closure` reading `$this->input('breed')`).
- **`UpdateFishRequest`** — `nickname/color_hex/size` optional with the same per-field rules; `breed` rejected if present (rule `prohibited`).
- **`FishPolicy`** — fills the stub from slice 2. `viewAny`: `true` (route uses `forUser($me)` scope anyway). `view`: `$user->id === $fish->user_id || $user->is_admin`. `create`: `true`. `update`/`delete`: owner only. Registered in the provider (slice 2 already did this).
- **`FishController`** — five RESTful actions + `breeds`. Implemented as `Route::apiResource('fishes', FishController::class)` with `->only([...])` if needed plus an explicit `Route::get('fishes/breeds', [FishController::class, 'breeds'])->name('fishes.breeds')` placed **before** the resource (Laravel routes are order-sensitive — `/fishes/breeds` would otherwise match `{fish}`). The constructor calls `$this->authorizeResource(Fish::class, 'fish')`. Index applies the SPEC §2.2 query params via a small `FishQuery` value-object (controller-local, ~30 lines).
- **Routes (`routes/api.php`)** — `fishes` resource + `fishes/breeds` under the existing `Route::middleware('auth:sanctum')` group for authed actions; **`fishes/breeds` is public** (per SPEC §2.2 — used by the unauthed `/[username]/[repo]` page in slice 4+) and is registered outside the auth group with `throttle:api`.
- **Pagination** — Laravel's `paginate($perPage)`. Returns standard `data/links/meta`. `per_page` capped at 100 server-side regardless of request.
- **N+1 guardrail** — index feature test wraps the request in `DB::enableQueryLog()` and asserts `count(DB::getQueryLog()) <= 4` (Sanctum user lookup + count query + page query + maybe a session-related touch). Tightened from "no N+1 detector available" to a deterministic upper bound.
- **`fishes/breeds`** — public, no auth, returns `{data: FishBreedResource[]}`. Cache headers: see open question.
- **OpenAPI** — every endpoint annotated with `#[OA\Operation(operationId: 'listFishes'|'createFish'|'getFish'|'updateFish'|'deleteFish'|'listBreeds')]`. Named schemas: `FishResource`, `FishBreedResource`, `StoreFishRequest`, `UpdateFishRequest`, `PaginatedFishCollection`. Regenerated `storage/api-docs/openapi.json` committed.
- **Pest tests** per endpoint: happy path, validation (each field's failure), auth failure (no token → 401), policy failure (other user's id → 403), pagination/filter/sort, immutability of `breed` on PATCH, soft-delete returns 204 + row gone from index + row still in `fishes` table (`whereNotNull('deleted_at')`), `source='manual'` default. Unit tests for `BreedCatalog`. Coverage gate (already in `phpunit.xml` from slice 2) automatically covers the new `app/Services/Fish/` and `app/Http/Controllers/Api/V1/FishController` directories.

**Frontend**

- **New deps:** `@radix-ui/react-dialog` (accessible focus trap, headless), `clsx` (small className helper). No GSAP / Framer / canvas libraries — we own the RAF loop.
- **Sprite assets** — `frontend/public/sprites/fish/{breed_id}.svg`. One side-view profile per breed, ~80×40 viewBox, body filled with `currentColor` so the canvas can recolor by setting `ctx.fillStyle` before drawing via a tinted `<img>` cache (see §5.4). Otocinclus and Cory Catfish are drawn with a flat-belly profile suggesting bottom-dwelling.
- **`useAquariumStore` (zustand)** — *client-only* state, deliberately disjoint from the server cache (TanStack Query owns the fish list). Shape:
  ```ts
  type AquariumStore = {
    food: FoodPelletState[];   // not the live class — serializable seed for the canvas
    hoveredFishId: string | null;
    paused: boolean;
    cameraOffset: { x: number; y: number };
    addFood: (x: number, y: number) => void;
    consumeFood: (id: string) => void;
    setHovered: (id: string | null) => void;
    togglePause: () => void;
  };
  ```
  Only `paused` is persisted to `localStorage` (key `fishbook:aquarium:paused`) via zustand's `persist` middleware with a partializer.
- **`lib/aquarium/seeded-random.ts`** — exports `mulberry32(seed: number)` returning a `() => number` in `[0,1)`, and `hashStringToSeed(s: string)` for deriving stable per-fish seeds from the fish id. Used for initial positions, target jitter, and bob phase. AGENT.md §8 forbids `Math.random()` for reproducible visuals.
- **`lib/aquarium/Fish.ts`** — pure TypeScript class (not a React component). Constructor `{id, breed, color_hex, size, nickname, prng, viewport}`. State: `position`, `velocity`, `target`, `nextTargetAt`, `bobPhase`, `eatingUntil`, `hovered`. Methods:
  - `pickNewTarget(viewport)` — random target in viewport with 5% inset; bottom-dwellers clamp `target.y > viewport.h * 0.6`.
  - `update(dtMs, food, viewport)` — steers toward target or nearest pellet within `feedingRadius`; capped accel/speed (speed scales inversely with size); sinusoidal vertical bob.
  - `aabb()` — returns axis-aligned bounding box for hover detection.
  - `render(ctx, spriteCache)` — draws the recolored sprite, flipped horizontally if `vx < 0`, with the eating-pulse and a tiny bubble if `eatingUntil > now`.
  - **All math is deterministic given the same PRNG.**
- **`lib/aquarium/FoodPellet.ts`** — class with `position`, `velocity` (sinks at constant +g until terminal), `createdAt`, `eaten`. Lifetime cap: 10 000 ms. Collision: each frame, for each fish, find the nearest *uneaten* pellet within `feedingRadius` and tag it as the fish's eating target; on `|p - f| < eatingDistance`, `pellet.eaten = true` and `fish.eatingUntil = now + 400`.
- **`components/aquarium/AquariumCanvas.tsx`** — `'use client'`. Mounts a `<canvas>` sized to viewport via `ResizeObserver`. One `requestAnimationFrame` loop. Reads fish list from a `fishes: FishResource[]` prop (parent owns the TanStack Query). On prop change, syncs the internal `Fish[]` ref: adds new ids, removes deleted ids, mutates color/size/nickname on the existing instances (positions persist). Mousedown → `useAquariumStore.addFood(x, y)` and pushes a new `FoodPellet` into the canvas-local ref. Mousemove → AABB hit-test → `useAquariumStore.setHovered(id | null)`. RAF tick reads the store imperatively via `useAquariumStore.getState()` (no subscription, no re-render). Honors `prefers-reduced-motion`: when true, freezes all velocities to 0 and skips bob. Pauses RAF on `document.visibilitychange → hidden` (battery win — see open question).
  - **Contract:** zero React state inside the loop. The component owns three refs (`canvasRef`, `fishMapRef`, `pelletsRef`), one `useEffect` that mounts the loop + observers, and *no* `useState`/`useReducer`. This is enforced by code review and by a Vitest unit test that mounts the component, observes that re-rendering the parent does not cancel/restart the RAF, and asserts the RAF handle is identical across renders.
- **`components/aquarium/HoverTooltip.tsx`** — absolute-positioned floating label, *outside* the canvas in the DOM. Subscribes to `useAquariumStore`'s `hoveredFishId` and reads the fish object from the TanStack Query cache (`queryClient.getQueryData(['fishes','list',params])` via a small selector hook). Styled per BRAND.md: `glass-sm` + `font-label-caps`, ~12px text. Does **not** trap focus — it's a tooltip, not an interactive surface.
- **`components/manage/FishManagerModal.tsx`** — Radix `Dialog`. Uses BRAND.md §10.7: `bg-black/30 backdrop-blur-sm` backdrop, `glass-lg` panel, `rounded-xl`, `max-w-3xl`, `p-8`, `max-h-[80vh] overflow-y-auto`. Sticky header with title (`headline-md`) and close button. Body: debounced search input (300 ms via a custom `useDebouncedValue`), breed `<select>`, sort dropdown (Name / Breed / Created / Size + direction). Table rows render fish nickname, breed pill (BRAND.md §10.1 chip), color swatch, size, Edit / Delete buttons. Pagination footer with prev/next. Optimistic delete via TanStack Query (`onMutate` removes the row from the list page; rolled back on error via `onError`).
- **`components/manage/AddFishDialog.tsx`** — Radix `Dialog` inside a portal so it can stack above the manager. `react-hook-form` + `zod`. Fields:
  - Breed: a 5×2 grid of breed cards (sprite preview swatched in the user's currently-selected color). Active card uses BRAND.md `ring-2 ring-primary ring-offset-4`.
  - Color: native `<input type="color">` styled per BRAND §10.5 input rules, plus a hex echo.
  - Size: slider clamped to selected breed's `min_size`/`max_size`, value shown in `tabular-nums`.
  - Nickname: text input, max 40 chars.
  Submit calls `useCreateFishMutation` with `onMutate` optimistic insert into `['fishes','list', firstPageParams]`. On error, roll back + toast (toast component is a no-op stub — slice 7 polishes).
- **`components/manage/EditFishDialog.tsx`** — same form, but `breed` field is read-only (chip, not card grid) per SPEC §2.2 immutability. Pre-fills from the selected fish via `useFishQuery(id)`.
- **`app/fish/page.tsx`** — Server Component. Reads the session cookie via `next/headers`, calls the backend through a small `serverFetch(path, cookies)` helper, dehydrates the query into a `HydrationBoundary`, and renders a client child `<FishPageClient>` that mounts the canvas, the tooltip, the dock, and the modals. Empty-state: if the dehydrated list is empty, the canvas renders a serene background and a single centered glass card with `"Curate your first sanctuary."` + an Add Fish CTA.
- **`app/api/proxy/[...path]/route.ts`** — already exists from slice 2; no change needed. The new hooks just call new paths through it.
- **API hooks** (in `frontend/src/lib/api/fishes.ts` and `frontend/src/hooks/`):
  - `useFishesQuery(params)` — `queryKey: ['fishes','list',params]`. 5-min staleTime.
  - `useFishQuery(id)` — `queryKey: ['fishes','one',id]`.
  - `useBreedsQuery()` — `queryKey: ['fishes','breeds']`. Infinite staleTime (config-only).
  - `useCreateFishMutation()` — optimistic insert; invalidate `['fishes','list']` on settle.
  - `useUpdateFishMutation()` — optimistic merge; invalidate on settle.
  - `useDeleteFishMutation()` — optimistic remove + rollback.
- **Vitest tests:**
  - `Fish.test.ts` — steering math (target reached → re-pick), bottom-dweller y-clamp, AABB hover, eating-collision threshold, capped speed scales inversely with size, deterministic positions given the same seed. **100% statements** per AGENT.md §5.
  - `FoodPellet.test.ts` — sinks; expires after 10 s; eaten flag.
  - `aquarium-store.test.ts` — addFood / consumeFood / setHovered / togglePause / persistence partializer. **100%.**
  - `seeded-random.test.ts` — same seed → same sequence; different seeds → different.
  - `AquariumCanvas.test.tsx` — mounts; RAF starts; parent re-render doesn't restart RAF; list-prop change adds/removes internal instances; `prefers-reduced-motion` freezes movement; `document.hidden` pauses RAF.
  - `FishManagerModal.test.tsx` — open/close, debounced search fires once after 300 ms, sort change updates query params, delete confirm flow, optimistic-rollback on error.
  - `AddFishDialog.test.tsx` — zod validation, size slider clamps to breed range, submit calls mutation with normalized payload.
  - `EditFishDialog.test.tsx` — breed read-only, submit excludes `breed`.
- **Coverage** — ≥70% statements project-wide (slice 2's floor); new files land above 80%; `Fish` + `useAquariumStore` at 100%.

**Both**

- Regenerate `backend/storage/api-docs/openapi.json` + `frontend/src/lib/api-client/`. Both committed. CI regen-no-diff stays green.
- `phpunit.xml`'s `<coverage>` already includes `app/Services` and `app/Http/Controllers`; no change needed.
- No new CI workflow files; the existing `backend.yml` and `frontend.yml` cover this slice.
- Tag at the end: `slice-3-fish-canvas`.

### Out (deferred)

- **Backgrounds** (`backgrounds` table, upload, Fal AI generation, `BackgroundLayer`) → Slice 5.
- **GitHub-repo aquarium** (`/[username]/[repo]`, `RepoAquariumGenerator`, `source='github_repo'` materialization, "Fork to My Aquarium") → Slice 4.
- **Playwright E2E** for the canvas (register → add fish → see swimming → hover → feed → delete) — defer to **Slice 6** polish; the auth-E2E that slice 2 deferred lands there too.
- **Sentry instrumentation** (frontend + backend `beforeSend` scrubbers) → Slice 7.
- **Performance profiling beyond a sanity check** — the actual 100-fish 60 fps budget is verified in Slice 6 polish with React Profiler + a synthetic 100-fish fixture. Slice 3 only commits to "doesn't visibly drop frames with ≤ 30 fish on a mid-range laptop."
- **AR-on-mobile, real-time multiplayer, fish breeding** — never in v1.
- **CSP / HSTS / security headers middleware** — Slice 7 polish. Noted in §6 below.

---

## 3. Approach Decisions

Record load-bearing judgment calls so slice 4+ doesn't relitigate.

1. **Plain 2D Canvas, not WebGL/`@react-three/fiber`.** SPEC §1 explicitly defaults to 2D Canvas API "for predictability." With ≤ 100 fish + ≤ 20 pellets in one draw pass per frame, we're nowhere near the perf ceiling of a software canvas on a modern laptop. WebGL adds shader complexity, a heavier bundle, and a worse a11y story for what is, visually, a small school of tinted SVGs.

2. **Canvas-internal `Fish[]` separate from server `FishResource[]`.** TanStack Query owns the source of truth (id, nickname, breed, color, size). The canvas owns ephemeral state (position, velocity, target, eating). A sync-pass on every list change reconciles the two. The alternative (storing position/velocity in zustand + reading them in React) would force a re-render every animation frame — explicitly forbidden by AGENT.md §3.

3. **One RAF loop, refs only.** `useState`/`setState` inside the loop is the most common way a canvas tanks. The component is enforced ref-only and audited via a test. State that *does* belong to React (hovered id, food list count for the dock counter) is mutated via the zustand store's imperative `getState()/setState()` — zustand updates trigger re-renders of subscribed components but **not** of the canvas, which doesn't subscribe.

4. **Radix Dialog over Headless UI or hand-rolled.** Radix's `Dialog` ships proper focus trap, scroll lock, `aria-modal`, and ESC handling out of the box — exactly what AGENT.md §3 ("a real `<dialog>` or `role="dialog"` with focus trap") demands. Headless UI works too; Radix has the larger ecosystem and is the de-facto Next.js choice in 2026. The dep is small (~12 KB gzip per primitive) and we'll likely reach for `@radix-ui/react-popover` and `@radix-ui/react-tooltip` later anyway. *Open question 3 — confirm or substitute before kickoff.*

5. **Sprites are inline SVGs loaded via `<Image src=…>` and cached as `HTMLImageElement` in a `Map<breed,sprite>`.** Canvas can `drawImage(HTMLImageElement)` faster than parsing SVG per-frame. To recolor, we draw into an offscreen canvas, apply `globalCompositeOperation = 'source-in'` with the desired fill, and cache the tinted result by `(breed, color_hex)`. Cache invalidation is trivial because the keyspace is small (10 breeds × N user-picked colors, bounded by the user's fish count).

6. **Seeded PRNG everywhere positions/velocities/colors are derived.** AGENT.md §8 forbids `Math.random()` for anything reproducible. The Fish class takes a `prng` factory in its constructor. Each fish gets a stable seed from `hashStringToSeed(fish.id)`. Tests pin behavior with seed `1234`.

7. **AABB hover, not pixel-perfect.** SPEC §1: "pointer-position inside its AABB." Pixel hit-testing on a transformed sprite is expensive and unnecessary; AABB is fine for fish ~40×20 px at this density.

8. **Bottom-dwellers via `vertical_band_preference` config flag.** A property in `config/fish_breeds.php` rather than hard-coded in JS — the constraint travels with the breed catalog through the API, so slice 4's repo-aquarium generator picks it up for free.

9. **Soft-delete, hard contract.** DELETE returns 204; the row is gone from the index endpoint; it remains in the `fishes` table with `deleted_at` set. The test asserts *both* conditions explicitly. Hard delete is not exposed in v1.

10. **`source` field defaults to `'manual'`.** SPEC §3 reserves `'github_repo'` for slice 4. A regression test asserts `source='manual'` whenever a fish is created through `POST /fishes`. The CHECK constraint enforces the allowed set at DB level.

11. **`fishes/breeds` is public.** SPEC §2.2 says public. Used by slice 4's public `/[username]/[repo]` page. We register the route *outside* the `auth:sanctum` group with `throttle:api`. *Open question 1 — cacheability.*

12. **Route ordering: `fishes/breeds` declared before `apiResource`.** Laravel's router is order-sensitive — `apiResource` registers `fishes/{fish}` which would otherwise greedily match `breeds`. We register `breeds` first and add a `where('fish', '[0-9]+')` constraint on the resource as belt-and-braces.

13. **Pagination cap server-side.** `per_page` is `min(request->integer('per_page', 25), 100)`. The client can ask for less; can't ask for more. Tested.

14. **Named OpenAPI schemas + explicit `operationId` on every operation.** Slice 2 lesson learned (`InlineObjectN.ts` hashed names). Every operation gets an explicit `operationId` so the generated `FishesApi.listFishes(...)` reads cleanly.

15. **Optimistic mutations + invalidate-on-settle.** Standard TanStack Query pattern: `onMutate` modifies cache, `onError` rolls back, `onSettled` invalidates so the eventual server state wins. Skipping optimism on create/delete would visibly stutter the canvas — both feed it directly.

16. **Hover tooltip is React, not canvas-drawn.** Two reasons: a11y (the canvas is decorative; the tooltip is informational), and styling (it must match BRAND.md `label-caps` + `glass-sm`, which is trivial in CSS and laborious in 2D canvas text). Cost: one absolute-positioned div + one cheap rerender per hover transition.

17. **`prefers-reduced-motion`** disables all velocity and the bob. SPEC §1's movement description and BRAND §9.6 both require this. Fish still render at their last position; the page is usable.

18. **No `cameraOffset` use yet.** Slice 3 types it in the store at `{x: 0, y: 0}` and never mutates it. Slice 4 or 5 may want pan/zoom. Documented so the type doesn't surprise.

19. **CSP impact.** The canvas draws SVG-via-`Image` which is `img-src 'self'` — fine for our domain because sprites live under `/sprites/...`. The eventual S3-backed background images will need `https://*.r2.cloudflarestorage.com` added. Slice 7 finalizes CSP.

---

## 4. API Surface

All paths under `/api/v1`. JSON in, JSON out. Errors `{message, errors?}` per SPEC §2.6.

### 4.1 `GET /fishes` (authed, throttled `api`)

Query params:
- `search?: string` — case-insensitive `ILIKE %s%` on `nickname`.
- `breed?: string` — exact match against breed id; rejected (422) if not in catalog.
- `color?: string` — exact `#RRGGBB`.
- `sort?: 'name'|'breed'|'created_at'|'size'` — default `'created_at'`.
- `direction?: 'asc'|'desc'` — default `'desc'`.
- `page?: int >= 1` — default 1.
- `per_page?: int 1..100` — default 25; values above 100 clamp to 100.

Response 200: `{data: FishResource[], links, meta}` standard Laravel envelope.

### 4.2 `POST /fishes` (authed)

Body (`StoreFishRequest`):
```json
{
  "nickname": "Blubsworth",
  "breed": "guppy",
  "color_hex": "#FF6B9D",
  "size": 12
}
```

Validation:
- `nickname`: 1–40 chars after trim.
- `breed`: present, in catalog.
- `color_hex`: matches `/^#[0-9A-Fa-f]{6}$/`.
- `size`: integer, within the breed's `min_size`/`max_size`.

Response 201: `{data: FishResource}` with `source='manual'`, `source_ref=null`, `user_id=$me->id`.

### 4.3 `GET /fishes/{id}` (authed, owner via policy)

Returns 200 `{data: FishResource}` or 403 (other user) or 404 (deleted / nonexistent).

### 4.4 `PATCH /fishes/{id}` (authed, owner)

Body (`UpdateFishRequest`): any subset of `{nickname, color_hex, size}`. `breed` present → 422 (`prohibited`). `size` validated against the existing breed (loaded from the model — *not* the request).

Response 200 `{data: FishResource}`.

### 4.5 `DELETE /fishes/{id}` (authed, owner)

Soft-deletes the row. Response 204. Subsequent `GET /fishes` does not include it; the DB row exists with `deleted_at != null`.

### 4.6 `GET /fishes/breeds` (public, throttled `api`)

Response 200: `{data: FishBreedResource[]}` where each entry is `{id, label, min_size, max_size, default_color, sprite_key, vertical_band_preference?: 'bottom'}`.

---

## 5. Frontend Architecture

### 5.1 The canvas's two worlds

```
TanStack Query cache              Canvas internals (refs)
─────────────────────             ───────────────────────
['fishes','list',params]   ──┐    fishMapRef: Map<id, Fish>
['fishes','one', id]         │    pelletsRef: FoodPellet[]
['fishes','breeds']          │    rafIdRef: number
                             │    lastTimeRef: number
                             │
        ▼ sync useEffect ────┘
        on [list] changes: add/remove/update Fish instances
```

The sync `useEffect` runs whenever the list changes. It adds new ids (constructing `Fish` with a seed derived from id), removes ids gone from the list, and mutates `color_hex`/`size`/`nickname` on existing instances (positions persist for continuity).

### 5.2 The RAF loop (pseudocode)

```
function tick(now) {
  const dt = clampedDelta(now);
  if (!store.paused && !prefersReducedMotion && !documentHidden) {
    for (const fish of fishMapRef.values()) {
      fish.update(dt, pelletsRef, viewport);
    }
    updatePellets(pelletsRef, dt);
    pruneEatenAndExpiredPellets(pelletsRef);
  }
  draw(ctx, fishMapRef, pelletsRef, spriteCache);
  rafIdRef = requestAnimationFrame(tick);
}
```

No React calls inside this function.

### 5.3 Hover & food event flow

```
Mousemove  → canvas.onmousemove → AABB hit test (refs)
           → if changed, store.setHovered(id|null)
              └─ HoverTooltip re-renders (subscribed)
              └─ AquariumCanvas DOES NOT re-render (not subscribed)

Mousedown  → canvas.onmousedown
           → store.addFood({x, y})
           → pelletsRef.push(new FoodPellet({x, y}))
              (yes, two writes — store for UI/dock count, ref for the loop)
```

### 5.4 Sprite cache

```
spriteCache = new Map<string, HTMLCanvasElement>();  // key = `${breed}:${color_hex}`

async function getSprite(breed, color_hex) {
  const key = `${breed}:${color_hex}`;
  if (spriteCache.has(key)) return spriteCache.get(key);
  const base = await loadImage(`/sprites/fish/${breed}.svg`);  // cached by browser
  const off = document.createElement('canvas');
  off.width = base.naturalWidth; off.height = base.naturalHeight;
  const c = off.getContext('2d');
  c.drawImage(base, 0, 0);
  c.globalCompositeOperation = 'source-in';
  c.fillStyle = color_hex;
  c.fillRect(0, 0, off.width, off.height);
  spriteCache.set(key, off);
  return off;
}
```

Worst case: 100 fish × ~10 unique colors = 1 000 cache entries × ~6 KB each ≈ 6 MB RAM. Acceptable.

### 5.5 Form state architecture

| Concern | Lives in |
|---|---|
| Fish list (server) | TanStack Query `['fishes','list',params]` |
| Single fish (edit) | TanStack Query `['fishes','one',id]` |
| Breeds catalog | TanStack Query `['fishes','breeds']` |
| Pending Add / Edit form values | react-hook-form |
| Zod schemas | `frontend/src/lib/fish/schemas.ts` (shared by Add + Edit + server-mirror validation) |
| Hover, pellets-for-UI, paused | zustand `useAquariumStore` |
| Canvas-internal motion state | refs inside `AquariumCanvas` |

---

## 6. Threat Model Touch-Points

| Threat | Mitigation in this slice |
|---|---|
| **Mass enumeration of other users' fish** | `FishPolicy::view/update/delete` checks `user_id === $auth->id || is_admin`. Index endpoint scopes via `Fish::forUser($me)`. Tested with a "fish belongs to other user" feature test. |
| **Mass-assignment of `user_id` / `source` / `source_ref`** | `StoreFishRequest::validated()` does **not** include those fields; controller writes them from `$me` and constants. `$fillable` does include them (factories need them), so the controller is the gate — tested by asserting a request body with `user_id: other_id` produces a fish owned by `$me`. |
| **Invalid breed / color / size injection** | FormRequest + `BreedCatalog::validate`; DB CHECK constraints as belt-and-braces. Color regex rejects `javascript:` etc. (no SVG/HTML injection vector, since color is rendered into canvas `fillStyle`, not innerHTML). |
| **XSS via nickname** | React escapes by default; tooltip and modal table use `{fish.nickname}`. No `dangerouslySetInnerHTML`. Canvas text uses `ctx.fillText` (no parsing). 40-char hard cap reduces lateral damage. |
| **SVG injection** | Sprites are static assets under `frontend/public/sprites/fish/*.svg`, never user-uploaded. CSP `img-src 'self'` covers them. |
| **N+1 / DoS via expensive list** | Per-user scope + pagination cap of 100; `per_page > 100` clamps. Index test asserts ≤ 4 SQL queries regardless of result count. |
| **CSRF on writes** | API uses bearer auth via the slice-2 proxy. The proxy is same-origin and SameSite=Lax. SPEC §2.6 explicitly disables CSRF for the API guard. |
| **Token leakage via React Query devtools / logs** | DevTools never see the token (it's in the proxy). Hooks only see `FishResource` objects. |
| **CSP / canvas image source** | All sprites under `/sprites/`. CSP `img-src 'self'` works. Future S3 sources require explicit allowlist — slice 7. |
| **Soft-delete bypass via direct id PATCH** | PATCH route uses route-model binding which respects `SoftDeletes` scope by default — a soft-deleted fish returns 404, not 200. Tested. |

---

## 7. Frontend State Architecture (detail)

### 7.1 Server cache: TanStack Query

- `useFishesQuery(params)` — staleTime 5 min, refetchOnWindowFocus false (the canvas is the visual truth; sporadic refetches would replay sync passes).
- `useBreedsQuery()` — staleTime `Infinity`. Catalog is static.
- Mutations all set `onSettled: () => qc.invalidateQueries({queryKey: ['fishes','list']})` so the next focus or explicit refetch realigns with the server.

### 7.2 UI state: zustand

```ts
type AquariumStore = {
  food: { id: string; x: number; y: number; createdAt: number }[];
  hoveredFishId: string | null;
  paused: boolean;
  cameraOffset: { x: number; y: number };
  addFood: (x: number, y: number) => void;
  consumeFood: (id: string) => void;
  setHovered: (id: string | null) => void;
  togglePause: () => void;
};
```

Only `paused` is persisted (localStorage key `fishbook:aquarium:paused`, partializer drops everything else). Camera offset is stored but never mutated in slice 3.

### 7.3 What lives where (cheat sheet)

| Concern | Lives in |
|---|---|
| Is this fish mine? | Server policy + index scope — frontend never decides. |
| Current fish list | `useFishesQuery` |
| Active hover | zustand `hoveredFishId` |
| Pellets *for UI* (dock count, debug) | zustand `food[]` |
| Pellets *for physics* | `AquariumCanvas` ref |
| Form values | react-hook-form |
| Sprite cache | module-level `Map` in `lib/aquarium/sprite-cache.ts` |

---

## 8. Testing Strategy

### 8.1 Backend (Pest)

| Layer | File | What |
|---|---|---|
| Feature | `tests/Feature/Fishes/IndexFishesTest.php` | Happy 200; unauthed 401; pagination meta; `per_page=200` clamps to 100; `search`, `breed`, `color`, `sort+direction` round-trip; **N+1 guardrail** (`DB::enableQueryLog` ≤ 4 queries); cross-user isolation. |
| Feature | `tests/Feature/Fishes/StoreFishTest.php` | Happy 201; `source='manual'` default; missing fields 422; invalid breed 422; invalid color regex 422; size out-of-range for breed 422; mass-assignment of `user_id`/`source` ignored (forced to authed user / `'manual'`). |
| Feature | `tests/Feature/Fishes/ShowFishTest.php` | Happy 200; other user's fish 403; nonexistent 404; soft-deleted 404. |
| Feature | `tests/Feature/Fishes/UpdateFishTest.php` | Happy 200 (partial); `breed` in body 422 (prohibited); other user's fish 403; size out-of-range 422. |
| Feature | `tests/Feature/Fishes/DeleteFishTest.php` | Happy 204; row gone from index; row still in DB with `deleted_at` set; other user's fish 403; idempotent (second DELETE 404). |
| Feature | `tests/Feature/Fishes/BreedsTest.php` | Public 200; shape; 10 breeds; `otocinclus`/`cory_catfish` carry `vertical_band_preference: 'bottom'`. |
| Unit | `tests/Unit/Services/Fish/BreedCatalogTest.php` | `find()` happy + miss; `clampSize` low/high; `validate` failure shapes. |

Coverage gate: ≥80% on `app/Services/Fish/` + `app/Http/Controllers/Api/V1/FishController` (already covered by slice 2's `<coverage>` scope).

### 8.2 Frontend (Vitest)

| Layer | File | What |
|---|---|---|
| Unit | `tests/unit/lib/aquarium/seeded-random.test.ts` | mulberry32 deterministic; different seeds diverge. |
| Unit | `tests/unit/lib/aquarium/Fish.test.ts` | Bottom-dweller y-clamp; AABB hover; eating collision; speed × size scaling; deterministic trajectory under fixed seed. **100% statements.** |
| Unit | `tests/unit/lib/aquarium/FoodPellet.test.ts` | Sinks; expires after 10 s; eaten flag set. |
| Unit | `tests/unit/stores/aquarium-store.test.ts` | All actions; persistence partializer. **100% statements.** |
| Component | `tests/unit/components/aquarium/AquariumCanvas.test.tsx` | RAF mounts; list-prop change syncs internal `Fish[]`; parent re-render does NOT restart RAF; reduced-motion freezes; visibility hidden pauses. |
| Component | `tests/unit/components/manage/FishManagerModal.test.tsx` | Open/close; debounced search (fake timers); sort; optimistic delete + rollback. |
| Component | `tests/unit/components/manage/AddFishDialog.test.tsx` | Zod validation; size clamps to breed range; submit payload shape. |
| Component | `tests/unit/components/manage/EditFishDialog.test.tsx` | Breed read-only; submit excludes `breed`. |

Coverage gate: 70% statement floor across the project (slice 2's setting). New files target ≥ 80%; `Fish` + `useAquariumStore` at 100%.

### 8.3 Out

- Playwright E2E (canvas + auth) → Slice 6 polish.
- Pixel-level canvas snapshot tests — too brittle for animated content; we test the math instead.

---

## 9. Acceptance Criteria

1. `GET /api/v1/fishes` with a valid token returns a paginated list scoped to the user; 401 without a token.
2. `POST /api/v1/fishes` with valid body returns 201 + `FishResource`; `source='manual'`, `user_id=$me`.
3. `PATCH /api/v1/fishes/{id}` updates `nickname`/`color_hex`/`size`; rejects `breed` (422).
4. `DELETE /api/v1/fishes/{id}` returns 204; row is absent from `GET /fishes` and present in DB with `deleted_at`.
5. `GET /api/v1/fishes/breeds` returns 10 breeds, public, no auth required.
6. `/fish` page renders a full-viewport canvas. On a user with ≥ 1 fish, that fish swims; on a user with 0 fish, the empty-state CTA is shown.
7. Hovering a fish shows its nickname in a `glass-sm` tooltip (SPEC §17 item 3).
8. Clicking the canvas drops a food pellet; the nearest fish swims to it and consumes it (SPEC §17 item 4).
9. The Manage Fishes modal opens, allows debounced search, breed filter, sort, edit nickname, and delete with optimistic UI (SPEC §17 item 5).
10. The Add Fish dialog creates a fish that appears on the canvas within one frame of the optimistic update.
11. The Sanctum token is **not** visible anywhere in `localStorage`, `sessionStorage`, or non-HttpOnly cookies. (Inherited from slice 2; re-verified.)
12. `php artisan l5-swagger:generate && git diff --exit-code storage/api-docs/openapi.json` exits 0.
13. `npm run generate:api && git diff --exit-code src/lib/api-client` exits 0.
14. Backend coverage ≥ 80% on the new directories; project coverage gate stays green.
15. Frontend coverage ≥ 70% statements; `Fish` + `useAquariumStore` at 100%.
16. `npm run lint`, `npm run typecheck`, `npm run build` stay clean. `./vendor/bin/phpstan analyse` level 6 stays clean.
17. `prefers-reduced-motion: reduce` freezes all fish movement; the page is still navigable.
18. Code review confirms there is no `useState`/`setState` call inside `AquariumCanvas`'s RAF loop or its descendants in the loop's call chain.

---

## 10. Open Questions / Follow-Ups

- **Cacheability of `/fishes/breeds`.** It's a static config — should the response carry `Cache-Control: public, max-age=3600`? Default plan: yes, plus an `ETag` derived from `md5(json_encode(config('fish_breeds')))` so a config rev busts caches. *Confirm before merge.*
- **Empty-state Add Fish flow.** A "one-click starter pack" (e.g. 1 guppy + 2 neon tetras + 1 cory catfish) might be a better first-run experience than a blank canvas + a single CTA. Default plan: strict one-at-a-time. *Confirm direction.*
- **Radix dependency choice.** The plan reaches for `@radix-ui/react-dialog`. Headless UI and a hand-rolled focus trap (e.g. `focus-trap-react`) are alternatives. Default: Radix. *Confirm or substitute.*
- **Sprite art direction.** Plan defaults to "simple geometric side-view profile" — recognizable but un-illustrated. Alternatives: hand-drawn-looking, strict outline-only, photorealistic-vector. *Pick a direction before the SVGs are baked into the repo; they're cheap to redo before then.*
- **`document.visibilitychange` → pause RAF.** Cheap battery win, but it can confuse users returning to a paused tank. Default plan: yes, pause on hidden; on visible, jump-resume with a one-frame `dt` clamp so fish don't teleport. *Confirm.*
- **Reduced motion ambiguity.** "All transitions/animations off" (AGENT.md) is unambiguous for the canvas — but should bottom-dwellers still respect the y-band when frozen at initial positions? Default: yes, the initial position itself respects the band. *No action; documented.*
- **Bigint id → string in `FishResource`.** Standard JS `number` can't safely represent ids past `2^53-1`. We emit `id` as a string for safety. Frontend types treat it as `string` throughout. *Confirm acceptable for the eventual repo-aquarium fork flow.*

---

## 11. Sources

- `SPEC.md` §1 (routes, layout, fish movement), §2.2 (Fishes API), §2.6 (cross-cutting), §3 (`fishes` table), §4 (breed catalog), §6 (acceptance bits), §12 (testing), §16 (security), §17 (acceptance items 1–7).
- `AGENT.md` §1 (versions), §3 (conventions, canvas rules), §4 (security), §5 (testing), §6 (perf budgets), §8 (review pitfalls).
- `BRAND.md` §2 (color), §5 (glass surfaces), §6 (radii), §9 (motion / reduced-motion), §10.5 (input), §10.7 (modal).
- Slice 2 design + plan — for tone, structure, OpenAPI/coverage baselines, and lessons learned on named schemas + `operationId`.
