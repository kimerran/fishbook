# Fishbook — Slice 5: GitHub Repo Aquarium Design

**Date:** 2026-05-16
**Slice:** 5 of 7 (GitHub Repo Aquarium)
**Status:** Approved — ready for implementation plan
**Depends on:** Slice 4 (Backgrounds) — `slice-4-backgrounds` tag.

Behaviour governed by [`SPEC.md`](../../../SPEC.md) §1 (the `/[username]/[repo]` route), §2.4 (GitHub Repo Aquarium API), §3 (`repo_aquarium_cache` table + the `source` / `source_ref` columns already shipped on `fishes` in slice 3), §5 (the **full** GitHub → Aquarium mapping: stats consumed, logarithmic tier scaling, fish allocation table per tier, color derivation, the determinism contract), §9 (GitHub service config), §10 (env vars), §12 (testing — `RepoAquariumGenerator` snapshot for `vercel/next.js`), §16 (security: `owner`/`repo` regex, log scrubs, rate limits), §17 (acceptance items **8** and **9**). Engineering practice by [`AGENT.md`](../../../AGENT.md) §3 (services testable, `Http` injection), §4 (input validation, log scrubs), §5 (mocked `Http` for `GithubStatsClient`, snapshot test for `RepoAquariumGenerator`), §6 (cached < 500 ms, cold < 2 s, always cache). Visual language by [`BRAND.md`](../../../BRAND.md) §5 (glass-md surfaces), §10 (chips, modal/dock patterns), §11 (canvas legibility). When this file is ambiguous, those win.

---

## 1. Context

Slices 1–4 give us: a monorepo with Postgres + Redis + MinIO behind docker compose; a Laravel 13 backend with Sanctum auth, a `fishes` table already carrying `source` and `source_ref` columns (slice 3); a `FishPolicy`, a generated TS client; a Next.js 16 frontend with an iron-session proxy at `/api/proxy/[...path]` that injects the bearer token from the HttpOnly cookie for authed browser traffic; a `/fish` page mounting `AquariumCanvas` at 60 fps; a `BackgroundLayer`; and a Radix-glass `BackgroundPanel`. The `seeded-random.ts` (`mulberry32` + `hashStringToSeed`) primitives exist and are used by `Fish` to seed per-fish wander.

Slice 5 makes the **public GitHub-repo aquarium** work end-to-end. After this slice:

- The `repo_aquarium_cache` table exists with all SPEC §3 columns, a unique `(owner, repo)` index, and no soft-delete (cache rows are overwritten or expired, not "deleted").
- A `GithubStatsClient` fetches the seven SPEC §5 stats (stars, forks, issues, watchers, contributors, language, age_days) from GitHub's REST API, optionally bearer-authed via `GITHUB_TOKEN`, with the timeout + retry budget AGENT.md §6 mandates.
- A `RepoAquariumGenerator` deterministically derives a `fish_set` (≤ 100 entries) from `(owner, repo, stats)`. **Same input → identical output**, asserted by a snapshot test against a hand-fabricated `vercel/next.js` stats fixture.
- A `RepoAquariumService` orchestrates the three-tier cache: **L1 Redis (10 min) → L2 `repo_aquarium_cache` DB (durable) → L3 GitHub API**, with a Redis lock around the L3 path to prevent stampedes.
- `GET /api/v1/repos/{owner}/{repo}/aquarium` is **public** (no `auth:sanctum`), `throttle:api` (60/min), returns `{stats, fish_set}` per SPEC §2.4.
- `POST /api/v1/repos/{owner}/{repo}/fork-to-my-aquarium` is **authed**, idempotent (skips fish whose `source_ref` already exists for this user), wrapped in `DB::transaction`, returns `{added: int}`.
- The frontend ships a public Server Component at `app/[username]/[repo]/page.tsx` that fetches `/aquarium` directly from `BACKEND_INTERNAL_URL` (bypassing the iron-session proxy — this endpoint is public, no token to inject), `notFound()` on 404, and hands `{stats, fish_set, owner, repo}` to a `<RepoAquariumPage>` client component.
- `AquariumCanvas` grows one new prop, `readOnly?: boolean`. Read-only suppresses the manager dock, the AddFishDialog trigger, and any CRUD UI in tooltips; **food-dropping (canvas click) remains enabled** for visual delight.
- `RepoAquariumPage` mounts the canvas in read-only mode, seeds initial positions via slice 3's `hashStringToSeed(owner + '/' + repo)`, paints a glass-md stats panel top-right, and shows either a **"Fork to My Aquarium"** glass-md button (authed) or a **"Sign in to fork"** link to `/login?redirect=/{owner}/{repo}` (unauthed).
- SPEC §17 acceptance items **8** (public deterministic repo aquarium) and **9** (authed user can fork) are satisfied.

It deliberately stops there. Cron-warmed cache (`repo:warm-cache` artisan command), per-user repo bookmarks, materialised historical snapshots, live "star count tick" WebSockets, auth-elevated GitHub calls for private repos, and Sentry instrumentation around the GitHub client are deferred (see §11).

---

## 2. Scope

### In

**Backend**

- **Composer deps:** none new. Laravel's built-in `Illuminate\Http\Client\Factory` is the HTTP layer for GitHub calls (same pattern as `FalAiClient` in slice 4 — `Http::fake()` covers tests).
- **`config/services.php`** additions: a `github` block — `token`, `base_url` defaulting to `https://api.github.com`, `cache_ttl_seconds` defaulting to 600, `lock_ttl_seconds` defaulting to 60, `lock_block_seconds` defaulting to 5, `user_agent` defaulting to `Fishbook/1.0 (+https://fishbook.neri.ph)`. (GitHub's API requires a `User-Agent` header on every request.)
- **Migration `2026_05_16_000020_create_repo_aquarium_cache_table.php`** — bigserial `id`; `owner varchar(100) NOT NULL`; `repo varchar(100) NOT NULL`; `stats_json jsonb NOT NULL`; `fish_set_json jsonb NOT NULL`; `fetched_at timestamp NOT NULL`. Unique index `(owner, repo)`. **No `created_at`/`updated_at`/`softDeletes`** — this is a write-overwrite cache table; `fetched_at` is the only temporal signal.
- **`RepoAquariumCache` Eloquent model** — `$fillable = ['owner','repo','stats_json','fish_set_json','fetched_at']`; `$casts = ['stats_json' => 'array', 'fish_set_json' => 'array', 'fetched_at' => 'datetime']`; `public $timestamps = false`. Scope `scopeNotStale(Builder $q, int $ttlSeconds)` filters `where('fetched_at', '>=', now()->subSeconds($ttlSeconds))`. No business logic.
- **`App\Services\Github\GithubStatsClient`** — constructor injects `Illuminate\Http\Client\Factory $http` and `Illuminate\Contracts\Config\Repository $config`. Public method `fetchStats(string $owner, string $repo): array` returns:
  ```
  [
    'stars'        => int,            // stargazers_count
    'forks'        => int,            // forks_count
    'issues'       => int,            // open_issues_count
    'watchers'     => int,            // subscribers_count  (NB: SPEC §5; not watchers_count which is a duplicate of stars)
    'contributors' => int,            // parsed from /contributors?per_page=1 Link last-page
    'language'     => ?string,        // language
    'age_days'     => int,            // now() - created_at
    'fetched_at'   => Carbon,
  ]
  ```
  - Calls `GET {base_url}/repos/{owner}/{repo}` for the primary payload.
  - Calls `GET {base_url}/repos/{owner}/{repo}/contributors?per_page=1&anon=true`. Parses the `Link` header for `rel="last"` and extracts the `page=N` query parameter as the contributor count. If `Link` header absent, returns `count(body)` (0 if empty array, 1 otherwise) — a tiny repo with ≤ 1 contributor.
  - Headers on every call: `Accept: application/vnd.github+json`, `X-GitHub-Api-Version: 2022-11-28`, `User-Agent: <config>`. If `GITHUB_TOKEN` is set, adds `Authorization: Bearer <token>`. Otherwise anonymous (60 req/hr GitHub rate limit; documented).
  - Timeouts per AGENT.md §6: `connectTimeout(5)->timeout(30)->retry(2, 1000, throw: false)` with the retry-when callback returning true only on 5xx or 429.
  - **Never follows redirects** (`Http::withOptions(['allow_redirects' => false])` — SSRF defence; GitHub doesn't redirect for these endpoints in practice, but we close the door).
  - Throws `RepoNotFoundException` on HTTP 404 (private repo or non-existent — GitHub returns 404 for both, intentionally indistinguishable; we propagate that).
  - Throws `RepoForbiddenException` on HTTP 403 (rate-limited, abuse-detection, or token revoked).
  - Throws `GithubUnavailableException` on other 5xx after retries are exhausted.
- **`App\Services\Github\BreedAccentMap`** — tiny config-backed lookup. Maps GitHub language strings to accent hex colors (a 10-entry vendored map, **not** the full github-linguist JSON — SPEC §5 mentions linguist but we ship a small curated subset to avoid a 50 KB asset). Initial map: `JavaScript → #F7DF1E`, `TypeScript → #3178C6`, `Python → #3776AB`, `Ruby → #CC342D`, `Go → #00ADD8`, `Rust → #DEA584`, `PHP → #777BB4`, `Java → #ED8B00`, `C → #A8B9CC`, `C++ → #00599C`, `Shell → #89E051`. Lives in `config/services.php` `github.language_colors` so updates don't require a deploy. Method `for(?string $language): ?string`.
- **`App\Services\Github\RepoAquariumGenerator`** — constructor injects `BreedAccentMap` and the existing slice 3 `BreedCatalog` (which exposes each breed's `min_size`, `max_size`, `default_color`). Public method `generate(string $owner, string $repo, array $stats): array` returning `array<int, array{id:string, breed:string, color_hex:string, size:int, nickname:string, source:string, source_ref:string}>`.
  - **Seed.** `seed = crc32($owner.'/'.$repo)` (32-bit unsigned). Fed into a `Random\Randomizer` with `Random\Engine\Mt19937($seed)` so PHP gives us a stable PRNG.
    - **The seed does NOT include `fetched_at`, `stats`, or any wall-clock value.** Stats influence *counts*; the seed only governs *which* breed slots get the language-accent, what hue jitter, and the nickname suffix. This preserves the "same repo always renders the same fish" contract while still re-deriving counts when stats change.
  - **Tier math (SPEC §5):**
    ```
    private function tier(int $value, array $bps): int {
        foreach ($bps as $i => $bp) if ($value < $bp) return $i;
        return count($bps);
    }
    ```
    Breakpoints (per SPEC §5, verbatim):
    - `stars` → `[10, 50, 200, 1000, 5000, 20000, 100000]` → 0..7
    - `forks` → `[5, 25, 100, 500, 2500, 10000]` → 0..6
    - `issues` → `[1, 10, 50, 200, 1000]` → 0..5
    - `watchers` → `[5, 20, 100, 500, 2500]` → 0..5
    - `contributors` → `[1, 5, 20, 100, 500]` → 0..5
  - **Allocation table (SPEC §5, verbatim, encoded as PHP constants):**

    | Source | Tier 0 | 1 | 2 | 3 | 4 | 5 | 6 | 7 |
    |---|---|---|---|---|---|---|---|---|
    | stars → guppy | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 |
    | stars → neon_tetra (school) | 0 | 0 | 3 | 5 | 7 | 9 | 11 | 13 |
    | stars → molly (rare) | 0 | 0 | 0 | 1 | 2 | 3 | 4 | 5 |
    | stars → cherry_barb (very rare) | 0 | 0 | 0 | 0 | 1 | 2 | 3 | 4 |
    | forks → zebra_danio | 0 | 1 | 2 | 3 | 4 | 5 | 6 | — |
    | issues → otocinclus | 0 | 1 | 2 | 3 | 4 | 5 | — | — |
    | watchers → platy | 0 | 1 | 2 | 3 | 4 | 5 | — | — |
    | contributors → endler | 0 | min(N,3) | min(N,6) | min(N,10) | min(N,15) | min(N,20) | — | — |
    | age → cory_catfish | 0 if <180d, 1 if <2y, 2 if <5y, 3 otherwise | | | | | | | |

    Worst-case ≈ 65 fish. The 100-cap is a belt against future allocation-table edits.
  - **Color derivation.** For each fish slot, in order:
    1. Start from `breed.default_color`.
    2. Roll the seeded PRNG: 30% chance (`< 0.30`) → blend toward `BreedAccentMap::for($stats['language'])` at 50% alpha if non-null; otherwise keep default.
    3. Apply a small per-slot hue jitter (±6° hue rotation derived from the seeded PRNG) so fish don't all look identical.
    Output is `#RRGGBB` (7 chars), validated by a final regex assertion.
  - **Size.** Uniformly distributed within `[breed.min_size, breed.max_size]`, seeded.
  - **Nickname.** `"{breed.label}-{short}"` where `short` is the first 3 hex chars of `dechex($prng_next_int_for_slot)`, uppercased. Example: `Guppy-A4F`.
  - **`id`.** `"repo-{owner}-{repo}-{index}"` zero-padded index. **Not a DB id** — these are virtual fish; the materialisation path generates fresh DB ids.
  - **`source`/`source_ref`.** Every fish in the generated set carries `source = "github_repo"` and `source_ref = "{$owner}/{$repo}"` for the fork path to use verbatim.
  - **Hard cap.** If allocation total exceeds 100, downsample evenly across breeds (drop every Nth slot until count ≤ 100). Tested with a fixture forcing high stats.
- **`App\Services\Github\RepoAquariumService`** — constructor injects `Cache::store('redis')`, `GithubStatsClient`, `RepoAquariumGenerator`, and the config. Methods:
  - `getOrGenerate(string $owner, string $repo): array` — returns `['stats' => ..., 'fish_set' => ...]`. Note **`cached_via` is NOT in the public response** (decision §3.6); it's logged internally only.
    - **L1:** `Cache::get($this->key($owner, $repo))`. If hit, return immediately. Key: `repo_aquarium:{$owner}/{$repo}:v1` (the `v1` lets us bump on algorithm changes per SPEC §5 determinism contract).
    - **L2:** `RepoAquariumCache::where(...)->notStale($ttl)->first()`. If hit, populate L1, return.
    - **L3 (under lock):** `Cache::lock("repo_aquarium_lock:{$owner}/{$repo}", $lockTtl)->block($blockSeconds, function () { ... })`. Inside the lock, re-check L1/L2 (another worker may have just populated). Otherwise: `fetchStats` → `generate` → upsert L2 (`RepoAquariumCache::updateOrCreate(['owner','repo'], [...])`) → set L1 with `Cache::put($key, $payload, $ttlSeconds)` → return.
    - Lock failure (didn't acquire in `$blockSeconds`) → falls back to a single L3 attempt without lock (degraded mode) and logs a warning. This avoids 500s under pathological lock contention.
  - `materializeForUser(User $user, string $owner, string $repo): array` returns `['added' => int]`.
    1. Call `getOrGenerate($owner, $repo)`.
    2. Compute `$sourceRef = "{$owner}/{$repo}"`.
    3. Look up existing fish for this user with `Fish::where('user_id', $user->id)->where('source', 'github_repo')->where('source_ref', $sourceRef)->count()`. **Idempotency:** if count > 0, return `['added' => 0]` (don't double-fork; pre-existing rows mean the user already forked this repo).
    4. Wrap in `DB::transaction`: bulk insert (`Fish::insert($rows)` for performance — slips past Eloquent events, acceptable here because every column is server-derived from the generator output that we trust). Each row: `nickname, breed, color_hex, size, source='github_repo', source_ref=$sourceRef, user_id=$user->id, created_at=now(), updated_at=now()`. Return `['added' => count($rows)]`.
  - `static function isReservedRoute(string $owner): bool` — denylist `['login', 'register', 'fish', 'onboarding', 'api-docs', 'api', '_next', 'auth', 'admin']`. Used by the controller to short-circuit reserved owner names to **404** (not 400) so we don't leak the route table.
- **`App\Http\Requests\Github\RepoAquariumRouteParameters`** — not a FormRequest (the body is empty) but a **route-pattern constraint** registered in a `RouteServiceProvider`-equivalent (Laravel 13 streamlined skeleton uses `bootstrap/app.php`'s `withRouting` closure or `routes/api.php` direct `Route::pattern(...)` at the top of the file).
  - `Route::pattern('owner', '[A-Za-z0-9._-]{1,100}');`
  - `Route::pattern('repo', '[A-Za-z0-9._-]{1,100}');`
  - Misshaped owners/repos miss the route entirely → Laravel returns 404, exactly what SPEC §16 wants ("leak nothing about reserved routes").
- **`App\Http\Controllers\Api\V1\RepoAquariumController`** — two thin actions.
  - `show(string $owner, string $repo): JsonResponse`
    - If `RepoAquariumService::isReservedRoute($owner)` → `abort(404)`.
    - `try { $payload = $service->getOrGenerate($owner, $repo); return new RepoAquariumResource($payload); }`
    - `catch (RepoNotFoundException) { abort(404); }`
    - `catch (RepoForbiddenException) { abort(403, 'Repository is private or rate-limited.'); }`
  - `fork(string $owner, string $repo, Request $r): JsonResponse`
    - `try { $result = $service->materializeForUser($r->user(), $owner, $repo); return response()->json($result, 201); }`
    - Same exception mapping. (404 if the underlying repo is gone.)
- **Named OpenAPI schemas + explicit `operationId`:**
  - `RepoStats` (stars, forks, issues, watchers, contributors, language, age_days, fetched_at)
  - `RepoFishItem` (id, breed, color_hex, size, nickname, source, source_ref)
  - `RepoAquariumResponse` (data: { stats: RepoStats, fish_set: RepoFishItem[] })
  - `RepoForkResponse` (added: integer)
  - `RepoAquariumErrorResponse` (message: string)
  - Operations: `getRepoAquarium`, `forkRepoAquarium`.
- **`routes/api.php`** updates:
  - **Public** group (no `auth:sanctum`): `GET /repos/{owner}/{repo}/aquarium` with `throttle:api`.
  - **Authed** group: `POST /repos/{owner}/{repo}/fork-to-my-aquarium` with `throttle:api` and `auth:sanctum`.
  - Both routes inherit the `Route::pattern` constraints declared at the top of the file.
- **Pest feature tests** (`tests/Feature/Github/`):
  - `GetRepoAquariumTest` — happy path (Http::fake, cold-cache → fresh; warm-cache → bypass GitHub); 404 (`RepoNotFoundException`); 403 (`RepoForbiddenException`); reserved-route 404 (`/api/v1/repos/login/whatever/aquarium`); regex-fail 404 (`/api/v1/repos/foo$bar/repo/aquarium` — pattern miss); cached_via not in response body (decision §3.6); rate-limit hit at the 61st call/min → 429.
  - `ForkRepoTest` — 401 unauthed; 201 with `added=N` on first fork; 201 with `added=0` on second fork (idempotency); inserted rows have `source='github_repo'`, `source_ref="vercel/next.js"`; 404 if repo no longer exists; soft-deleted prior fork (user soft-deletes one, refetches, then forks again) is **NOT** treated as an existing fork — *but* this is documented as a tiny known limitation: idempotency keys on non-trashed rows only. (Open question 4 invites refinement.)
  - `RepoAquariumRaceConditionTest` — fire two concurrent requests via Laravel's testing parallel runner; assert GitHub fake was hit at most once. (Implemented by spying on `Http::fake()` call count.)
  - `RepoAquariumSnapshotTest` — feeds a hand-fabricated `vercel/next.js` stats fixture (`tests/fixtures/repo_aquarium/vercel-nextjs-stats.json`) into `RepoAquariumGenerator::generate`, compares output to `tests/fixtures/repo_aquarium/vercel-nextjs-fish.json`. **Byte-for-byte determinism contract.**
- **Pest unit tests** (`tests/Unit/Services/Github/`):
  - `GithubStatsClientTest` — happy path (200 on `/repos/...` + 200 with `Link` header on `/contributors`); 404 → `RepoNotFoundException`; 403 → `RepoForbiddenException`; 5xx → retries 2x then `GithubUnavailableException`; Link-header parsing (extracts `page=42` correctly); Link absent + empty contributors body → returns 0; Link absent + 1-entry body → returns 1; Bearer header present when `GITHUB_TOKEN` set; anonymous (no Authorization header) when unset; **the `Authorization` header value never appears in any captured log record** (asserts via Monolog memory handler).
  - `RepoAquariumGeneratorTest` — determinism (same input twice → identical output, `assertSame` with `JSON_THROW_ON_ERROR`); tier-boundary tests (value just below each breakpoint vs just above, per stat); 100-cap (synthetic max-stats fixture → output count ≤ 100); language-accent assignment (~30% of fish carry an accent within ±2% over a 1000-slot fixture); nickname format (`/^[A-Z][a-zA-Z _]+-[0-9A-F]{3}$/`); `id` format (`/^repo-[^-]+-.+-\d+$/`).
  - `RepoAquariumServiceTest` — L1 hit returns instantly (no `GithubStatsClient` invocation); L2 hit promotes to L1; L3 path writes to L2 and L1; lock acquired-elsewhere fallback path logs warning; `materializeForUser` idempotent (two calls → `added` then `0`); `materializeForUser` inserts correct `source`/`source_ref`.
- **Coverage:** ≥ 80% on the new `app/Services/Github` directory (added to the phpunit `<coverage>` include list alongside the slice 4 directories).

**Frontend**

- **No new deps.** The repo aquarium reuses slice 3's `AquariumCanvas`, `Fish`, `seeded-random`. The "Fork to My Aquarium" button is a Tailwind glass-md button — no new component library.
- **`app/[username]/[repo]/page.tsx`** — Server Component (`'use client'` is **not** at the top).
  - Pre-flight regex validation: `^[A-Za-z0-9._-]{1,100}$` on both `username` and `repo` (the same regex the backend enforces). On mismatch → `notFound()`.
  - Calls the backend **directly** via `fetch(`${process.env.BACKEND_INTERNAL_URL}/repos/${owner}/${repo}/aquarium`, { cache: 'no-store' })`. **Bypasses the iron-session proxy** — this endpoint is public, there is no token to inject (decision §3.3).
  - On `404` → `notFound()`. On `403` → render a small inline "This repository is private or temporarily unavailable." card (no full crash page). On `5xx` or network error → bubble to Next.js's error boundary, which renders the slice-1 generic 500 page.
  - On success → pass `{stats, fish_set, owner, repo, isAuthed}` to `<RepoAquariumPage>`. `isAuthed` is computed server-side from the iron-session cookie (using the slice-2 `getSession()` helper) so the page renders the correct CTA without a client-side flash.
- **`components/repo-aquarium/RepoAquariumPage.tsx`** — `'use client'`. Layout:
  - **Background:** slice 1 radial gradient (no user-specific background since the visitor may be unauthed; the page deliberately does NOT call `useBackgroundsQuery`).
  - **Canvas:** `<AquariumCanvas fishes={fish_set} breeds={breeds} readOnly />`. Breeds are fetched at module init via `useBreedsQuery()` — the breed catalog endpoint is already public from slice 3.
  - **Stats panel (top-right, glass-md, BRAND.md §10.7):** label-caps rows.
    ```
    {owner}/{repo}
    ⭐ {stars}   🍴 {forks}   🐛 {issues}
    👀 {watchers}   👥 {contributors}   💬 {language ?? '—'}
    ```
    Emoji-light per decision §3.7 (open question 5 invites strict-text).
  - **Fork CTA (bottom-right, glass-md button):**
    - Authed: button text "Fork to My Aquarium". Click → `useForkRepoMutation(owner, repo)` → on success, inline toast "Added {added} fish to your aquarium." Stay on the page (decision §3.5; open question 1 invites redirect-to-`/fish`). Invalidate the `useFishesQuery` cache so a future visit to `/fish` reflects the new rows immediately.
    - Unauthed: anchor `Link` to `/login?redirect=/{owner}/{repo}` with the same glass-md styling.
  - **Read-only canvas behavior** (new `readOnly` prop on `AquariumCanvas`):
    - Suppresses the slice 3 dock buttons ("Manage", "Backgrounds", "Add Fish") — those don't render on this page anyway (only `/fish` mounts them), but the prop is the future-proof contract.
    - Suppresses the `HoverTooltip`'s Edit / Delete actions if those exist on the hovered fish.
    - **Food-dropping STAYS enabled** — clicking the canvas drops a `FoodPellet`, fish swim to it. Pure visual delight; no persistence. Decision §3.4.
- **`hooks/use-fork-repo-mutation.ts`** — TanStack Query mutation. POSTs through the iron-session proxy to `/api/proxy/repos/{owner}/{repo}/fork-to-my-aquarium`. (Authed traffic uses the proxy as established in slice 2.) On success, invalidates `['fishes','list']`.
- **`hooks/use-breeds-query.ts`** — already shipped in slice 3 for `/fish`; reused here as-is.
- **No `useRepoAquariumQuery` hook.** The server component already has the data; passing it through React state into the client component is the cleanest path. (Avoids a re-fetch in the browser.) If a future refresh-on-focus is wanted, slice 7 can add the hook.
- **Reserved-route note.** Next.js dynamic routes are last-priority — static routes (`/login`, `/register`, `/fish`, `/onboarding`, `/api-docs`) win at the frontend layer. The dynamic `/[username]/[repo]` only catches **two-segment** paths, so `/login` (one segment) and `/login/garbage` (matches `[username]/[repo]` with `username=login`) are the cases worth thinking about. The second is the one the backend defends against via `isReservedRoute`.
- **Tests** (`frontend/tests/unit/`):
  - `components/repo-aquarium/RepoAquariumPage.test.tsx` — renders stats panel; renders Fork button when `isAuthed=true`; renders Sign-in link when `isAuthed=false`; click Fork → mutation called once → success toast.
  - `components/aquarium/AquariumCanvas-readOnly.test.tsx` — `readOnly` prop suppresses CRUD UI (asserts no Manager dock, no AddFishDialog trigger); food-pellets still spawn on click (`pellets.length` increases after `mousedown`).
- **Coverage:** stays ≥ 70% statement floor; new files target ≥ 80%.

**Cross-cutting**

- **`backend/.env.example`** already has `GITHUB_TOKEN=` from slice 1. No additions.
- **`AppServiceProvider::boot()`** — no new rate limiter named groups (the existing `throttle:api` 60/min suffices).
- **AGENT.md §4 logging scrubs** — verify the slice 2 / slice 4 Monolog processor lists `Authorization`, `Bearer `, `Key `. The `GithubStatsClientTest` asserts no `Bearer <token>` substring in captured records.
- **Regenerate** `backend/storage/api-docs/openapi.json` AND `frontend/src/lib/api-client/`. Both committed.
- **phpunit coverage** picks up `app/Services/Github`. Add to the `<include>` glob.
- **No CI changes.** Existing `backend.yml` / `frontend.yml` / `e2e.yml` workflows cover this slice.
- **Final tag:** `slice-5-repo-aquarium`.

### Out (deferred)

- **Cron-warmed cache.** A `repo:warm-cache {owner} {repo}` artisan command + a `routes/console.php` scheduled invocation for the project's "featured repos" list would let popular repos always serve from L1. Defer to slice 7 polish.
- **Auth-elevated GitHub calls** (private repos visible to the authenticated GitHub-user). This slice ships **public-only**: the endpoint accepts no GitHub user OAuth, only the project's server-side `GITHUB_TOKEN`. Authed Fishbook users can fork *public* repos but cannot view private ones. Out.
- **Materialised historical snapshots** ("see this repo as of 2024"). Defer — the `stats_json` column could backfill this but no UI ships.
- **Per-user repo bookmarks / favorites.** Defer.
- **WebSockets for live repo-event streams** (star ticker, new-issue confetti). Defer indefinitely.
- **Sentry instrumentation** around `GithubStatsClient` (custom transaction spans for the three-tier cache). Defer to slice 7.
- **Replacing the curated language-color map with the full github-linguist JSON.** ~50 KB asset, ~600 languages; SPEC §5 mentions it as a possibility but the curated 11-entry map covers the top languages we care about. Defer.
- **Promoting "soft-deleted prior fork" to count as already-forked.** Current idempotency check looks at non-trashed rows only. Open question 4.

---

## 3. Approach Decisions

Record load-bearing judgment calls so slice 6+ doesn't relitigate.

1. **Determinism split: backend derives identity, frontend seeds positions.** The backend's `RepoAquariumGenerator` deterministically produces `{breed, color_hex, size, nickname, id}` from `(owner, repo)` — these are the *identity* fields that appear in the cache and survive forks. The frontend's `AquariumCanvas` seeds each `Fish`'s starting `position` and wander RNG from `mulberry32(hashStringToSeed(fish.id))` — exactly the same path slice 3 uses for user-owned fish. The `fish.id` for repo aquarium fish is `repo-{owner}-{repo}-{index}`, so positions are stable across page loads without polluting the API payload. SPEC §5 determinism contract is satisfied on identity; visual stability comes for free from slice 3's existing seeding. This is **the** load-bearing decision of the slice.

2. **Three-layer cache with a Redis lock.** L1 Redis 10 min, L2 `repo_aquarium_cache` durable, L3 GitHub. The lock around L3 prevents stampedes when a popular repo (`vercel/next.js`) cold-cache-misses simultaneously across N workers. Lock TTL 60 s, block 5 s; on lock-acquisition failure, the request falls through to an unsynchronised L3 call (degraded) and a warning is logged. AGENT.md §6 "always cache" is honored.

3. **Public GitHub-aquarium endpoint bypasses the iron-session proxy.** The Server Component calls `BACKEND_INTERNAL_URL` directly. Two reasons:
   - The proxy's job is to inject a bearer token from the iron-session cookie; for a *public* endpoint, that token is irrelevant and (if the user happens to be authed) leaking it to a non-auth endpoint is wasted capability surface.
   - The proxy adds a measurable round-trip in container-network terms; for a 200-fish cache hit we want this < 50 ms.
   The fork endpoint **does** go through the proxy because it requires the bearer token. Symmetric: the proxy is for authed browser-originated traffic.

4. **Read-only canvas keeps food-dropping enabled.** Visiting `/vercel/next.js` should feel alive — clicking should still drop a pellet and the nearest fish should swim to eat it. The `readOnly` prop on `AquariumCanvas` only suppresses CRUD UI (manager dock, AddFishDialog trigger, tooltip Edit/Delete). Food persistence was never server-side anyway (slice 3 keeps it in-memory).

5. **Post-fork UX: toast + stay on the repo page.** Less jarring than a redirect to `/fish`, and the user has visible context (this repo's fish are now also mine). Open question 1 invites alternatives.

6. **`cached_via` is logged, not exposed.** The L1/L2/L3 path is useful for ops debugging but exposes cache structure. We log internally (`Log::debug`) and keep the API response surface minimal: `{stats, fish_set}` per SPEC §2.4 verbatim. Open question 2 invites a `?debug=1` flag.

7. **Stats panel uses sparse emoji** (⭐🍴🐛👀👥💬). Decoded glyphs render consistently across modern OSes; pure text would be drabber and demand more chrome. Open question 5 invites strict-text.

8. **Hand-fabricated `vercel/next.js` stats fixture.** A static JSON committed to `tests/fixtures/repo_aquarium/vercel-nextjs-stats.json` (e.g. 116000 stars, 25000 forks, 2400 issues, 750 watchers, 480 contributors, language `TypeScript`, age 2900 days). The corresponding `vercel-nextjs-fish.json` is generated **once** with the implemented generator and inspected by hand before committing. CI then asserts byte-equality. Live API calls in CI are fragile and not auth-stable. Open question 4 invites a "regenerate fixture" make-target.

9. **`subscribers_count` is the "watchers" stat.** SPEC §5 lists `subscribers_count` (watchers) and `contributors_count` (contributors) as separate stats. GitHub's API returns *both* `watchers_count` (a duplicate of `stargazers_count` — historical artifact) and `subscribers_count` (the actual "Watch this repo" count). We use `subscribers_count`. Documented inline.

10. **Idempotency by (`user_id`, `source='github_repo'`, `source_ref="$owner/$repo"`).** A user who already forked `vercel/next.js` and calls `POST .../fork-to-my-aquarium` again gets `added=0`. The check looks at *non-trashed* rows only; if the user soft-deleted all their `vercel/next.js` fish, a re-fork *will* re-insert them. This is the intuitive behavior — "I deleted them, now I want them back." Documented; open question 4 invites refinement.

11. **The seed for the generator's PRNG is `crc32($owner.'/'.$repo)` only.** **Stats don't change the seed.** Stats only drive *counts*. This means: when a repo's star count moves from 999 to 1001 (crossing tier 3→4), one extra `molly` and three more `neon_tetra` get added — but every fish that was already there keeps its breed, color, size, nickname, and id. The cache writes a new `fish_set_json` each time, but the existing slots are stable. This is the cleanest reading of SPEC §5's "same `(owner, repo, stats)` → identical `fish_set_json`" — interpreted as "additions are deterministic; nothing changes underneath you."

12. **The generator emits fish in a stable order:** stars→guppy slots first, then stars→neon_tetra, then molly, cherry_barb, forks→zebra_danio, issues→otocinclus, watchers→platy, contributors→endler, age→cory_catfish. Within each band, indexes go 0..N. This is what makes the snapshot test reliable.

13. **Reserved-route 404, not 400.** SPEC §16 says "leak nothing about reserved routes." A 400 with "reserved" would do exactly that. 404 it is.

14. **SSRF posture.** GitHub never redirects for the repo + contributors endpoints we use, but we set `allow_redirects: false` on the `Http` client anyway. Belt-and-suspenders against a future GitHub-side or token-restricted misconfiguration that could redirect us into a private network.

15. **The 100-fish cap is enforced at generation time, not render time.** AGENT.md §6 budgets the canvas at 100 fish for 60 fps. The generator's worst-case (≈ 65) leaves headroom but the cap is the contract. If a future allocation-table edit pushes the worst-case higher, the cap catches it; the test fixture forces this.

16. **Named OpenAPI schemas + explicit `operationId`** — slice 4 lesson, repeated. Every operation gets `operationId: getRepoAquarium|forkRepoAquarium`. Schemas named `RepoAquariumResponse`, `RepoForkResponse`, `RepoStats`, `RepoFishItem`, `RepoAquariumErrorResponse`.

17. **No `useRepoAquariumQuery` on the client.** The Server Component already fetches; passing data via props is simpler and avoids hydration mismatches on the deterministic fish-id strings.

18. **The `BreedAccentMap` lives in config, not in code.** A future PR to add Kotlin or Swift doesn't require a service redeploy beyond a config bump. Same precedent as slice 4's `PromptDenylist`.

19. **Logging scrubs** include `Authorization`, `Bearer `, `Key `, `api_key`. The `GithubStatsClientTest` asserts no `Bearer <test_token>` substring in any captured Monolog record.

---

## 4. API Surface

All paths under `/api/v1`. JSON in, JSON out. Errors `{message}` per SPEC §2.6.

### 4.1 `GET /repos/{owner}/{repo}/aquarium` (public, `throttle:api`)

Route pattern constraints: `owner` and `repo` both `^[A-Za-z0-9._-]{1,100}$`. Pattern miss → 404.

Reserved-owner denylist → 404. (`login`, `register`, `fish`, `onboarding`, `api-docs`, `api`, `_next`, `auth`, `admin`.)

Response 200:
```json
{
  "data": {
    "stats": {
      "stars": 116000,
      "forks": 25000,
      "issues": 2400,
      "watchers": 750,
      "contributors": 480,
      "language": "TypeScript",
      "age_days": 2900,
      "fetched_at": "2026-05-16T12:00:00Z"
    },
    "fish_set": [
      {"id": "repo-vercel-next.js-0",  "breed": "guppy",      "color_hex": "#FF6B9D", "size": 14, "nickname": "Guppy-A4F", "source": "github_repo", "source_ref": "vercel/next.js"},
      "..."
    ]
  }
}
```

- 404 if repo not found, owner is reserved, or pattern fails.
- 403 if GitHub returns 403 (private repo or rate-limited).
- 429 if `throttle:api` (60/min/IP) trips.
- 502/503 on `GithubUnavailableException` after retries.

Caching: 10-minute TTL keyed `repo_aquarium:{owner}/{repo}:v1`. The `v1` suffix bumps when the generator algorithm changes (SPEC §5 contract).

### 4.2 `POST /repos/{owner}/{repo}/fork-to-my-aquarium` (authed, `throttle:api`)

Body: none.

Response 201:
```json
{ "added": 47 }
```

- 401 if no bearer.
- 404 if repo not found / owner reserved / pattern fails.
- 429 on rate-limit.

Idempotency: if `Fish::where(user_id, source='github_repo', source_ref='owner/repo')->exists()`, returns `{added: 0}`. Does NOT re-insert.

---

## 5. The Three-Layer Cache Flow

```
                  ┌─ request: GET /repos/owner/repo/aquarium ──┐
                  │                                            ▼
                  │                                  ┌───────────────────┐
                  │                                  │  isReservedOwner? │──── yes ─→ 404
                  │                                  └────────┬──────────┘
                  │                                           │ no
                  │                                           ▼
                  │                              ┌──────────────────────────┐
                  │                              │ L1: Redis GET            │
                  │                              │ repo_aquarium:O/R:v1     │
                  │                              └────────┬─────────────────┘
                  │                                       │ miss
                  │                                       ▼
                  │                              ┌──────────────────────────┐
                  │                              │ L2: DB                   │
                  │                              │ repo_aquarium_cache      │
                  │                              │ WHERE notStale(600s)     │
                  │                              └────────┬─────────────────┘
                  │                                       │ miss
                  │                                       ▼
                  │                              ┌──────────────────────────┐
                  │                              │ Redis::lock(             │
                  │                              │   repo_aquarium_lock:O/R,│
                  │                              │   ttl=60s)               │
                  │                              │ ->block(5s, fn() => …)   │
                  │                              └────────┬─────────────────┘
                  │                                       │ acquired
                  │                                       ▼
                  │                              ┌──────────────────────────┐
                  │                              │ re-check L1, L2          │
                  │                              │ (another worker may have │
                  │                              │  beat us to it)          │
                  │                              └────────┬─────────────────┘
                  │                                       │ still miss
                  │                                       ▼
                  │                              ┌──────────────────────────┐
                  │                              │ L3: GithubStatsClient    │
                  │                              │  GET /repos/O/R          │
                  │                              │  GET /repos/O/R/         │
                  │                              │   contributors?per_page=1│
                  │                              └────────┬─────────────────┘
                  │                                       │
                  │                                       ▼
                  │                              ┌──────────────────────────┐
                  │                              │ RepoAquariumGenerator    │
                  │                              │  ::generate(O, R, stats) │
                  │                              └────────┬─────────────────┘
                  │                                       ▼
                  │                              ┌──────────────────────────┐
                  │                              │ upsert L2                │
                  │                              │ set L1 ttl=600s          │
                  │                              └────────┬─────────────────┘
                  ▼                                       ▼
            return {stats, fish_set}             return {stats, fish_set}
```

Lock-failure branch (didn't acquire in 5 s) → falls through to a single un-locked L3 call + warning log. Pathological lock-contention shouldn't ever 500.

---

## 6. Materialization (Fork) Atomicity

```
POST /repos/owner/repo/fork-to-my-aquarium  (authed)
   │
   ▼
 getOrGenerate(owner, repo) ───────→ returns {stats, fish_set}
   │
   ▼
 sourceRef = "owner/repo"
   │
   ▼
 Fish::where(user_id, source='github_repo', source_ref=$sourceRef)
       ->exists()?
   │  yes ─→ return {added: 0}
   │  no
   ▼
 DB::transaction:
   $rows = $fish_set.map(fn => [...derive Fish columns..., user_id, timestamps])
   Fish::insert($rows)
 commit
   │
   ▼
 return {added: count($rows)}
```

- **Why bulk `Fish::insert` not `Fish::create`-per-fish?** 65 separate inserts at ~5 ms each is 300 ms wasted. `insert` is a single round-trip.
- **Why no Eloquent events?** No `Fish` model events fire today (slice 3); if slice 7 adds one (e.g. an audit log), revisit.
- **Why `Fish::where(...)->exists()` not `count()`?** Cheaper; same semantics for our boolean check.
- **Why is `source_ref` length-safe?** SPEC §3 caps it at 255; `owner + '/' + repo` is at most `100 + 1 + 100 = 201` chars.

---

## 7. Determinism Contract (the formal version)

**Definition.** Let `G(owner, repo, stats)` be `RepoAquariumGenerator::generate`. Then:

```
G(owner, repo, stats) = G(owner, repo, stats)   ; pure
G(owner, repo, stats).map(f => f.id)            ; depends only on owner, repo, and slot index
G(owner, repo, stats).map(f => f.breed)         ; depends only on stats counts
G(owner, repo, stats).map(f => f.color_hex)     ; depends on stats.language + seeded PRNG → stable per slot
G(owner, repo, stats).map(f => f.size)          ; depends on breed range + seeded PRNG → stable per slot
G(owner, repo, stats).map(f => f.nickname)      ; depends on breed + seeded PRNG → stable per slot
```

**The seed.** `seed = crc32($owner.'/'.$repo)`. **Not** time, **not** fetched_at, **not** stats values. (Stats *count* fish; the seed *colors and names* them.)

**Cache-key version.** `v1`. Bumps when the algorithm changes (e.g. allocation table edits, color blend math). Bumping invalidates Redis and DB caches.

**Frontend role.** The frontend reads `fish.id` and seeds initial position + wander PRNG from `mulberry32(hashStringToSeed(fish.id))` — slice 3's existing primitive. Stable across reloads without any position data in the API payload.

---

## 8. Threat Model Touch-Points

| Threat | Mitigation in this slice |
|---|---|
| **Owner/repo path traversal** (`../`, URL-encoded slashes) | `Route::pattern('owner|repo', '[A-Za-z0-9._-]{1,100}')` rejects at routing layer with 404. Tested with `../`, `/`, null bytes. |
| **Reserved route enumeration** | `isReservedRoute()` denylist returns 404 (not 400) for `login`, `register`, `fish`, etc. Tested. |
| **GitHub API token leak via logs** | Monolog scrubs `Authorization`, `Bearer `, `Key `. The `GithubStatsClient` test asserts no `Bearer <token>` substring in captured records. |
| **GitHub API rate-limit exhaustion** | `GITHUB_TOKEN` bumps unauth-60/hr to authed-5000/hr; three-tier cache means cold-only on cache eviction. |
| **Cache stampede on cold-cache for popular repos** | `Cache::lock("repo_aquarium_lock:O/R", 60)->block(5, …)` serializes L3 access; lock failure degrades gracefully. |
| **SSRF via 30x redirect from GitHub** | `Http::withOptions(['allow_redirects' => false])`. GitHub doesn't 30x for these endpoints, but we close the door anyway. |
| **Cache poisoning via clock skew** | `fetched_at` stored on the L2 row; reads filter `where('fetched_at', '>=', now()->subSeconds(600))` rather than trusting Redis TTL alone. |
| **DoS via repeated cold-cache requests for nonexistent repos** | 404s also get cached briefly in Redis (60 s "negative cache" — same key, sentinel value). Per-IP `throttle:api` 60/min as the second gate. |
| **Mass-forking the same repo to bloat the user's `fishes` table** | Idempotency: pre-existing `source_ref` row → `added: 0`. Tested. |
| **Cross-user fork** | The fork action is keyed to `$request->user()`; no `user_id` ever comes from the client. |
| **Repo aquarium endpoint returning private-repo data via someone's PAT** | The endpoint uses the **server's** `GITHUB_TOKEN`, scoped to public-repo reads only. The server's token must be a fine-grained PAT with **no repo access** — only public `repo:status` and `public_repo` read. Documented in `backend/.env.example` and a comment in `config/services.php`. |
| **Information disclosure via fish ids encoding owner/repo** | The fish id (`repo-vercel-next.js-0`) trivially encodes the repo path. Acceptable — this *is* a public endpoint about that repo. |
| **CSP impact** | None — same-origin server fetch, no third-party assets. |

---

## 9. Frontend Architecture Detail

### 9.1 Server vs client boundary

```
app/[username]/[repo]/page.tsx          (Server Component)
   │
   ├── pre-flight regex on params
   ├── fetch(BACKEND_INTERNAL_URL/repos/{owner}/{repo}/aquarium, no-store)
   ├── getSession() → isAuthed
   ├── notFound() on 404
   ├── inline error on 403
   └── render <RepoAquariumPage ... />
       │
       ▼
   components/repo-aquarium/RepoAquariumPage.tsx  ('use client')
       │
       ├── <AquariumCanvas fishes={fish_set} breeds readOnly />
       ├── <StatsPanel stats={stats} owner={owner} repo={repo} />
       └── <ForkCta isAuthed owner={owner} repo={repo} />
            └── on click → useForkRepoMutation → invalidate ['fishes','list'] → toast
```

The Server Component does the data fetch (server-side, cheap, no client JS for the load) and the client component owns animation and interaction.

### 9.2 Why no `useRepoAquariumQuery`

The page is a Server Component with `cache: 'no-store'`. TanStack Query on the client would re-fetch on mount, doubling load. The initial props are already fresh. If slice 7 wants window-focus refresh, *then* add a hook seeded from `initialData`.

### 9.3 Canvas read-only contract

```ts
// frontend/src/components/aquarium/AquariumCanvas.tsx (diff)
export function AquariumCanvas({
  fishes,
  breeds,
  readOnly = false,        // ← new
}: {
  fishes: FishDTO[];
  breeds: Breed[];
  readOnly?: boolean;
}) {
  // ... existing canvas + RAF loop ...
}
```

`readOnly` is consumed only by the slice 3 callers that render the dock/manager — they read the prop via the `useAquariumStore` (a small store boolean), and conditionally render. In this slice, `RepoAquariumPage` passes `readOnly`; `/fish` continues to pass it as `false` (default). The canvas itself doesn't change visually — the read-only behavior lives in the surrounding chrome.

### 9.4 Stats panel placement

```
┌──────────────────────────────────────────────┐
│                                  ┌─────────┐ │
│                                  │  STATS  │ │   <-- glass-md, top-right
│                                  │  PANEL  │ │
│                                  └─────────┘ │
│                                              │
│         (full-viewport <canvas>)             │
│                                              │
│                                              │
│                                              │
│                                ┌───────────┐ │
│                                │ Fork CTA  │ │   <-- glass-md, bottom-right
│                                └───────────┘ │
└──────────────────────────────────────────────┘
```

Both panels are `position: fixed`. The stats panel reserves the top-right corner; the dock area on `/fish` (which would normally be bottom-right too) is suppressed by `readOnly`, so the Fork CTA owns that slot uncontested.

---

## 10. Testing Strategy

### 10.1 Backend (Pest)

| Layer | File | What |
|---|---|---|
| Feature | `tests/Feature/Github/GetRepoAquariumTest.php` | Happy path with `Http::fake` (cold → fresh; warm → no GitHub hit asserted via `Http::assertNothingSent` after a primer call); 404 (`RepoNotFoundException`); 403 (`RepoForbiddenException`); reserved-route 404 (`/api/v1/repos/login/whatever/aquarium`); regex 404 (`/api/v1/repos/foo$bar/repo/aquarium`); `cached_via` NOT in response body; 429 after 61 calls; response shape matches `RepoAquariumResponse` schema. |
| Feature | `tests/Feature/Github/ForkRepoTest.php` | 401 unauthed; 201 + `added=N` on first fork; 201 + `added=0` on second (idempotency); inserted rows have correct `source`/`source_ref`/`user_id`; 404 if underlying repo missing; `DB::transaction` rollback on insert failure (forced via DB exception); auth via Sanctum token. |
| Feature | `tests/Feature/Github/RepoAquariumSnapshotTest.php` | Feeds `tests/fixtures/repo_aquarium/vercel-nextjs-stats.json` into the generator; compares output to `tests/fixtures/repo_aquarium/vercel-nextjs-fish.json` byte-exact. Pinning test for SPEC §5 determinism contract. |
| Feature | `tests/Feature/Github/RepoAquariumRaceTest.php` | Spawns two parallel requests against the same cold-cache repo; asserts `Http::recorded(...)` shows exactly one GitHub call (lock serialized them). |
| Unit | `tests/Unit/Services/Github/GithubStatsClientTest.php` | Happy path; 404 → `RepoNotFoundException`; 403 → `RepoForbiddenException`; 5xx retry-then-fail → `GithubUnavailableException`; Link parsing (page=42 extraction); Link absent + empty body → 0 contributors; Link absent + 1-entry body → 1; Bearer header present when env set; Bearer absent when env unset; **no `Bearer ` substring in captured logs**; `allow_redirects: false` honored. |
| Unit | `tests/Unit/Services/Github/RepoAquariumGeneratorTest.php` | Determinism (call twice, `assertSame`); tier boundaries (`tier(9, [10,…]) === 0`, `tier(10, [10,…]) === 1`, etc., per stat); 100-cap (max-stats fixture → count ≤ 100); language-accent assignment statistically ≈ 30% over 1000 slots; nickname regex; id regex; `source='github_repo'`/`source_ref` on every fish. |
| Unit | `tests/Unit/Services/Github/RepoAquariumServiceTest.php` | L1 hit → no client call; L2 hit promotes to L1; L3 path writes both tiers; lock-fail degraded warning logged; `materializeForUser` first-call insert + second-call `added=0`. |

Coverage gate: ≥ 80% on `app/Services/Github`. Project-wide gate from slice 4 stays.

### 10.2 Frontend (Vitest)

| Layer | File | What |
|---|---|---|
| Component | `tests/unit/components/repo-aquarium/RepoAquariumPage.test.tsx` | Stats panel renders correct labels + values; Fork button renders when `isAuthed=true`; Sign-in link renders when `isAuthed=false`; Fork click → mutation called once → success toast; mutation error → error toast. |
| Component | `tests/unit/components/aquarium/AquariumCanvas-readOnly.test.tsx` | `readOnly` suppresses CRUD UI; food-pellet still spawns on `mousedown`. |
| Unit | `tests/unit/lib/repo-aquarium/regex.test.ts` | Owner/repo regex accepts valid; rejects `../`, `foo$bar`, 101-char string, empty string. |

Coverage gate: 70% statement floor.

### 10.3 Out

- Playwright E2E for the repo-aquarium journey — slice 7 polish.
- Live GitHub API tests in CI — explicitly forbidden (use `Http::fake()` only).
- Visual regression on the canvas (the determinism contract is the identity guarantee; pixel diffs would be too brittle on tinted sprites).

---

## 11. Acceptance Criteria

1. `GET /api/v1/repos/vercel/next.js/aquarium` returns 200 with `{data: {stats, fish_set}}` matching `RepoAquariumResponse` schema; `fish_set.length ≤ 100`; every fish has `source='github_repo'` and `source_ref='vercel/next.js'`.
2. Same endpoint called twice within 10 min hits Redis (asserted via `Http::assertNothingSent` after the first call).
3. `GET /api/v1/repos/login/whatever/aquarium` returns 404 (reserved-route).
4. `GET /api/v1/repos/foo$bar/repo/aquarium` returns 404 (regex miss).
5. `GET /api/v1/repos/this-repo/does-not-exist/aquarium` returns 404 (`RepoNotFoundException`).
6. `POST /api/v1/repos/vercel/next.js/fork-to-my-aquarium` unauthed returns 401.
7. Same endpoint authed returns 201 + `{added: N}` on first call; second call returns `{added: 0}` (idempotency); inserted rows have `source='github_repo'` and `source_ref='vercel/next.js'`.
8. The `RepoAquariumGenerator` snapshot test passes byte-exact for the `vercel/next.js` fixture.
9. The cold-cache race test asserts exactly one GitHub HTTP call for two concurrent requests.
10. Visiting `/vercel/next.js` (Next.js dev server) renders the canvas with N fish swimming; the stats panel shows the stat values; an authed visitor sees the Fork button; an unauthed visitor sees the Sign-in link.
11. The `readOnly` canvas test asserts food-pellets still spawn on click and no CRUD UI mounts.
12. `php artisan l5-swagger:generate && git diff --exit-code storage/api-docs/openapi.json` exits 0.
13. `npm run generate:api && git diff --exit-code src/lib/api-client` exits 0.
14. Backend coverage ≥ 80% on `app/Services/Github`; project gate stays green.
15. Frontend coverage ≥ 70% statements.
16. `npm run lint`, `npm run typecheck`, `npm run build` clean; `./vendor/bin/phpstan analyse` level 6 clean; `./vendor/bin/pint --test` clean.
17. CI does NOT call GitHub — every Github test uses `Http::fake()`.
18. The `Authorization: Bearer …` header value never appears in any log record captured by the unit-test memory handler.

---

## 12. Open Questions / Follow-Ups

- **Post-fork UX: toast + stay vs redirect to `/fish`.** Default: toast + stay. *Confirm before merge.*
- **Expose `cached_via` ("redis" | "db" | "fresh") in the API response?** Default: no (log only). Open: gate on `?debug=1` + admin auth, slice 7. *Confirm.*
- **`vercel/next.js` fixture: hand-fabricated static JSON vs a `make refresh-fixtures` target that calls the live API once.** Default: hand-fabricated. *Confirm.*
- **Stats panel: sparse emoji (⭐🍴🐛👀👥💬) vs strict text labels.** Default: emoji. *Confirm.*
- **Fork idempotency: should soft-deleted prior fork count as "already forked"?** Default: no (deleted fish are forgotten; re-fork re-inserts). *Confirm.*
- **Featured/recently-added indicator on the forked fish.** Default: no — every fish enters the user's tank as a normal `manual`-grade entry except for the `source`/`source_ref` audit fields. *Confirm.*
- **GitHub API model for "watchers".** SPEC §5 says `subscribers_count`; we honor that. If reviewers prefer `watchers_count` (historical = stars), revisit. Default: `subscribers_count`. *Confirm.*
- **Cron-warmed cache** for a curated "featured repos" list. Slice 7. *No action; flagged.*
- **Auth-elevated private-repo viewing.** Out of scope; flagged. *No action.*

---

## 13. What's intentionally NOT here

- A `repo:warm-cache` artisan command, a scheduled invocation of it, or any "featured repos" list.
- A `useRepoAquariumQuery` TanStack Query hook (Server Component fetch suffices).
- Auth-elevated GitHub OAuth (the project's `GITHUB_TOKEN` is the only credential).
- Materialized historical snapshots of a repo at a point in time.
- Per-user repo bookmarks, favorites, or watchlists.
- Live event streaming (WebSockets / SSE) for star ticker, new issues, etc.
- Sentry tracing around the `GithubStatsClient` (slice 7).
- Full github-linguist JSON (we ship an 11-entry curated map).
- Visual regression snapshots of the canvas.
- A standalone `/fish/settings` page (slice 4 deferred; still deferred).
- An "auto-promote a new active background" path for the user's tank (slice 4 deferred; still deferred — repo aquarium uses the radial gradient).
- Pixel-perfect determinism of fish *positions* via the API (positions stay frontend-derived via `hashStringToSeed(fish.id)`).

---

## 14. Sources

- `SPEC.md` §1 (`/[username]/[repo]` route, `AquariumCanvas`), §2.4 (GitHub Repo Aquarium API), §3 (`repo_aquarium_cache` table + `fishes.source` / `fishes.source_ref`), §5 (the full GitHub → Aquarium mapping, stats consumed, tier scaling, allocation table, color derivation, determinism contract), §9 (GitHub service config + Guzzle timeout + retry policy), §10 (env vars: `GITHUB_TOKEN`), §12 (testing: mocked Guzzle for `GithubStatsClient`, snapshot for `RepoAquariumGenerator`), §16 (security: owner/repo regex, log scrubs, rate limits), §17 (acceptance items 8 and 9).
- `AGENT.md` §3 (controllers thin, services testable via constructor injection, `Http` factory injected — not faded), §4 (input validation, log scrubs include `Authorization` + `Bearer `), §5 (`Http::fake()` for the GitHub client, snapshot test for the generator), §6 (cached < 500 ms, cold < 2 s, always cache).
- `BRAND.md` §5 (glass-md surfaces), §10 (modal/dock/chip patterns), §11 (canvas legibility ambient overlay + edge-to-edge feel).
- Slices 1–4 design + plan — for stack baselines, OpenAPI/coverage conventions, the iron-session proxy contract, named-schema/`operationId` lessons, the Monolog log-scrub processor, and the cache-store wiring (Redis from slice 1, lock primitives from slice 4's race tests).
