# Fishbook — Slice 4: Backgrounds (Upload + Fal AI Generate + Select) Design

**Date:** 2026-05-16
**Slice:** 4 of 7 (Backgrounds)
**Status:** Approved — ready for implementation plan
**Depends on:** Slice 3 (Fish CRUD + Canvas) — `slice-3-fish-canvas` tag.

Behaviour governed by [`SPEC.md`](../../../SPEC.md) §1 (`BackgroundLayer`, `BackgroundPanel`), §2.3 (Backgrounds API), §3 (`backgrounds` table), §6 (Background Customization), §8 (file storage), §9 (Fal AI service), §10 (env vars), §12 (testing), §16 (security: file upload validation + LLM prompt blocklist), §17 (acceptance items 6 and 7). Engineering practice by [`AGENT.md`](../../../AGENT.md) §3 (services testable, Intervention v3 image processing), §4 (file upload MIME sniff, EXIF strip, LLM prompt cap, no public buckets, signed URLs, soft-delete + 7-day S3 retention), §5 (mocked Guzzle for FalAiClient), §6 (background generation poll up to 60s). Visual language by [`BRAND.md`](../../../BRAND.md) (BackgroundPanel tabbed glass surface, generator prompt scaffolding). When this file is ambiguous, those win.

---

## 1. Context

Slices 1–3 give us: a working monorepo with Postgres + Redis + MinIO behind docker compose, a Sanctum-auth API surface, an iron-session Next.js proxy that injects the bearer token, a Radix-styled `/fish` page rendering an aquarium canvas at 60 fps, a `FishPolicy`, and a `BackgroundPolicy` **stub** registered in the providers (every method returns `false`). The `BackgroundLayer` named in SPEC §1 does not yet exist — `/fish` paints the canvas over a slice 1 radial-gradient `<body>` background.

Slice 4 makes backgrounds work end-to-end. After this slice:

- The `backgrounds` table exists with all SPEC §3 columns, the partial-unique active-per-user index, and soft-delete.
- A user can `POST /backgrounds/upload` (multipart, ≥1280×720, ≤5MB, JPG/PNG/WebP), `POST /backgrounds/generate` (Fal AI `flux-2/turbo` with up-to-60s polling), `GET /backgrounds`, `PATCH /backgrounds/{id}/select`, `DELETE /backgrounds/{id}`.
- The `BackgroundResource` exposes a 1-hour signed URL minted via `Storage::disk('s3')->temporaryUrl(...)`; the bucket has no public ACL.
- The frontend ships a `BackgroundLayer` that paints the active background behind the canvas (or falls back to the slice 1 gradient), a Radix `BackgroundPanel` modal with three tabs (Upload / Generate / Library), and TanStack Query hooks (`useBackgrounds*`).
- Soft-delete + a `PurgeBackgroundJob` delayed 7 days enforces the SPEC §8 retention window; a daily artisan command reconciles orphans.
- Rate limits: 10/hr per user **and** 200/day global on AI generation, both registered as named `RateLimiter::for('generate', …)` limits; standard `throttle:api` (60/min) on upload + select + delete.
- SPEC §17 acceptance items **6** and **7** are satisfied.

It deliberately stops there. Curated preset backgrounds (the `kind=preset` admin library), real-time generation progress events (SSE/websockets), richer LLM moderation (OpenAI moderation, AWS Comprehend), crossfade animations, mobile camera-capture UX, and CDN image transforms are all later slices.

---

## 2. Scope

### In

**Backend**

- **Composer dep:** `intervention/image` v3 (per AGENT.md §1, pinned). Fal AI calls use Laravel's built-in `Illuminate\Support\Facades\Http` (Guzzle under the hood) — no extra package.
- **`config/services.php`** additions: a `fal` block (`api_key`, `base_url` defaulting to `https://queue.fal.run`, `model` defaulting to `fal-ai/flux-2/turbo`, `daily_global_limit`). The existing `s3` filesystem disk from slice 1 is reused.
- **Migration `2026_05_16_000010_create_backgrounds_table.php`** — bigserial `id`; `user_id` FK → users(id) `ON DELETE CASCADE`; `kind varchar(16) NOT NULL` + CHECK `kind IN ('upload','generated','preset')`; `storage_key varchar(255) NOT NULL`; `width integer NOT NULL`; `height integer NOT NULL`; `prompt text NULL`; `is_active boolean NOT NULL DEFAULT false`; `timestamps` + `softDeletes`. Indexes: `(user_id, deleted_at)`; raw `CREATE UNIQUE INDEX one_active_bg_per_user ON backgrounds(user_id) WHERE is_active = true AND deleted_at IS NULL` (partial). The `storage_key` column is **not** indexed — lookups are always `(user_id, …)`.
- **`Background` Eloquent model** — `$fillable = ['user_id','kind','storage_key','width','height','prompt','is_active']`; `$casts = ['is_active' => 'boolean', 'width' => 'integer', 'height' => 'integer']`; `belongsTo(User::class)`; scopes `forUser(int $userId)` and `active()`. No business logic.
- **`BackgroundFactory`** — random fields drawn from a Faker seeded with `1234`; default `kind='upload'`, `is_active=false`, `prompt=null`, `storage_key='backgrounds/u'.User::factory().'/test.webp'`. A `generated()` state sets `kind='generated'` + `prompt`.
- **`BackgroundResource`** — named OpenAPI schema. Exposes `id` (string), `kind`, `storage_key` (raw — see §3 decision 4), `signed_url` (computed: `Storage::disk('s3')->temporaryUrl($storage_key, now()->addHour())`), `width`, `height`, `prompt`, `is_active`, `created_at`.
- **`BackgroundCollection`** + **`BackgroundResourceEnvelope`** — named OpenAPI schemas for list and single-item envelopes (mirrors slice 3's `PaginatedFishCollection` pattern; `data: BackgroundResource[]` + `meta`/`links`).
- **`BackgroundPolicy`** — fills in the slice 2 stub. `viewAny`: `true` (index scopes by `forUser`). `view`/`update`/`delete`: owner-only (`$user->id === $bg->user_id || $user->is_admin` for view; owner-only for write). `create`: `true` (any authed user).
- **`App\Services\Backgrounds\BackgroundImageProcessor`** — constructor injects `Intervention\Image\ImageManager $manager` and `Illuminate\Contracts\Filesystem\Factory $filesystems`. Public method `process(UploadedFile $file, int $userId): array` returns `['storage_key','width','height']`. Pipeline:
  1. **Deep MIME sniff** via Intervention's `read()` (which uses GD/Imagick to actually decode the file). If decode fails, throw `InvalidImageException`.
  2. Reject if encoded MIME is not in `['image/jpeg','image/png','image/webp']`.
  3. Read width/height; reject if `< 1280` × `< 720` with `DimensionsTooSmallException`.
  4. Reject if file size > 5 MiB (`5 * 1024 * 1024`) with `FileTooLargeException`.
  5. If long edge > 2560 px, resize keeping aspect (SPEC §6).
  6. `encode()` to WebP at quality 85 — Intervention v3 **strips EXIF on re-encode** by default.
  7. Store via injected `Filesystem` at `backgrounds/u{userId}/{ulid}.webp` using `Str::ulid()`.
  Each failure mode is a domain exception under `App\Exceptions\Backgrounds\` so the controller can map to specific HTTP codes via a small handler tweak.
- **`App\Services\FalAi\FalAiClient`** — constructor injects `Http` factory (`Illuminate\Http\Client\Factory`) and `Repository $config`. Public method `generateBackground(string $prompt, string $aspectRatio, int $userId): array` returning `['storage_key','width','height']`. Pipeline:
  1. **Submit:** `POST {base_url}/{model}` with body `{prompt, image_size: imageSizeFor(aspectRatio), num_images: 1}`. Headers: `Authorization: Key {api_key}`, `accept: application/json`. Returns a `status_url` + `request_id` per Fal's queue contract.
  2. **Poll:** every 1 s up to **60 s**. If the response body's `status == "COMPLETED"`, fetch the `response_url`; extract `images[0].url`.
  3. **Fetch image:** GET the image url; re-encode to WebP via Intervention (same code path as upload) for consistency; store at `backgrounds/u{userId}/{ulid}.webp`.
  4. Timeout policy per AGENT.md: `connect_timeout=5s`, `timeout=60s` per call; `Http::retry(2, 250, throw: false)` on 5xx/timeouts with exponential backoff. Logs the prompt for audit (SPEC §6) — *not* the API key.
  5. `imageSizeFor`: `'16:9' → 'landscape_16_9'`, `'3:2' → 'landscape_3_2'`, `'1:1' → 'square_hd'` (Fal's enum values; verify in the open question list).
  6. Throws `FalAiTimeoutException` if polls exhaust 60 s; `FalAiFailedException` if Fal returns a failure status; `FalAiQuotaException` on HTTP 429.
- **`App\Services\Backgrounds\Prompts\PromptDenylist`** — small service holding a denylist array (configurable via `config/services.php` `fal.prompt_denylist`). Initial denylist (minimal, conservative): `['nsfw','nude','naked','explicit','porn','xxx','sexual','blood','gore']`. Method `assertAllowed(string $prompt): void` throws `DisallowedPromptException` on a case-insensitive substring hit. Length cap of 500 is enforced by `GenerateBackgroundRequest` (FormRequest); the denylist is a defence-in-depth check applied **after** validation passes, inside `BackgroundService::generate`. Easily testable in isolation; the list lives in config so updating it doesn't require a code change.
- **`App\Services\Backgrounds\BackgroundService`** — orchestrates:
  - `upload(User $u, UploadedFile $f): Background` — calls processor, creates the `Background` row, and if the user has no active background, atomically selects it as active.
  - `generate(User $u, string $prompt, string $aspectRatio): Background` — runs `PromptDenylist::assertAllowed`, calls `FalAiClient`, creates the row with `kind=generated` + `prompt`, and selects-as-active if user has none.
  - `select(User $u, Background $bg): Background` — wrapped in `DB::transaction(fn() => …)`: flip the prior active row to `false`, then this row to `true`. The partial unique index guarantees safety under races (`QueryException` with unique violation → return 409 from the controller).
  - `delete(User $u, Background $bg): void` — `$bg->delete()` (soft) + `dispatch((new PurgeBackgroundJob($bg->id))->delay(now()->addDays(7)))`.
- **`App\Jobs\PurgeBackgroundJob`** — queued job, `tries=3`, `backoff=[60,300,900]`. `handle()`: load the background `withTrashed`; abort if `deleted_at` is null (a restore happened — belt for race); delete the S3 object via `Storage::disk('s3')->delete($storage_key)`; log the event with `background_id` only.
- **`App\Console\Commands\BackgroundsPurgeOrphansCommand`** — `php artisan backgrounds:purge-orphans`. Walks `Background::onlyTrashed()->where('deleted_at','<',now()->subDays(7))` and re-dispatches `PurgeBackgroundJob` for each (idempotent — the job already abort-guards on null `deleted_at` and `Storage::delete` is idempotent on missing objects). Scheduled `daily()` in `routes/console.php` (Laravel 13's streamlined skeleton).
- **`App\Http\Requests\Backgrounds\UploadBackgroundRequest`** — `authorize(): $this->user()->can('create', Background::class)`. Rules: `image: required|file|max:5120|mimes:jpeg,png,webp` (Laravel's `mimes` uses finfo, but trivial header spoofing can fool it — the **deep sniff in `BackgroundImageProcessor` is the primary defence**; this is the cheap first gate). Documented in PHPDoc on the class.
- **`App\Http\Requests\Backgrounds\GenerateBackgroundRequest`** — `prompt: required|string|min:3|max:500`; `aspect_ratio: nullable|in:16:9,3:2,1:1` defaulted via `prepareForValidation` to `'16:9'`.
- **`App\Http\Requests\Backgrounds\SelectBackgroundRequest`** — no body fields; `authorize(): $this->user()->can('update', $this->route('background'))`.
- **`BackgroundController`** (`apiResource` minus `store`/`update`, plus three custom routes):
  - `GET /api/v1/backgrounds` (`index`) — authed, paginated, owner-scoped, ordered by `is_active DESC, created_at DESC`.
  - `POST /api/v1/backgrounds/upload` — authed, `UploadBackgroundRequest`, `throttle:api`.
  - `POST /api/v1/backgrounds/generate` — authed, `GenerateBackgroundRequest`, `throttle:generate` (named in `AppServiceProvider::boot()`: 10/hr per user **and** 200/day global keyed as `'global'`).
  - `PATCH /api/v1/backgrounds/{background}/select` (`select`) — authed, owner via policy.
  - `DELETE /api/v1/backgrounds/{background}` (`destroy`) — authed, owner via policy.
- **`RateLimiter::for('generate', …)`** in `AppServiceProvider::boot()`: returns an array of two limits, `Limit::perHour(10)->by($req->user()->id)` and `Limit::perDay(200)->by('global')`. Laravel 11+ rate-limiter supports returning multiple `Limit` instances; both must pass.
- **`routes/api.php`** updates: register the four background routes inside the `auth:sanctum` group. Generate uses `throttle:generate`; the rest use `throttle:api`.
- **OpenAPI** — every operation annotated with explicit `operationId` (`listBackgrounds`, `uploadBackground`, `generateBackground`, `selectBackground`, `deleteBackground`). Named schemas: `UploadBackgroundRequest` (multipart with `image` field), `GenerateBackgroundRequest`, `BackgroundResource`, `BackgroundCollection`, `BackgroundResourceEnvelope`. Regenerated `storage/api-docs/openapi.json` committed.
- **Pest tests** per endpoint: happy path; auth failure (no token → 401); policy failure (other user's background → 403); validation (under-size image, over-size image, wrong MIME, prompt too short, prompt too long, denylist hit, aspect_ratio invalid); rate-limit hit on generate (`Limit::perHour(10)` exceeded → 429); N+1 guardrail on index; soft-delete contract (gone from index, present in DB with `deleted_at`); race-safe `select` (partial unique violation surfaces 409); signed URL contains `X-Amz-Signature` query param.
- **Unit tests** for `BackgroundImageProcessor` (fixtures committed under `backend/tests/fixtures/backgrounds/`): a 1280×720 JPEG (accepted), an 800×600 JPEG (rejected, `DimensionsTooSmallException`), a JPEG with GPS EXIF (accepted; output asserted to have no EXIF chunk), a 7 MiB JPEG (rejected, `FileTooLargeException`), a `.jpg`-named text file (rejected, `InvalidImageException` — proves the deep sniff fires).
- **Unit tests** for `FalAiClient` with `Http::fake()` covering: happy path (submit → 2 polls → completed → image fetch → stored); 5xx-then-success on submit (retry 2x with backoff); 60-second timeout path (polls return `IN_PROGRESS` indefinitely → `FalAiTimeoutException`); 429 → `FalAiQuotaException`. Tests assert that `FAL_API_KEY` never appears in any logged context.
- **Unit test** for `PromptDenylist` (hit + miss + case-insensitivity).
- **Unit test** for `PurgeBackgroundJob` (deletes the S3 object; aborts if `deleted_at` is null after the 7-day delay).
- **Feature test** asserting `Queue::fake()` + `Queue::assertPushedWithDelay(7 days)` after `DELETE /backgrounds/{id}`.

**Frontend**

- **New dep:** `react-dropzone` (well-maintained, ~10 KB gzipped) for the Upload tab. No other new deps — Radix Tabs is already pulled in via `@radix-ui/react-dialog`'s sibling package (we add `@radix-ui/react-tabs`).
- **Sprite/asset additions:** none. The radial-gradient fallback comes from the slice 1 root layout.
- **`lib/aquarium/BackgroundLayer.tsx`** — `'use client'`. Reads the active background via a new `useActiveBackgroundQuery()` (derived from `useBackgroundsQuery`'s data, picking the entry with `is_active === true`). Renders a `fixed inset-0 -z-10 pointer-events-none` `<img>` with `object-fit: cover`, signed URL as `src`, `alt=""`, `role="presentation"`, and a `bg-white/20 backdrop-blur-[4px]` overlay above it for legibility (BRAND.md §11 callout). On load → fade in via Tailwind transition; when `prefers-reduced-motion: reduce` matches → skip the transition. Fallback: when no active background or signed URL load errors, render the slice 1 radial gradient.
- **`components/manage/BackgroundPanel.tsx`** — Radix `Dialog`. Sticky header `headline-md`; body is a Radix `Tabs.Root` with three `Tabs.Trigger`s (**Upload** / **Generate** / **Library**). BRAND.md §10.7 glass-lg panel.
- **`components/manage/BackgroundUploadTab.tsx`** — `react-dropzone`. On file drop:
  1. Client-side validate MIME against `['image/jpeg','image/png','image/webp']`; bail with a `text-error` message per SPEC §17 item 6.
  2. Read the image dimensions via `createImageBitmap(file)`; bail if `< 1280` × `< 720`.
  3. Bail if size > 5 MiB.
  4. Build a `FormData`, POST through the proxy with `useUploadBackgroundMutation`.
  Optimistic library insert (server returns the canonical row; `onSettled` invalidates).
- **`components/manage/BackgroundGenerateTab.tsx`** — `react-hook-form` + `zod`: `prompt: string().trim().min(3).max(500)`, `aspect_ratio: enum(['16:9','3:2','1:1'])`. Quick-pick chips (BRAND.md §11 prompt scaffolding). Submit → `useGenerateBackgroundMutation`. While pending: a glass card showing a slow spinner and the copy "Painting your aquarium…"; SPEC §6 budgets this at up to 60 s, so the UI explicitly says "this can take up to a minute." A "Cancel" button dismisses the spinner client-side only (we do not currently expose a backend cancel — documented as an open question / future). On success, the new background is set active by the backend and the library refetches.
- **`components/manage/BackgroundLibraryTab.tsx`** — responsive grid of the user's backgrounds (thumbnails from the signed URL with `loading="lazy"`). Active one badged ("Active" chip BRAND.md §10.1). Click a card → `useSelectBackgroundMutation` (optimistic flag flip, rollback on error). Hover reveals a small "Delete" button → `useDeleteBackgroundMutation` (optimistic removal).
- **Backgrounds dock button** on `/fish` next to "Manage" — opens `BackgroundPanel`.
- **API hooks** in `frontend/src/lib/backgrounds/api.ts` + `frontend/src/hooks/use-background-queries.ts`:
  - `useBackgroundsQuery()` — `queryKey: ['backgrounds','list']`, 5-min staleTime.
  - `useActiveBackgroundQuery()` — derived selector hook over the list; returns `data?.find(b => b.is_active) ?? null`.
  - `useUploadBackgroundMutation()` — `mutationFn: (file) => fetch('/api/proxy/backgrounds/upload', { method: 'POST', body: formData })`. **Does not** set `Content-Type` — the browser sets the multipart boundary automatically (see decision §3.5).
  - `useGenerateBackgroundMutation()` — POSTs JSON via the generated client.
  - `useSelectBackgroundMutation()` — optimistic flag flip; rollback on error.
  - `useDeleteBackgroundMutation()` — optimistic removal; rollback on error.
- **Proxy verification:** the slice 2 proxy (see `frontend/src/app/api/proxy/[...path]/route.ts`) reads `req.arrayBuffer()` for non-GET bodies and re-forwards every request header except `cookie`/`host`. Multipart upload passes through correctly because:
  1. The browser sets `Content-Type: multipart/form-data; boundary=…` on the original `fetch`,
  2. The proxy copies that header verbatim,
  3. The body's raw bytes (boundary + parts) survive the `arrayBuffer()` round-trip.
  We add a Vitest test asserting that a `FormData`-wrapped `Request` flows through the proxy with `Content-Type` and body intact. **If** that test fails (we don't expect it to), the plan includes a fix that switches `body` to `req.body` (stream pass-through) for non-GET requests.
- **`useApiClient` extension:** the generated client is JSON-only. The upload mutation bypasses the generated client and calls the proxy directly with a hand-rolled `fetch` (typed via the OpenAPI-generated `BackgroundResource` type). Generated/select/delete go through the typed client.
- **Vitest** component tests for each tab (validation, submit, success/error states), `BackgroundLayer` (renders signed URL into `img src`, falls back to gradient when no active background, respects `prefers-reduced-motion`), `useActiveBackgroundQuery` selector. Project coverage stays ≥ 70%.

**Cross-cutting**

- **`AppServiceProvider::boot()`** — add `RateLimiter::for('generate', …)` returning two `Limit`s (per-user per-hour, global per-day).
- **`routes/console.php`** — schedule `BackgroundsPurgeOrphansCommand` daily at 03:00 UTC.
- **`backend/.env.example`** — `FAL_API_KEY`, `FAL_DAILY_GLOBAL_LIMIT`, `FAL_BASE_URL` defaults already exist from slice 1 (per SPEC §10). Confirm no additions needed.
- **`frontend/.env.example`** — no changes needed; backgrounds go through the proxy.
- **`docker-compose.yml`** — `minio` and `minio-init` are already wired from slice 1. No changes expected. The acceptance task starts them explicitly.
- **Regenerate** `backend/storage/api-docs/openapi.json` AND `frontend/src/lib/api-client/`. Both committed.
- **phpunit coverage scope** picks up `app/Services/Backgrounds`, `app/Services/FalAi`, `app/Jobs`, `app/Console/Commands` (the existing `<coverage>` glob `app/Services` + `app/Http/Controllers` already covers Services; Jobs + Commands are added to the include list).
- **AGENT.md §4 logging scrubs** — verify the slice-2 Monolog processor drops `Authorization` + `password*`; add `FAL_API_KEY` + `api_key` to the scrub list if missing. The `FalAiClient` test asserts no token in logged context.
- **Final tag:** `slice-4-backgrounds`.

### Out (deferred)

- **Curated preset backgrounds.** The `kind=preset` enum value exists but no admin UI ships; no system-owned rows are seeded. Defer.
- **Real-time generation progress events** (SSE/websockets). The frontend just shows a spinner with copy and polls implicitly via TanStack Query's `mutation.isPending`. Good enough for v1.
- **Richer LLM moderation** (OpenAI moderation, AWS Comprehend, an LLM-as-judge). The local denylist is a baseline; slice 7 polish can add a moderation provider.
- **Crossfade between background swaps.** The layer simply swaps the `<img>` src. Animation polish → slice 7.
- **Mobile-specific upload UX** (camera capture, share sheet, HEIC support). Defer to polish.
- **CDN image transforms** (Cloudflare Images, Bunny CDN). Defer; signed S3 URLs are sufficient at this scale.
- **`/fish/settings`** page (SPEC §1 lists it as a "deep link convenience"). The `BackgroundPanel` modal covers all functionality; standalone page defers to polish.
- **CSP allowlist update** for the S3 host. Slice 7 finalises CSP; signed S3 URLs work without CSP locally.

---

## 3. Approach Decisions

Record load-bearing judgment calls so slice 5+ doesn't relitigate.

1. **Fal AI via the standard Laravel `Http` client, not a third-party SDK.** Fal's REST surface is small (submit → poll → fetch). A bespoke `FalAiClient` service against `Http` is easier to fake in tests (`Http::fake([...])`) than wrapping an SDK. AGENT.md §5 explicitly says "FalAiClient mocked Guzzle handler" — Laravel's `Http::fake()` uses the same Guzzle handler under the hood.

2. **WebP re-encode at quality 85, regardless of input.** Uniform output simplifies the storage tier (single extension, single content-type) and Intervention v3 strips EXIF on re-encode automatically. Quality 85 is the broadly accepted "indistinguishable from original at viewing distances" sweet spot for photographic content. Test fixture for GPS-EXIF stripping verifies the property.

3. **Signed URLs only, 1-hour TTL.** AGENT.md §4 (storage) is explicit. We mint the URL inside `BackgroundResource::toArray` via `Storage::disk('s3')->temporaryUrl($storage_key, now()->addHour())`. We never call `Storage::url()` (which would require a public ACL). The test asserts the URL has a query string signature (`X-Amz-Signature` or `X-Amz-SignedHeaders`).

4. **`storage_key` IS exposed in `BackgroundResource`.** SPEC §3 lists it as a column. Hiding it would be security-through-obscurity — the signed URL is the actual protection. Exposing the key keeps the API shape symmetric and lets a future admin tool reference objects directly. Documented in §6 threat model. *Open question 3 invites a different default.*

5. **Multipart upload bypasses the generated OpenAPI client.** `openapi-generator-cli`'s `typescript-fetch` output for `multipart/form-data` operations is awkward (it tends to serialize incorrectly through `fetch`'s Body initializer). For one endpoint, a hand-rolled `fetch('/api/proxy/backgrounds/upload', { method: 'POST', body: formData })` is cleaner. We do NOT set `Content-Type` manually — letting the browser populate the multipart boundary is the entire point. The response is typed via the OpenAPI-generated `BackgroundResource` type. A Vitest test asserts the proxy forwards multipart bodies unchanged.

6. **Atomic active-flip in a DB transaction, with the partial unique index as the safety net.** `BackgroundService::select` runs `DB::transaction(function () use ($u, $bg) { Background::active()->forUser($u->id)->update(['is_active' => false]); $bg->forceFill(['is_active' => true])->save(); });`. Under concurrent selects, the partial unique index `(user_id) WHERE is_active = true` rejects the second commit with a `QueryException` (unique violation). The controller catches that specific SQLSTATE and returns 409 Conflict with a clear retry hint. Tested.

7. **Per-user 10/hr + global 200/day on generate, both registered as a single named limiter returning an array.** Laravel's `RateLimiter::for` callback can return an array of `Limit` instances; all must pass. The global gauge is keyed `by('global')`. A separate `Limit::perDay(env('FAL_DAILY_GLOBAL_LIMIT', 200))->by('global')` config-drives the ceiling per SPEC §6 cost guard.

8. **Soft-delete + delayed `PurgeBackgroundJob` (7 days), reconciled by a daily artisan command.** SPEC §8 mandates a 7-day grace window. Two layers: the `delete()` controller path schedules a delayed job; a `daily()` `backgrounds:purge-orphans` command sweeps anything the queue lost (Redis flushed, worker scaled to zero, etc.). The job and the command are idempotent — they both call `Storage::delete($key)` which silently succeeds on missing objects.

9. **Layered MIME defense.** `mimes:jpeg,png,webp` on the FormRequest is the cheap first gate using finfo. The processor's `Intervention::read()` is the deep gate — it actually decodes the file, which a header-spoofed text file cannot survive. A unit test renames `payload.txt` to `payload.jpg`, asserts the processor throws `InvalidImageException`. Both layers must pass.

10. **EXIF strip via re-encode, not a manual `Image::stripExif()`.** Intervention v3's `encode()` drops EXIF by default; we don't need a separate call. A fixture image with embedded GPS EXIF is processed, the output bytes are scanned for the `Exif\x00\x00` marker, and the test asserts absence.

11. **No formal per-user upload concurrency cap.** SPEC §16's general `throttle:api` (60/min per user) plus the practical bandwidth bottleneck are sufficient. If abuse appears in production, slice 7 can add a smaller per-user bucket.

12. **`BackgroundLayer` uses `object-fit: cover`** (BRAND.md "fills the viewport, edges may be cropped"). `contain` would letterbox, which clashes with the BRAND.md §11 expectation of an edge-to-edge ambient image. The radial-gradient fallback uses the slice 1 root layout. *Open question 1 invites contrast.*

13. **Empty-state for backgrounds = the slice 1 radial gradient, not a curated preset.** Slice 4 ships no preset rows. *Open question 2 invites a starter pack.*

14. **Upload tab supports one file at a time.** `react-dropzone` config `maxFiles: 1`. Multi-upload is a polish-grade ergonomic improvement; defer. *Open question 4 invites batch.*

15. **The Fal AI `flux-2/turbo` model name and its queue API shape come from SPEC §9.** If Fal changes their queue API in the meantime, the plan's `Http::fake` responses and the `FalAiClient::poll` shape need adjustment. *Open question 5 flags this.*

16. **Named OpenAPI schemas + explicit `operationId`** — slices 2 & 3 lesson. Every operation gets `operationId: listBackgrounds|uploadBackground|generateBackground|selectBackground|deleteBackground` so the generated `BackgroundsApi` reads cleanly. Multipart is annotated with `requestBody: { content: { 'multipart/form-data': { schema: { properties: { image: { type: 'string', format: 'binary' } } } } } }` per OpenAPI 3.1.

17. **Logging scrubs include `FAL_API_KEY` and `api_key`.** AGENT.md §4 already lists these. The `FalAiClient` test asserts a specific Monolog handler with a memory channel and verifies that the captured records contain none of `FAL_API_KEY|Bearer |Key `.

18. **CSP impact (out of scope here, noted for slice 7).** Signed S3 / MinIO URLs render under `img-src 'self' data: blob:` locally because the browser fetches them directly from `localhost:9000` (path-style endpoint). Production R2 sources will require an explicit `https://*.r2.cloudflarestorage.com` allowlist when slice 7 finalises CSP.

19. **Reduced motion ambiguity for backgrounds.** BRAND.md §9 says "all transitions off." We interpret this as: skip the fade-in transition; the image still renders at full opacity immediately. No motion to suppress beyond the fade.

---

## 4. API Surface

All paths under `/api/v1`. JSON in, JSON out except `upload` which is `multipart/form-data` in, JSON out. Errors `{message, errors?}` per SPEC §2.6.

### 4.1 `GET /backgrounds` (authed, `throttle:api`)

Query params:
- `page?: int >= 1` — default 1.
- `per_page?: int 1..50` — default 25; clamps to 50.

Response 200: `{data: BackgroundResource[], links, meta}` ordered `is_active DESC, created_at DESC`.

### 4.2 `POST /backgrounds/upload` (authed, `throttle:api`)

`multipart/form-data`, field `image`. Validates: ≤ 5 MiB, MIME jpeg/png/webp (cheap gate via FormRequest); deep gate via `BackgroundImageProcessor` (decode + dimensions ≥ 1280×720 + EXIF strip + WebP re-encode).

Response 201: `{data: BackgroundResource}` with `kind=upload`. If the user had no active background, the row is set active in the same request (`is_active=true`).

### 4.3 `POST /backgrounds/generate` (authed, `throttle:generate`)

Body (`GenerateBackgroundRequest`):
```json
{
  "prompt": "a calm coral reef at dusk, soft caustic light",
  "aspect_ratio": "16:9"
}
```

Validation: `prompt` 3–500 chars; `aspect_ratio` in `['16:9','3:2','1:1']`, default `'16:9'`. Denylist enforced server-side after validation. Rate limit: 10/hr per user **and** 200/day global.

Response 201: `{data: BackgroundResource}` with `kind=generated` and `prompt` set. Selected as active if the user had none. May take up to ~60 s; clients should set a per-request timeout ≥ 65 s.

429 if either rate-limit gate trips. 503 if the global gauge is exhausted (mapped explicitly with `Retry-After`).

### 4.4 `PATCH /backgrounds/{background}/select` (authed, owner)

No body. Atomically flips this background to `is_active=true` and the prior active row to `false` inside a `DB::transaction`.

Response 200: `{data: BackgroundResource}` (the now-active background). 409 if a concurrent select wins the race.

### 4.5 `DELETE /backgrounds/{background}` (authed, owner)

Soft-deletes the row + dispatches `PurgeBackgroundJob` delayed 7 days. Response 204. Subsequent `GET /backgrounds` does not include it.

If the deleted background was active, the response does **not** auto-promote another one — slice 4 leaves the user with no active background; the `BackgroundLayer` falls back to the radial gradient until the user picks another. (Out of scope to auto-promote; documented.)

---

## 5. Frontend Architecture

### 5.1 The two BackgroundPanel worlds

```
TanStack Query cache               BackgroundPanel UI state
─────────────────────              ─────────────────────────
['backgrounds','list']    ─┐       activeTab: 'upload'|'generate'|'library'
                            │       uploadFile: File | null (transient)
       ▼ derived selector ──┘       generateForm: rhf state
useActiveBackgroundQuery ─→ BackgroundLayer (img src)
```

Server cache owns the truth; UI state lives in component-local hooks.

### 5.2 Upload flow

```
react-dropzone onDrop  → client validate (MIME, dims via createImageBitmap, size)
                       → on success: FormData → useUploadBackgroundMutation
                       → fetch('/api/proxy/backgrounds/upload', { method:'POST', body: formData })
                       → onSuccess: invalidate ['backgrounds','list']
                       → BackgroundLayer auto-swaps because the new row is is_active=true
```

The browser sets `Content-Type: multipart/form-data; boundary=…` automatically; we **never** set it manually. The proxy (slice 2) copies all incoming headers except `cookie`/`host` and the body's `arrayBuffer()`, so the multipart payload survives.

### 5.3 Generate flow with polling UI

```
react-hook-form submit → useGenerateBackgroundMutation
                       → POST /api/proxy/backgrounds/generate (JSON)
                       → mutation.isPending === true
                          ↓
                       Spinner card visible (BRAND.md glass-md):
                         "Painting your aquarium…"
                         "This can take up to a minute."
                         [Cancel] (dismisses spinner only)
                          ↓
                       Backend: FalAiClient.submit → poll(1s × ≤60) → fetch → store
                          ↓
                       mutation.onSuccess: invalidate ['backgrounds','list']
                          ↓
                       BackgroundLayer auto-swaps; spinner closes.
```

Cancel button dismisses the spinner client-side but **does not** abort the backend request. Documented limitation (open question 5 for future SSE-cancel).

### 5.4 Select / delete with optimism

```
Click a library card → useSelectBackgroundMutation
  onMutate: flip is_active locally in the cache
  onError:  restore the snapshot
  onSettled: invalidate

Hover card → click Delete → useDeleteBackgroundMutation
  onMutate: filter the row out locally
  onError:  restore
  onSettled: invalidate
```

### 5.5 BackgroundLayer placement

```
<body>                                  z-index notes
  <radial-gradient bg from slice 1>      base layer (z-0)
  <BackgroundLayer />                    fixed inset-0 -z-10 (behind everything; img cover)
  <AquariumCanvas />                     z-0 (transparent canvas over the bg)
  <HoverTooltip />                       z-50
  <Dock buttons>                         z-10
  <BackgroundPanel modal>                z-50
```

The canvas itself is transparent (slice 3 leaves it that way); the background layer is the visible bottom. CSS `mix-blend-mode` is not used; the overlay is a separate sibling div with `bg-white/20 backdrop-blur-[4px]` (BRAND.md §11 legibility).

### 5.6 Form state architecture

| Concern | Lives in |
|---|---|
| Background list | TanStack Query `['backgrounds','list']` |
| Active background (derived) | Selector hook over the list |
| Upload form (file, validation errors) | Component-local `useState` |
| Generate form values | react-hook-form |
| Generate zod schema | `frontend/src/lib/backgrounds/schemas.ts` |
| Panel open / active tab | Lifted to `/fish/_client.tsx` |

---

## 6. Threat Model Touch-Points

| Threat | Mitigation in this slice |
|---|---|
| **Malicious image upload (MIME spoof, executable disguised as JPEG)** | Layered: `mimes:jpeg,png,webp` FormRequest gate (finfo) + Intervention's `read()` deep decode + re-encode to WebP (output is always re-rendered pixel data). Test renames a text file to `.jpg` → 422. |
| **Oversize uploads (DoS via memory)** | FormRequest `max:5120` (KiB) gate + Intervention checks size during decode + PHP `upload_max_filesize`/`post_max_size` configured to 8 MiB in production. 7-MiB fixture rejected. |
| **EXIF leaking GPS / device** | Intervention v3 `encode()` drops EXIF on re-encode. Test scans output for `Exif\x00\x00` marker → absent. |
| **Predictable storage paths** | `Str::ulid()` per file. Path pattern `backgrounds/u{userId}/{ulid}.webp` is per-user-scoped but the ULID is unguessable. |
| **Public bucket access** | MinIO/R2 bucket has no public ACL (slice 1 `minio-init` only sets download for anonymous on `local/fishbook` for dev convenience; **production** bucket policy must be private — documented and re-verified in deploy slice). Reads via signed URLs only. |
| **Signed URL replay** | TTL 1 hour. Acceptable per AGENT.md. Longer is unnecessary; shorter would force more re-fetches. |
| **Cross-user background access** | `BackgroundPolicy::view/update/delete` checks `user_id === $auth->id || is_admin`. Index endpoint scopes via `Background::forUser($me)`. Tested with "background belongs to other user → 403." |
| **LLM prompt injection / NSFW** | 500-char cap (FormRequest) + server-side denylist (`PromptDenylist::assertAllowed`) + log audit (prompt persisted on the row). Denylist hits return 422. Richer moderation deferred. |
| **Fal AI key leak** | Never returned in any API response, never logged: Monolog scrub processor drops `FAL_API_KEY`, `Authorization`, `Bearer `, `Key `. Test asserts. |
| **Fal AI bill blow-up** | Per-user 10/hr + global 200/day named limiter. 503 with `Retry-After` once global is exhausted. SPEC §6 cost guard. |
| **Race condition on active flip** | DB transaction + partial unique index `WHERE is_active = true`. Concurrent commits surface unique violations → controller returns 409. Tested. |
| **Soft-delete bypass via direct id** | Route-model binding respects `SoftDeletes` scope; deleted backgrounds return 404. Tested. |
| **PurgeBackgroundJob deleting a restored background** | Job loads `withTrashed`; if `deleted_at` is null (the user restored, via a future restore endpoint), abort. Idempotent. |
| **CSRF on uploads** | Bearer-auth API via the same-origin proxy. SameSite=Lax cookie. No CSRF tokens needed (SPEC §2.6). |
| **Information disclosure via `storage_key`** | Decision §3.4: exposed deliberately. The key is per-user-scoped and contains a ULID; without a signed URL it's not directly fetchable. Reviewers may push back via open question 3. |

---

## 7. Frontend State Architecture (detail)

### 7.1 Server cache: TanStack Query

- `useBackgroundsQuery()` — staleTime 5 min, refetchOnWindowFocus false.
- All mutations set `onSettled: () => qc.invalidateQueries({queryKey:['backgrounds','list']})`.
- `useActiveBackgroundQuery()` is a *selector hook* (not a separate fetch): it subscribes to the list and returns `data?.find(b => b.is_active) ?? null`. This avoids a duplicate cache key and guarantees the `BackgroundLayer` re-renders on flip.

### 7.2 UI state

```ts
// BackgroundPanel.tsx local state
const [activeTab, setActiveTab] = useState<'upload'|'generate'|'library'>('library');
const [generationPending, setGenerationPending] = useState(false); // mirrors mutation.isPending for ARIA live region
```

### 7.3 What lives where (cheat sheet)

| Concern | Lives in |
|---|---|
| Background list (server) | `useBackgroundsQuery` |
| Active background (derived) | `useActiveBackgroundQuery` selector |
| Upload form state | Component-local `useState` (file + error messages) |
| Generate form values | react-hook-form |
| Open/closed panel + active tab | `/fish/_client.tsx` lifted state |
| Generation UI "Painting…" spinner | `BackgroundGenerateTab` reads `mutation.isPending` |

---

## 8. Testing Strategy

### 8.1 Backend (Pest)

| Layer | File | What |
|---|---|---|
| Feature | `tests/Feature/Backgrounds/IndexBackgroundsTest.php` | Happy 200; unauthed 401; pagination meta; only own rows; `is_active DESC, created_at DESC` ordering; **N+1 guardrail** (≤ 4 queries); signed URL contains `X-Amz-Signature` (assert query param). |
| Feature | `tests/Feature/Backgrounds/UploadBackgroundTest.php` | Happy 201 (fixture 1280×720 jpeg); 422 (800×600); 422 (.txt renamed .jpg — deep MIME); 422 (7-MiB file); 422 (.bmp — disallowed MIME); first upload sets `is_active=true`; second upload does NOT auto-flip; mass-assignment of `is_active` / `kind` / `user_id` ignored. |
| Feature | `tests/Feature/Backgrounds/GenerateBackgroundTest.php` | Happy 201 (`Http::fake` returns COMPLETED on second poll); 422 prompt too short; 422 prompt too long; 422 denylist hit ("nsfw"); 422 invalid aspect_ratio; 429 after 11 generates in an hour (rate-limit); 503 after 201st global generate (cost guard). |
| Feature | `tests/Feature/Backgrounds/SelectBackgroundTest.php` | Happy 200; flips prior active false + new true; 403 on other user's row; 409 on simulated race (manually insert a second `is_active=true` row → asserting unique-violation surfaces). |
| Feature | `tests/Feature/Backgrounds/DeleteBackgroundTest.php` | Happy 204; `Queue::fake` + `assertPushedWithDelay(7 days, PurgeBackgroundJob::class)`; row absent from index; row in DB with `deleted_at`; 403 on other user's row. |
| Unit | `tests/Unit/Services/Backgrounds/BackgroundImageProcessorTest.php` | Accepted: 1280×720 jpeg; Rejected: 800×600 (`DimensionsTooSmallException`), 7-MiB file (`FileTooLargeException`), `.jpg`-named text file (`InvalidImageException`); EXIF-strip: GPS-bearing jpeg → output bytes have no Exif marker. |
| Unit | `tests/Unit/Services/FalAi/FalAiClientTest.php` | Happy (submit + 2 polls + image fetch + store); 5xx-then-success retry on submit; 60-s timeout (polls stay IN_PROGRESS) → `FalAiTimeoutException`; 429 → `FalAiQuotaException`; logs contain prompt but NO `FAL_API_KEY` substring. |
| Unit | `tests/Unit/Services/Backgrounds/Prompts/PromptDenylistTest.php` | Allowed prompt passes; "nsfw" anywhere → throws; case-insensitive. |
| Unit | `tests/Unit/Jobs/PurgeBackgroundJobTest.php` | Deletes the S3 object via faked filesystem; aborts if `deleted_at` is null (restore race); idempotent on already-missing object. |

Coverage gate: ≥ 80% on the new directories (`app/Services/Backgrounds`, `app/Services/FalAi`, `app/Jobs`, `app/Console/Commands`); project-wide gate stays ≥ 80% on `app/Services` + `app/Http/Controllers`.

### 8.2 Frontend (Vitest)

| Layer | File | What |
|---|---|---|
| Component | `tests/unit/components/aquarium/BackgroundLayer.test.tsx` | Renders signed URL into `img.src` when active bg present; falls back to gradient when none; respects `prefers-reduced-motion` (no fade-in class). |
| Component | `tests/unit/components/manage/BackgroundUploadTab.test.tsx` | Drop a too-small image → inline error; drop a too-large file → inline error; drop a valid jpeg → `useUploadBackgroundMutation` called with FormData containing the file. |
| Component | `tests/unit/components/manage/BackgroundGenerateTab.test.tsx` | Zod errors for short/long prompt; submit → spinner with "Painting…" copy; on success, panel reflects new active row. |
| Component | `tests/unit/components/manage/BackgroundLibraryTab.test.tsx` | Grid renders; click card → optimistic select + rollback on error; delete button → optimistic remove + rollback. |
| Unit | `tests/unit/hooks/use-active-background.test.ts` | Selector returns the active row; returns null when none. |
| Unit | `tests/unit/app/api/proxy-multipart.test.ts` | FormData-wrapped Request flows through proxy with Content-Type + body intact. |

Coverage gate: 70% statement floor; new files target ≥ 80%.

### 8.3 Out

- Playwright E2E for the upload + generate journey — slice 6 polish.
- Pixel-perfect background snapshot tests — too brittle for compressed WebP output.
- Live Fal AI tests in CI — explicitly forbidden (CI must never call Fal). The acceptance task's "live MinIO smoke" is the only out-of-CI live check.

---

## 9. Acceptance Criteria

1. `GET /api/v1/backgrounds` returns the user's own rows, `is_active` first; 401 unauthed.
2. `POST /api/v1/backgrounds/upload` with a 1280×720 jpeg returns 201; smaller image returns 422 with a clear `errors.image` message (SPEC §17 item 6).
3. `POST /api/v1/backgrounds/generate` with a valid prompt returns 201 with `kind=generated`; the new row is `is_active=true` if the user had no active background (SPEC §17 item 7).
4. `POST /api/v1/backgrounds/generate` with a denylisted prompt returns 422.
5. `POST /api/v1/backgrounds/generate` honors per-user 10/hr (11th → 429) and global 200/day (201st → 503 with `Retry-After`).
6. `PATCH /api/v1/backgrounds/{id}/select` flips active atomically; 403 on another user's row; 409 on simulated race.
7. `DELETE /api/v1/backgrounds/{id}` returns 204; row absent from index; row in DB with `deleted_at`; `PurgeBackgroundJob` dispatched with 7-day delay.
8. `BackgroundResource` always emits a signed URL containing an S3 query signature; raw `storage_key` is included but is not directly browser-fetchable on a private bucket.
9. The `/fish` page renders the active background behind the canvas; no active background → falls back to the slice 1 radial gradient.
10. The `BackgroundPanel` modal has Upload / Generate / Library tabs; uploading and generating both update the canvas without a full page reload.
11. EXIF GPS data is stripped from uploaded images (asserted by the processor test on a GPS-bearing fixture).
12. The Fal AI key never appears in logged context (asserted by the FalAi client test).
13. `php artisan l5-swagger:generate && git diff --exit-code storage/api-docs/openapi.json` exits 0.
14. `npm run generate:api && git diff --exit-code src/lib/api-client` exits 0.
15. Backend coverage ≥ 80% on the new directories; project gate stays green.
16. Frontend coverage ≥ 70% statements.
17. `npm run lint`, `npm run typecheck`, `npm run build` stay clean. `./vendor/bin/phpstan analyse` level 6 stays clean. `./vendor/bin/pint --test` stays clean.
18. CI does NOT call Fal AI — every Fal test uses `Http::fake()`.

---

## 10. Open Questions / Follow-Ups

- **`object-fit: cover` vs `contain` on `BackgroundLayer`.** `cover` is the default and matches BRAND.md's edge-to-edge ambient intent, but it crops image edges. `contain` would letterbox. Default: `cover`. *Confirm before merge.*
- **Curated preset backgrounds for the empty state.** Slice 4 ships zero presets — the empty state is the radial gradient. We could seed 3–5 admin-owned `kind=preset` rows so first-run users see something inspiring. Default: no presets in slice 4. *Confirm direction.*
- **Should `BackgroundResource` expose `storage_key`?** SPEC §3 lists it as a column; that doesn't mean the API must surface it. The signed URL is the real protection. Default: include it for SPEC-consistency. *Confirm or hide.*
- **Batch upload (drag multiple files).** Slice 4 caps `react-dropzone` to one file at a time for simplicity. Default: one. *Confirm.*
- **Fal AI `flux-2/turbo` queue API shape.** The polling contract `{status: 'IN_PROGRESS'|'COMPLETED', response_url, images:[{url}]}` comes from SPEC §9. If Fal updates their API, the `Http::fake` responses + the `FalAiClient::poll` parser need adjustment. *No action; flagged.*
- **Auto-promote on delete-active.** When the user deletes their currently-active background, slice 4 leaves them with none active. We could auto-promote the most-recent remaining row. Default: no auto-promote. *Confirm.*
- **Cancel button on the generate spinner.** Currently dismisses the spinner client-side only; the backend request continues and the bill ticks. A future endpoint `POST /backgrounds/generate/cancel` (relayed to Fal AI's queue cancellation) would close the gap. *Out of scope; flagged.*
- **`/fish/settings` page.** SPEC §1 lists it for deep-linking but the modal covers the functionality. Default: no standalone page. *Confirm.*

---

## 11. Sources

- `SPEC.md` §1 (`BackgroundLayer`, `BackgroundPanel`), §2.3 (Backgrounds API), §3 (`backgrounds` table), §6 (Background Customization), §8 (file storage), §9 (Fal AI), §10 (env vars), §12 (testing), §16 (security: file upload + LLM prompt blocklist), §17 (acceptance items 6 and 7).
- `AGENT.md` §1 (`intervention/image` v3), §3 (services testable), §4 (file upload MIME sniff, EXIF strip, no public buckets, signed URLs, soft-delete + 7-day S3 retention, log scrubs), §5 (mocked Guzzle for FalAiClient), §6 (60-s background generation budget).
- `BRAND.md` §5 (glass surfaces), §6 (radii), §9 (reduced motion), §10.1 (chip), §10.7 (modal), §11 (background imagery + prompt scaffolding).
- Slices 1–3 design + plan — for stack baselines, OpenAPI/coverage conventions, the iron-session proxy contract, and named-schema / `operationId` lessons.
