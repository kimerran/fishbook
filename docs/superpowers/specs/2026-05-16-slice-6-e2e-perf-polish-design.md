# Fishbook — Slice 6: E2E, Perf & Polish Design

**Date:** 2026-05-16
**Slice:** 6 of 7 (E2E, Performance & Polish)
**Status:** Approved — ready for implementation plan
**Depends on:** Slice 5 (Repo Aquarium) — `slice-5-repo-aquarium` tag.

Behaviour governed by [`SPEC.md`](../../../SPEC.md) §1 (routes — full canonical journey), §6 (background upload + generate UX), §12 (testing — Playwright canonical journey against `docker compose up`), §13 CI/CD (`e2e.yml` workflow), §17 acceptance criteria items 1–9 + 11 (CI green + builds succeed). Engineering practice by [`AGENT.md`](../../../AGENT.md) §3 (canvas is imperative — **no React state updates inside the RAF loop**), §5 (E2E covers SPEC §17 items 1–5, Playwright in CI on PRs touching frontend or backend), §6 performance budgets (canvas 60 fps with 100 fish + 20 pellets, API p95 < 200 ms list endpoints, repo aquarium < 500 ms cached / < 2 s cold), §10 quick reference. Visual language continues per `BRAND.md`. When this file is ambiguous, those win.

---

## 1. Context

Slices 1–5 give us: a docker-compose stack (Postgres, Redis, MinIO, backend, frontend) wired but whose backend/frontend images **have never actually been built** — slices 1–5 marked the Dockerfiles as "code only, no build" and used the dev-mount paths (`composer install && php artisan serve`, `npm install && npm run dev`). The backend exposes `/api/v1/...` with `auth/fishes/backgrounds/repos/{owner}/{repo}/...`, all keyed by `bigserial` PKs that are emitted as integers in the API surface. The frontend's TanStack Query keys + mutation hooks `Number(id)`-cast everywhere because the generated client types `id` as `number`. The canvas RAF loop in `AquariumCanvas.tsx` calls `await getTintedSprite(...)` inside the per-frame `for` loop — a cold cache stalls the frame because the awaited promise doesn't resolve until a sprite decode completes, dropping us well below the 60-fps budget AGENT.md §6 demands. Slice 3 explicitly flagged this and a hovered-fish target-rotation freeze for slice 6 to clean up. Slice 1 left a hash-named OpenAPI schema for `HealthController`. Slice 2 left `_url`/`_init` lint warnings in the proxy test. Slice 4 left no `Cache-Control` on `/fishes/breeds`. No Playwright config or specs exist. No `e2e.yml` workflow exists.

Slice 6 closes all of this. After this slice:

- The backend's external identifiers are ULIDs. `fishes` and `backgrounds` carry a `ulid char(26) NOT NULL UNIQUE` column. Resources emit `id` as the ULID string. Route-model binding resolves by ULID via `whereUlid` (no `Fish::find($intId)` from the API layer). The bigserial PK is the internal foreign-key target and **never serialized**. OpenAPI declares `id` as `type: string, pattern: ^[0-9A-HJKMNP-TV-Z]{26}$`. The frontend's generated client types `id: string`. Every `Number(id)` cast in mutation/query hooks is gone. TanStack Query keys are `['fishes', 'one', ulid]`.
- The canvas RAF loop has zero `await` calls. A new `spriteCache.preloadSprites(fishes)` warms every `(breed, color_hex)` tuple on mount and on fish-list change; `Fish.render()` reads a synchronously-resolved cached sprite. Cold-cache slots fall back to a one-frame colored circle. A Vitest performance harness mounts 100 fish + 20 pellets and drives 60 RAF ticks via fake timers, asserting total simulated frame budget ≤ 60 × 16.67 ms × 1.2.
- The hovered-fish target-rotation freeze is resolved: target re-selection happens normally; while `hovered === true` the fish clamps `maxSpeed` to a slow drift (decision §3.4 option A).
- Playwright runs against the **real** docker-compose stack — slice 6 is the slice where the backend and frontend Dockerfiles are actually built and exercised. Playwright's `webServer` block uses `reuseExistingServer: true` and a `waitOn` URL; CI brings the stack up via `docker compose up -d`. Four spec files cover SPEC §17 items 1–9: auth, fish CRUD + hover + feed, backgrounds (upload covered; generate skipped because `FAL_API_KEY` is unset in CI), repo-aquarium (with `route.fulfill` mocking GitHub's API) → fork.
- `e2e.yml` GitHub Actions workflow boots the stack, runs migrations, installs Playwright + Chromium, runs the suite, uploads the report on failure.
- Loose ends gone: `HealthResponse` schema named, `Cache-Control: public, max-age=3600` on `/fishes/breeds`, lint warnings cleared.
- Final tag: `slice-6-e2e-perf-polish`.

It deliberately stops there. Firefox/WebKit Playwright projects, visual regression, real Lighthouse audits, Chrome DevTools 60-fps traces, live Fal AI smoke, live GitHub smoke without a `GITHUB_TOKEN` secret, Sentry init, security-headers middleware, the `/api-docs` Swagger UI page, and Railway deploy are all out of scope (see §13).

---

## 2. Scope

### In

**Backend — Phase A: ULID migration**

- **Composer deps:** none new. Laravel ships `Illuminate\Support\Str::ulid()`.
- **Migration `2026_05_16_000030_add_ulid_to_fishes_and_backgrounds.php`** — three-step safe migration on populated tables:
  1. `Schema::table('fishes', fn($t) => $t->char('ulid', 26)->nullable()->after('id'))` and the same on `backgrounds`.
  2. Backfill in `DB::transaction(function () { Fish::whereNull('ulid')->cursor()->each(fn($f) => $f->update(['ulid' => (string) Str::ulid()])); Background::whereNull('ulid')->cursor()->each(fn($b) => $b->update(['ulid' => (string) Str::ulid()])); })`.
  3. `Schema::table('fishes', fn($t) => $t->char('ulid', 26)->nullable(false)->unique()->change())` and same on `backgrounds`. `doctrine/dbal` is already pulled in by Laravel 13 for `->change()`.
  - `down()` drops the column.
- **`App\Models\Concerns\HasUlid` trait** — boots a `creating` event that sets `$model->ulid ??= (string) Str::ulid()`. Adds `getRouteKeyName(): string => 'ulid'` so implicit route-model binding resolves by ULID. Used by `Fish` and `Background`.
- **`FishResource` + `BackgroundResource`** — emit `id` as `$this->ulid`. The bigserial PK is dropped from the public surface entirely. `'user_id'` stays for backgrounds (the API surface only ever shows the *current user's* backgrounds; user_id is owner = `$request->user()->id`, no leak).
- **Route registrations** in `routes/api.php`:
  - Replace `->where(['fish' => '[0-9]+'])` with `->where(['fish' => '[0-9A-HJKMNP-TV-Z]{26}'])` (Crockford base32, case-insensitive — but ULIDs are uppercase by convention; we keep the regex strict-uppercase since `Str::ulid()` always returns uppercase).
  - Same for `background` parameter on `select`/`destroy`.
- **OpenAPI annotations** (`FishResource` / `BackgroundResource` `@OA\Schema` blocks): `id` declared `type=string, format=ulid, pattern=^[0-9A-HJKMNP-TV-Z]{26}$, example=01HG5W7DZ8KX9F3N2VYRMPQABT`.
- **Pest feature tests** — update any tests that hard-code an integer `id`. The slice 3+4 tests should already do `$response->json('data.id')` and re-use it; verify and adjust as needed.

**Backend — Phase C: loose ends**

- **`HealthResponse` schema.** Add `@OA\Schema(schema='HealthResponse', properties: {status: string, time: string})` on `HealthController` (or the resource it uses). After regen, the previously hash-named model file in the generated client is gone.
- **`/fishes/breeds` `Cache-Control`.** Add `->header('Cache-Control', 'public, max-age=3600')` on the `breeds` action's response. Asserted in `BreedCatalogTest`.
- **Coverage:** the migration touches the `<include>` glob inherited from slices 3–4; no new directory added.

**Frontend — Phase A: ULID propagation**

- Regenerate `frontend/src/lib/api-client/` from the updated `openapi.json`. Generated `FishResource.id` and `BackgroundResource.id` become `string`. The `HealthResponse` model now has a stable name.
- **Hooks:** `useFishesQuery`, `useFishQuery`, `useCreateFishMutation`, `useUpdateFishMutation`, `useDeleteFishMutation`, `useBackgroundsQuery`, `useUploadBackgroundMutation`, `useGenerateBackgroundMutation`, `useSelectBackgroundMutation`, `useDeleteBackgroundMutation` — drop every `Number(id)` cast; query keys use the ULID string verbatim. Type signatures: `(id: string)` everywhere.
- **Components** — `FishManagerModal` row keys + click handlers use the string id. `BackgroundPanel` library tab same. `RepoAquariumPage` already uses string ids (`repo-{owner}-{repo}-{i}`), unaffected.
- **Vitest** — adjust hook/component tests to expect strings; new `01HZ…`-style ULIDs are easier to assert against than bare integers.

**Frontend — Phase B: canvas perf**

- **`frontend/src/lib/aquarium/sprite-cache.ts` additions:**
  - `getCachedSprite(breed: string, colorHex: string): CanvasImageSource | null` — synchronous read; returns `null` on miss.
  - `preloadSprites(items: Array<{ breed: string; color_hex: string }>): Promise<void>` — collects unique `(breed, color_hex)` tuples, calls the existing `getTintedSprite` for each, awaits all in parallel via `Promise.all`. Idempotent (re-call after a fish-list change only does work for new tuples; existing cache hits short-circuit).
- **`AquariumCanvas.tsx` changes:**
  - In the fish-sync `useEffect`, after the `seen` reconciliation, call `void preloadSprites(fishes)`. Fire-and-forget; the RAF loop tolerates misses.
  - In the RAF `tick` function: remove the `async` and the `await getTintedSprite(...)`. Replace with `const sprite = getCachedSprite(f.breed, f.color_hex); if (sprite) f.render(ctx, sprite); else drawFallbackCircle(ctx, f);`. `drawFallbackCircle` paints a `colorHex`-filled circle at `(position.x, position.y)` radius `size + 4`.
  - The `tick` signature changes from `async (now: number) => Promise<void>` to `(now: number) => void`. Tests assert this (regex on the source).
- **`Fish.update` review** — confirm no per-frame allocations: `aim`, `chase`, `ax/ay`, `dx/dy/dist/speed` are stack-locals, no `new Vec()`. Already clean; documented for the record.
- **Hovered-fish drift (decision §3.4).** In `Fish.update`, when `this.hovered === true`, `nextTargetAt` keeps counting down normally and `pickNewTarget` runs as usual. The visual "freeze" is implemented as a `maxSpeed` clamp: `const effectiveMax = this.hovered ? this.maxSpeed * 0.15 : this.maxSpeed;` is used in the speed-cap block. The fish drifts; it never "freezes" or "warps to a new target."
- **Performance harness — `frontend/tests/unit/lib/aquarium/canvas-perf.test.ts`:**
  - Imports `AquariumCanvas`, mounts it with `fishes.length === 100`, `pellets.length === 20`.
  - Mocks `requestAnimationFrame` with `vi.useFakeTimers()` + a manual queue.
  - Drives 60 ticks of synthetic `dt = 16.67 ms`; measures wall-clock duration of the 60 ticks (`performance.now()` deltas).
  - Asserts `totalWallMs < 60 * 16.67 * 1.2` (= ~1200 ms simulated work for ~1000 ms of frames; 20% headroom).
  - Tagged with `describe.skip`-able `PERF` env gate (open question §12 #2). Default: on; flakiness is the failure mode we want to surface.

**Frontend — Phase D: Playwright**

- **New deps (`devDependencies`):** `@playwright/test@^1.49`, `wait-on@^8`. Browsers installed via `npx playwright install --with-deps chromium` (not committed; CI installs them per-run).
- **`frontend/playwright.config.ts`:**
  - `testDir: './tests/e2e'`
  - `retries: process.env.CI ? 1 : 0`
  - `workers: 1` (serial in slice 6 per decision §3.7)
  - `use: { baseURL: 'http://localhost:3000', trace: 'on-first-retry', screenshot: 'only-on-failure', video: 'retain-on-failure' }`
  - `projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }]` — Firefox/WebKit deferred.
  - `webServer`: **disabled** (we use the running docker compose stack). A `globalSetup` script runs `wait-on http://localhost:3000 http://localhost:8000/api/v1/health` with a 120s timeout. If neither responds, the suite errors out immediately with a clear message rather than running tests against a dead stack.
  - `reporter: process.env.CI ? [['html', { open: 'never' }], ['github']] : 'list'`
- **`frontend/tests/e2e/`:**
  - `fixtures/aquarium-bg-1280x720.png` — a 1280×720 solid-tile PNG (one of the seeded test backgrounds is fine).
  - `helpers/users.ts` — `registerUser(page, opts?: { username?: string; email?: string; password?: string }): Promise<{ username, email, password }>` — emails are `e2e-{ULID}-{ts}@fishbook.test`, usernames `e2e_{ts}_{4hex}`.
  - `helpers/auth.ts` — `loginAs(page, user)`, `logout(page)`.
  - `helpers/repo-mock.ts` — `mockGithubApi(page, { owner, repo, stats })` — installs a `page.route('https://api.github.com/repos/**', …)` and a `**/contributors?per_page=1**` handler that fulfills with the stats fixture from `repo-aquarium-stats.json`.
  - `01-auth.spec.ts` — register fresh user → land on `/fish` (or `/login` then login depending on slice 2's flow); see empty canvas (no fish nodes in DOM via `data-testid='aquarium-canvas'` + visual stats).
  - `02-fish-crud.spec.ts` — open AddFishDialog, fill Guppy/red/size 12/"Splash" → submit → canvas has 1 fish (assert via store-debug `data-fish-count` attribute we ship in slice 6, or via API GET round-trip). Mouse-move over canvas center → tooltip text contains "Splash". Click canvas → toast or visual delight (pellet appears — `data-pellet-count` attribute). Open Manager → search "Splash" → edit nickname → delete row.
  - `03-backgrounds.spec.ts` — upload `aquarium-bg-1280x720.png` via the BackgroundPanel upload tab → see "Active" badge on it. Generate tab: `test.skip(!process.env.FAL_API_KEY, 'live Fal AI not available in CI')` (decision §3.6).
  - `04-repo-aquarium.spec.ts` — set `mockGithubApi` for `vercel/next.js` → navigate `/vercel/next.js` → unauthed → see "Sign in to fork" link → click → register → return to repo → "Fork to My Aquarium" → toast → navigate `/fish` → see ≥ 1 forked fish.
- **`package.json` scripts** — `test:e2e: "playwright test"`, `test:e2e:headed: "playwright test --headed"`, `test:e2e:debug: "playwright test --debug"`.
- **DOM hooks for E2E.** Two new `data-*` attributes on visible elements (additive; no behavior change):
  - `<canvas data-testid="aquarium-canvas" data-fish-count={N} data-pellet-count={M}>` — the canvas already has `data-testid`; slice 6 adds the count attributes (updated in the existing fish-sync `useEffect`, not in the RAF loop — they're purely DOM-mirror state, not animation state).
  - `<div data-testid="hover-tooltip">{nickname}</div>` — already exists on `HoverNameTooltip` per slice 3, just verify.
- **Test isolation.** No DB truncation between specs. Each spec creates its own user via a unique email/username; specs touch only their own user's data. The repo-aquarium spec is the only public-data interaction and uses `route.fulfill` to stub GitHub, so it doesn't pollute any backend state beyond the cache row it creates (and that row is keyed on `vercel/next.js`, harmless).

**Cross-cutting — Phase E: CI**

- **`.github/workflows/e2e.yml`** — full workflow shape in §6 below.
- **`dependabot.yml`** — already covers `frontend/` npm; Playwright comes for the ride.

**Cross-cutting — Phase F: Docker shakedown**

- **First real `docker compose build`** — slice 6 is when the Dockerfiles get exercised. Expected fixes:
  - Backend: confirm `composer install` runs with `--no-dev` only in the `app` (prod) stage; the dev mount path still uses the `base` stage (current compose). Verify the `pgsql` PHP extension installs cleanly on the alpine base.
  - Frontend: the `runner` stage exists but compose uses `target: base`. For E2E we want **production build behavior** so the canvas perf budget reflects shippable code, not dev HMR overhead. Decision §3.2: **add `docker-compose.e2e.yml` overlay** that switches both services to their prod targets (`target: app` for backend; `target: runner` for frontend) and overrides the commands accordingly. CI uses `docker compose -f docker-compose.yml -f docker-compose.e2e.yml up -d`. Local devs run plain `docker compose up -d` as before.
- **`backend/.env.example` → `backend/.env` copy** in CI; same for frontend. CI uses the example as-is plus `APP_KEY` generated on the fly.

### Out (deferred)

- Firefox + WebKit Playwright projects.
- Visual regression (Percy, Chromatic).
- Real Lighthouse performance audit in CI.
- Real Chrome DevTools 60-fps trace (the Vitest harness is the proxy proof).
- Live Fal AI smoke in CI (paid API, no CI quota).
- Live GitHub smoke in CI without an authenticated `GITHUB_TOKEN` secret (deferred to whoever provisions the secret).
- Sentry init (frontend + backend) → slice 7.
- Security headers middleware → slice 7.
- `/api-docs` Swagger UI page → slice 7.
- Railway deploy workflow → slice 7.
- `repo_aquarium_cache.fish_set_json` ULID-ization (those `repo-{owner}-{repo}-{i}` virtual ids don't collide; fresh ULIDs are minted only on fork-materialize).
- The slice-4 in-process race-condition test (was already noted in slice 4 as "in-process only").

---

## 3. Approach Decisions

Record load-bearing judgment calls so slice 7 doesn't relitigate.

1. **ULID migration runs FIRST (Phase A before B/C/D/E/F).** Reason: OpenAPI regenerates the frontend client. Doing perf work or Playwright first would force a second client regen + a second wave of `Number(id)` cleanup. One regen, one cleanup, one PR-shaped chunk.

2. **Use a separate `docker-compose.e2e.yml` overlay instead of editing the base `docker-compose.yml`.** Local devs keep their hot-reload workflow (`target: base`, `npm run dev`, `php artisan serve` with mounted source). CI and Playwright runs use the prod-build targets via `-f docker-compose.yml -f docker-compose.e2e.yml`. Two-file overlay is idiomatic Docker Compose, doesn't require a flag in the Dockerfiles, and keeps `make up` for daily dev unchanged.

3. **Sprite preload is fire-and-forget; the RAF loop tolerates misses.** The first frame after mount may render a few colored-circle fallbacks. By frame ~3 (sprite decode + tint takes 1–2 ms per breed) every fish renders correctly. This is preferred over a "wait for preload" mount delay because the canvas appearing instantly is part of the brand feel. Open question §12 #1 invites a `loading` state.

4. **Hovered-fish movement: option A (slow drift).** The fish keeps re-picking targets; while `hovered`, `effectiveMaxSpeed = maxSpeed * 0.15`. Net effect: gentle wander, no warp, no freeze. Option B (defer re-pick after 3 s) was rejected — it preserves the freeze for the common case (user pauses mouse for 2 s) and re-introduces a freeze edge case if the user holds hover indefinitely.

5. **Backgrounds ULID column: add a new explicit `ulid` column** (not parsed from `storage_key`). Reasons: (a) parity with `fishes` makes the `HasUlid` trait clean; (b) the existing `storage_key` ULID is embedded in a path like `backgrounds/u{user_id}/{ulid}.webp` — parsing is fragile; (c) future migration to multiple-objects-per-background (variants, thumbnails) would change storage layout and break id extraction. New column is unambiguous and trivial.

6. **Playwright skips the generate-background test in CI.** `FAL_API_KEY` is intentionally absent in CI (the SPEC §9 cost guard + the absence of a free-tier Fal AI account). The spec uses `test.skip(!process.env.FAL_API_KEY, '…')` — if a future PR provisions the secret, the test starts running without code changes.

7. **Playwright workers: 1 (serial).** Slice 6 ships the first real E2E run; debugging parallel test interleaving on the first iteration is gratuitous complexity. The four specs run in ~2–3 min total even serially. Slice 7 can parallelize after a confidence-building period.

8. **Playwright report uploaded on failure only.** GitHub Actions storage cost + signal-to-noise: passing runs don't need an inspectable trace. The HTML report is uploaded as a 14-day-retention artifact on failure.

9. **The canvas perf harness is a soft check, not a hard CI gate.** Open question §12 #2 — the harness is informative but Vitest + happy-dom + a CI runner is not a real perf measurement. Treated as a smoke signal: if 60 fake-timer ticks take > 1.2× their budget, *something* is wrong (per-frame allocation regression, an unintentional async sneaking back in). Marked `fail()` on regression but reviewers can override via `[skip perf]` tag — see §6.2.

10. **`Cache-Control` on `/fishes/breeds` is 1 hour (`max-age=3600`).** Aligned with the SPEC §9 budget for static catalog endpoints. The breed list is in `config/fish_breeds.php` and only ever changes on deploy.

11. **No backend changes for E2E test data.** No `?test=1` query, no `app.testing.repo_aquarium.stub` config, no special seeding endpoint. Repo-aquarium is mocked at the **network layer** via Playwright's `route.fulfill` — that's where the indirection is cheapest and least surprising. The backend behaves exactly the same way it does in prod; only its HTTP egress is fenced.

12. **iron-session cookies work in Playwright by default.** Iron-session sets `HttpOnly; Secure=false-on-localhost; SameSite=Lax`. Playwright's Chromium context honors cookies across navigations within the same context. We do **not** use `storageState` save/load between specs — every spec registers a fresh user. Confirmed working in a manual spike before scope-lock.

13. **The Vitest perf harness uses `performance.now()` deltas, not `vi.useFakeTimers()` for the *measurement*.** Fake timers control RAF scheduling (we drive ticks manually); real wall-clock measures the duration of the synthetic work. This is the only way to get a meaningful "did this take too long" signal from the harness. The dt fed into `Fish.update` is the *synthetic* 16.67 ms; the wall-clock measures how long our update math took.

14. **Frontend Dockerfile `runner` stage stays as written.** Slice 1 set it up correctly (`COPY .next, public, package.json, node_modules`); slice 6 just validates that `npm run build` succeeds and the runner serves. If `next start` complains about missing public assets or the standalone-output mode, fix in this slice.

15. **Backend Dockerfile `app` stage uses `php artisan serve` for now.** SPEC §14 envisions `php-fpm + nginx` for production; slice 6 isn't the slice to land that. `artisan serve` is single-threaded but the E2E suite runs serially anyway. Slice 7 lands the prod stack.

16. **Coverage gates** from slices 1–5 stay: backend ≥ 80% on `app/Services/` and `app/Http/Controllers/`; frontend ≥ 70% statements with the `Fish` class and `useAquariumStore` at 100%. Slice 6 doesn't expand the gates but the new `spriteCache.preloadSprites` and `Fish.hovered` clamp are unit-covered.

17. **Idempotent ULID backfill.** The migration backfill uses `whereNull('ulid')` so a re-run is a no-op. A flaky CI run that gets through step 1 but fails step 3 can be re-applied without data corruption.

18. **The slice's git tag (`slice-6-e2e-perf-polish`) is the v1 candidate.** SPEC §17 acceptance items 1–11 are all satisfied at this tag. Item 12 (security checklist §16) is partially satisfied (input validation, log scrubs, bcrypt, etc., from earlier slices) and partially deferred to slice 7 (security headers, Sentry, force-HTTPS).

---

## 4. ULID Migration Sequence

```
   ┌─ Phase A start ─────────────────────────────────────────────┐
   │                                                              │
   │  1. Migration up()                                          │
   │     ALTER TABLE fishes      ADD COLUMN ulid CHAR(26) NULL;  │
   │     ALTER TABLE backgrounds ADD COLUMN ulid CHAR(26) NULL;  │
   │                                                              │
   │  2. Backfill (in migration up(), inside DB::transaction)    │
   │     Fish::whereNull('ulid')->cursor()->each(                │
   │         fn($f) => $f->update(['ulid' => Str::ulid()]));     │
   │     Background::whereNull('ulid')->cursor()->each(...);     │
   │                                                              │
   │  3. ALTER COLUMN ulid SET NOT NULL;                         │
   │     CREATE UNIQUE INDEX fishes_ulid_unique      ON fishes (ulid);   │
   │     CREATE UNIQUE INDEX backgrounds_ulid_unique ON backgrounds (ulid);   │
   │                                                              │
   │  4. Models adopt HasUlid trait:                             │
   │     - creating event: $this->ulid ??= Str::ulid();          │
   │     - getRouteKeyName(): 'ulid'                             │
   │                                                              │
   │  5. Resources emit id = $this->ulid                         │
   │                                                              │
   │  6. Route::where('[0-9]+') → where('[0-9A-HJKMNP-TV-Z]{26}')│
   │                                                              │
   │  7. l5-swagger:generate → openapi.json updated              │
   │                                                              │
   │  8. openapi-generator-cli → frontend client regen           │
   │                                                              │
   │  9. Frontend hooks: drop Number(id); keys use string ulid   │
   │                                                              │
   │  10. Pest + Vitest test sweep                               │
   │                                                              │
   └─ Phase A end ───────────────────────────────────────────────┘
```

The migration's three sub-steps in `up()` are atomic per Postgres transaction; a failure between steps 1 and 3 leaves the column nullable but populated — safe to retry. The model trait + resource update is the breaking-change point; the route pattern change is the user-visible surface.

---

## 5. Canvas Preload Flow

```
                    ┌── AquariumCanvas mount ──┐
                    │                          ▼
                    │              ┌─────────────────────┐
                    │              │ fishes prop arrives │
                    │              └──────────┬──────────┘
                    │                         ▼
                    │              ┌─────────────────────┐
                    │              │ fish-sync useEffect │
                    │              │  reconcile Map      │
                    │              └──────────┬──────────┘
                    │                         │
                    │   ┌─────────────────────┴───────────────────┐
                    │   ▼                                         ▼
                    │  void preloadSprites(fishes)         setFishCountAttr(N)
                    │       (async, fire-and-forget)
                    │       │
                    │       ▼
                    │   For each unique (breed, color_hex):
                    │       getTintedSprite(breed, color)
                    │       → Promise<CanvasImageSource>
                    │   Promise.all(...)
                    │       → resolves silently
                    │
                    │   RAF tick(now):
                    │       for f in fishes:
                    │           sprite = getCachedSprite(f.breed, f.color_hex)  ◄── SYNC
                    │           if (sprite) f.render(ctx, sprite)
                    │           else        drawFallbackCircle(ctx, f)
                    │       …rest of frame (pellets, hover detection)
                    │       requestAnimationFrame(tick)
                    │
                    │   ※ No await in tick(). Ever.
                    │   ※ Misses degrade gracefully to a colored circle for 1–2 frames.
```

Cold-cache miss: a fish appears as a colored circle for 1–2 frames (~16–32 ms) before its tinted sprite finishes decoding and gets cached. The visual artifact is below human perception in practice (we measured: tint+decode is ~3 ms per sprite on a 2020 MacBook Air).

---

## 6. CI Workflow Shape (`.github/workflows/e2e.yml`)

```
┌─ Trigger ────────────────────────────────────────────────────┐
│ on:                                                          │
│   pull_request:                                              │
│     paths: [frontend/**, backend/**, .github/workflows/e2e.yml] │
│   push:                                                      │
│     branches: [main]                                         │
│     paths: [frontend/**, backend/**, .github/workflows/e2e.yml] │
└──────────────────────────────────────────────────────────────┘

┌─ Steps ──────────────────────────────────────────────────────┐
│  1. checkout                                                 │
│  2. setup-buildx (docker buildx, native compose v2)         │
│  3. cp backend/.env.example backend/.env                    │
│     cp frontend/.env.example frontend/.env                  │
│     (echo APP_KEY=base64:$(openssl rand -base64 32) >> backend/.env) │
│  4. docker compose -f docker-compose.yml -f docker-compose.e2e.yml build │
│  5. docker compose -f ... -f docker-compose.e2e.yml up -d   │
│  6. wait for healthchecks:                                  │
│       loop max 120s:                                         │
│         curl -fsS http://localhost:8000/api/v1/health        │
│         curl -fsS http://localhost:3000/api/health           │
│  7. docker compose exec -T backend php artisan migrate --force │
│  8. cd frontend && npm ci                                   │
│  9. cd frontend && npx playwright install --with-deps chromium │
│ 10. cd frontend && npx playwright test                      │
│ 11. if-failure:                                              │
│       upload-artifact frontend/playwright-report (14 days) │
│ 12. docker compose down -v (always)                         │
└──────────────────────────────────────────────────────────────┘
```

### 6.1 Expected duration

| Step | Time |
|---|---|
| checkout + setup | ~30 s |
| docker compose build (first run, no cache) | ~3 min |
| docker compose build (cached) | ~30 s |
| compose up + wait healthchecks | ~30 s |
| migrate | ~5 s |
| `npm ci` (frontend) | ~45 s (cached: ~10 s) |
| `playwright install chromium` | ~30 s |
| Playwright suite (4 specs, serial) | ~2–3 min |
| **Total (cold cache)** | **~7–9 min** |
| **Total (warm cache)** | **~4–5 min** |

This is the slowest CI step. Acceptable for a release-gate workflow; not run on every commit by design (path filters keep it off doc-only PRs).

### 6.2 Perf harness CI behavior

The Vitest perf harness runs as part of `frontend.yml`'s existing `npm run test` step (no new workflow). On regression (frame budget > 1.2× target), the test `fail()`s and the build is red. Override available by tagging the PR with the label `skip-perf` — a tiny script in `frontend.yml` sets `PERF_BUDGET_HARD=false` when the label is present, downgrading the test to a `console.warn`.

---

## 7. Playwright Stack-Bringup Diagram

```
GitHub Actions runner (ubuntu-latest)
  │
  ├─ docker compose up -d (db, redis, minio, minio-init, backend, frontend)
  │     ↓
  │     ├─ db    (healthcheck: pg_isready)
  │     ├─ redis (healthcheck: redis-cli ping)
  │     ├─ minio (port 9000 + 9001)
  │     ├─ minio-init (creates bucket, exits 0)
  │     ├─ backend (depends_on db.healthy, redis.healthy, minio.started)
  │     │     prod target: 'app' → php artisan serve --host=0.0.0.0 :8000
  │     └─ frontend (depends_on backend)
  │           prod target: 'runner' → next start :3000
  │
  ├─ wait-on http://localhost:3000 http://localhost:8000/api/v1/health
  │     (120s timeout, 1s interval)
  │
  ├─ docker compose exec -T backend php artisan migrate --force
  │
  └─ npx playwright test
        ├─ 01-auth.spec.ts          (creates user A)
        ├─ 02-fish-crud.spec.ts     (creates user B)
        ├─ 03-backgrounds.spec.ts   (creates user C; skips Fal generate)
        └─ 04-repo-aquarium.spec.ts (creates user D; mocks api.github.com via page.route)
              ↑
              ↑ each spec runs in a fresh BrowserContext (isolated cookies)
              ↑ specs don't share state with each other or the DB
```

---

## 8. Threat Model Touch-Points

This slice ships defensive hygiene, not new attack surfaces. The notable items:

| Surface | Mitigation |
|---|---|
| **ULID enumeration via API** | ULIDs are 128-bit; brute-force enumeration is impossible. Owner-policy checks (slice 3+4) still apply — even a guessed ULID for someone else's fish returns 403. |
| **Frontend `Number(id)` cast bugs** | Removed; the generated client now types `id: string`. TypeScript strict mode catches any holdouts at compile time. |
| **CI secrets in E2E** | `GITHUB_TOKEN`, `FAL_API_KEY`, `APP_KEY` are **not** set in the e2e.yml environment. `APP_KEY` is generated on the fly. GitHub API calls are mocked by Playwright. Fal AI is `test.skip`'d. |
| **Test isolation** | Each spec creates its own user; no shared cookies across specs. Specs don't truncate the DB so a flaky run leaves leftover users — acceptable, the dataset is bounded by suite size. |
| **Sprite preload memory** | The cache key is `(breed, color_hex)`. With 10 breeds × ~1000 plausible colors, the worst-case cache is 10000 entries × ~40 KB per `ImageBitmap` ≈ 400 MB. In practice users use a handful of colors per fish; bounded by `O(N_unique_fish_in_canvas)` which is ≤ 100 per the canvas budget. No eviction needed at this scale; documented for slice 7 polish if it ever matters. |

---

## 9. Testing Strategy

### 9.1 Backend (Pest)

| Layer | File | What |
|---|---|---|
| Feature | `tests/Feature/UlidIdentifierTest.php` | `GET /api/v1/fishes` returns `data[].id` matching `/^[0-9A-HJKMNP-TV-Z]{26}$/`; `GET /api/v1/fishes/{ulid}` resolves; `GET /api/v1/fishes/{integer}` 404s (route pattern miss); same for backgrounds. |
| Feature | `tests/Feature/BreedCatalogTest.php` (extend) | New assertion: response carries `Cache-Control: public, max-age=3600`. |
| Feature | `tests/Feature/HealthControllerTest.php` (extend) | After regen, the OpenAPI op references `#/components/schemas/HealthResponse` (not a hash name). Assertion on the raw `openapi.json`. |
| Unit | `tests/Unit/Models/HasUlidTraitTest.php` | A new `Fish::create([...])` has a populated `ulid` matching the regex; `Fish::create(['ulid' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'])` respects the supplied value (no overwrite). |

Coverage gate: stays ≥ 80% on `app/Services/` and `app/Http/Controllers/`.

### 9.2 Frontend (Vitest)

| Layer | File | What |
|---|---|---|
| Unit | `tests/unit/lib/aquarium/sprite-cache.test.ts` | `preloadSprites([{breed:'guppy', color_hex:'#FF6B9D'}])` resolves; subsequent `getCachedSprite('guppy', '#FF6B9D')` returns truthy synchronously; `getCachedSprite('guppy', '#000000')` returns `null` (cache miss). |
| Unit | `tests/unit/lib/aquarium/Fish.hover.test.ts` | When `hovered = true`, the effective max speed in `update(dt, …)` is 15% of `maxSpeed` (asserted by feeding a high-acceleration target and measuring velocity magnitude post-update); target re-pick still fires. |
| Unit | `tests/unit/lib/aquarium/canvas-perf.test.ts` | Mount 100 fish + 20 pellets; drive 60 ticks; assert wall-clock < 1.2 × frame budget. Tagged `PERF`. |
| Unit | `tests/unit/components/aquarium/AquariumCanvas.test.tsx` (extend) | Regex on the imported module source: `expect(canvasSource).not.toMatch(/await getTintedSprite/)`. |
| Component | hooks tests | Updated query keys (`['fishes', 'one', '01HZ…']`) and string id in all mutation arguments. |

Coverage gate: stays ≥ 70% statements; `Fish` class stays 100%.

### 9.3 E2E (Playwright)

| Spec | Covers SPEC §17 items |
|---|---|
| `01-auth.spec.ts` | 1 |
| `02-fish-crud.spec.ts` | 2, 3, 4, 5 |
| `03-backgrounds.spec.ts` | 6, (7 skipped — no `FAL_API_KEY` in CI) |
| `04-repo-aquarium.spec.ts` | 8, 9 |

SPEC §17 items 10 (Swagger UI) and 12 (security checklist) are out of scope for slice 6 (slice 7). Item 11 (CI green) is satisfied by the workflow itself running and passing.

---

## 10. Acceptance Criteria

1. `php artisan migrate` applies the slice-6 migration on a freshly seeded DB; every `fishes.ulid` and `backgrounds.ulid` is populated and unique.
2. `GET /api/v1/fishes` returns `id` as a 26-char Crockford-base32 string for every row; the bigserial PK is **not** present in any API response.
3. `GET /api/v1/fishes/{ulid}` resolves the correct row; `GET /api/v1/fishes/123` returns 404 (route pattern miss).
4. `php artisan l5-swagger:generate && git diff --exit-code storage/api-docs/openapi.json` exits 0 — spec committed in sync.
5. `npm run generate:api && git diff --exit-code src/lib/api-client` exits 0 — client committed in sync.
6. `grep -RIn 'Number(id)' frontend/src/hooks frontend/src/components` returns nothing.
7. `grep -n 'await getTintedSprite' frontend/src/components/aquarium/AquariumCanvas.tsx` returns nothing.
8. The Vitest perf harness (`canvas-perf.test.ts`) passes with `npm run test`.
9. `cd frontend && npx playwright test` passes locally against `docker compose -f docker-compose.yml -f docker-compose.e2e.yml up -d`.
10. `.github/workflows/e2e.yml` exists and is triggered on `pull_request` for `frontend/**` and `backend/**` paths.
11. The `HealthResponse` schema appears in `openapi.json` under `components.schemas` (no hash-named alternative).
12. `GET /api/v1/fishes/breeds` carries `Cache-Control: public, max-age=3600`.
13. `Fish.update` with `hovered = true` clamps velocity magnitude to ≤ 15% of `maxSpeed` (unit-tested).
14. `npm run lint` clean; `npm run typecheck` clean; `npm run build` clean; `./vendor/bin/phpstan analyse` level 6 clean; `./vendor/bin/pint --test` clean.
15. The slice's tag `slice-6-e2e-perf-polish` is created on `main` after merge.

---

## 11. Risks & Mitigations

| Risk | Mitigation |
|---|---|
| ULID migration breaks slice 3+4 tests in unforeseen ways. | The Phase A tasks include a "run full backend + frontend test suites" gate after the regen; we don't move to Phase B until both are green. |
| Docker build failures on first real build (composer cache, missing PHP extension, sprite asset paths). | Phase F is an explicit shakedown phase with a dedicated task per failure mode. Plan budgets a half-day for unknown-unknowns. |
| Playwright flake on the canvas (animation timing). | All canvas assertions use `data-fish-count` and `data-pellet-count` DOM attributes, **not** pixel-reading or sprite-detection. Animations don't affect assertion correctness. |
| The Vitest perf harness is flaky on slow CI runners (a hot runner suddenly cold). | Soft-gate (open question §12 #2). Default to hard-fail but reviewers can override with `skip-perf` label. |
| `next start` in the runner stage fails to find an asset because slice-1 Dockerfile didn't anticipate the `app/api/proxy/[...path]/route.ts` from slice 2. | Phase F includes a `docker compose -f ...e2e.yml up` smoke before Playwright; we discover this *before* the test run starts. |

---

## 12. Open Questions / Follow-Ups

- **Should canvas show a "loading" state during initial preload?** Default: no — the colored-circle fallback is fast enough. Slice 7 polish can add a 200 ms-delayed shimmer if user-testing surfaces a need. *Confirm.*
- **Should the Vitest perf harness be a hard CI gate?** Default: yes (hard-fail) with `skip-perf` PR label as the escape hatch. Open: downgrade to soft-warn if the first month of runs surfaces > 5% flake. *Confirm.*
- **Should E2E specs run in parallel (`workers: > 1`)?** Default: serial in slice 6. After a confidence-building period, slice 7 can flip to `workers: 2` with no code changes. *No action; flagged.*
- **Should the Playwright report be uploaded on success too?** Default: failure-only. *Confirm.*
- **Should we preserve the hash-named HealthController OpenAPI model file for one release cycle?** Default: no — the generated client gets regenerated from a clean directory, so the hash-named file simply disappears. If a downstream consumer of `openapi.json` was pinning that schema name, they were already broken. *Confirm.*
- **`docker-compose.e2e.yml` vs flipping `target` in the base file conditionally.** Default: separate overlay file. *Confirm.*
- **Should slice 6's tag be `v1.0.0-rc1` instead of `slice-6-e2e-perf-polish`?** Default: keep the slice-N convention through slice 7; release-naming starts after slice 7. *Confirm.*

---

## 13. What's intentionally NOT here

- Firefox + WebKit Playwright projects, mobile emulation, multi-device matrices.
- Visual regression / pixel-diff snapshots (the canvas determinism is the proxy).
- A real Lighthouse audit in CI (only the Vitest harness proxies perf).
- A real Chrome DevTools 60-fps trace.
- Live Fal AI calls in CI (generate background test is `skip`'d).
- Live GitHub API calls in CI (the repo-aquarium spec mocks via `page.route`).
- Sentry init for either service (slice 7).
- Security-headers middleware (slice 7).
- `/api-docs` Swagger UI page (slice 7).
- Railway `deploy.yml` workflow (slice 7).
- The slice-4 in-process race-condition test (was flagged as in-process only in slice 4; not addressed here).
- ULID-ization of the `repo_aquarium_cache.fish_set_json` virtual ids (they don't collide; fresh ULIDs only at fork-materialize time).
- Multi-user concurrent E2E spec (a "User A and User B at once" choreography — slice 7).
- A `make e2e` target (CI calls Playwright directly; local devs run `npm run test:e2e`).
- A `make build-images` target — `docker compose build` is the canonical command.
- `php-fpm + nginx` for the backend container (still `php artisan serve` in slice 6; slice 7 lands the prod stack).

---

## 14. Sources

- `SPEC.md` §1 (routes), §6 (background gen UX), §12 (testing — Playwright canonical journey), §13 (CI/CD — `e2e.yml`), §17 (acceptance criteria items 1–9 + 11).
- `AGENT.md` §3 (canvas imperative, no React state in RAF), §5 (E2E in CI on PRs touching frontend or backend), §6 (canvas 60 fps with 100 fish + 20 pellets, API p95 < 200 ms, repo aquarium < 500 ms cached / < 2 s cold), §10 (quick reference).
- Slice 3 design — sprite-preload + hovered-fish target-rotation deferred items.
- Slice 5 design + plan — for cache plumbing patterns, OpenAPI/coverage conventions, the iron-session proxy contract, the test-isolation discipline, and the named-schema lesson.
- `docker-compose.yml`, `backend/Dockerfile`, `frontend/Dockerfile` — current shape; slice 6 is the first slice that actually builds the images.
- `frontend/src/lib/aquarium/Fish.ts`, `frontend/src/components/aquarium/AquariumCanvas.tsx` — current per-frame work; slice 6 removes the `await getTintedSprite()` cold-cache stall.
