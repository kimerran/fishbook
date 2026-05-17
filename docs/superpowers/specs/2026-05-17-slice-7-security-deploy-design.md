# Fishbook — Slice 7: Security, Observability & Deploy (v1 close-out) Design

**Date:** 2026-05-17
**Slice:** 7 of 7 (Security, Observability & Deploy — v1 close-out)
**Status:** Approved — ready for implementation plan
**Depends on:** Slice 6 (E2E, Perf & Polish) — `slice-6-e2e-perf-polish` tag.

Behaviour governed by [`SPEC.md`](../../../SPEC.md) §13 CI/CD (`deploy.yml`), §14 Deployment (Railway), §15 OpenAPI/Swagger (`/api-docs` page via `swagger-ui-react`, lazy), §16 Security Checklist (HTTPS forcing, CORS, security headers including the full CSP/HSTS/XFO/Referrer/Permissions-Policy set, log scrubs, Dependabot, admin seeder fail-closed), §17 acceptance items 10 (Swagger UI reachable) and 12 (security checklist complete). Engineering practice by [`AGENT.md`](../../../AGENT.md) §4 (security headers via middleware, HTTPS forced in prod, CORS scoped to `FRONTEND_URL`, Sentry with `beforeSend` that drops requests containing sensitive headers, Monolog scrubs for `password`/`token`/`Authorization`/`api_key`/`FAL_API_KEY`), §6 (route-level JS payload < 200 KB gzipped on `/fish`; `swagger-ui-react` lazy-loaded on `/api-docs`), §7 workflow (regenerate OpenAPI + frontend client on any backend route change; commit both). When this file is ambiguous, those win.

---

## 1. Context

Slices 1–6 give us: a docker-compose stack that actually builds, a Pest + Vitest + Playwright test triangle that proves SPEC §17 items 1–9 + 11 against the running stack, a ULID-externalized API surface, the `await getTintedSprite` cold-cache stall purged from the canvas RAF loop, and an `e2e.yml` workflow that runs the full canonical journey on every PR touching `frontend/` or `backend/`. The backend currently registers no global middleware (`bootstrap/app.php` `withMiddleware` is an empty closure), serves over HTTP locally with `APP_URL=http://localhost:8000`, has CORS already scoped to `FRONTEND_URL` from slice 1, and emits OpenAPI 3.1 with `OA\Server(url: '/api/v1')` declared on `HealthController` plus mixed `path:` declarations on the other controllers: `AuthController`, `HealthController`, `GoogleAuthController` use bare paths (`/health`, `/auth/login`, `/auth/google/redirect`) but `FishController`, `BackgroundController`, `RepoAquariumController` redundantly prefix `/api/v1/` on every operation. The OpenAPI generator concatenates `servers.url` + `path`, so the spec currently emits `https://api.fishbook.neri.ph/api/v1/api/v1/fishes` for those three controllers if servers ever resolves to absolute, and `/api/v1/api/v1/fishes` when relative — both broken. The slice 6 generated frontend client masks this with a `rewritePath` shim in `frontend/src/lib/fish/api.ts` and `frontend/src/lib/backgrounds/api.ts` that strips a leading `/api/v1/` before forwarding to the Next.js iron-session proxy. There is no Sentry SDK in either project. There is no `/api-docs` page in `frontend/src/app/`. There is no `deploy.yml` in `.github/workflows/`. Slice 6 left 7 lint warnings (next/image suggestions on `<img>` tags, a `react-compiler` ↔ `react-hook-form` interop note, a Sanctum `TransientToken` unused-import docblock, and a possible `font-display` warning on Inter).

Slice 7 closes v1. After this slice:

- The backend ships a `SecurityHeaders` middleware that sets HSTS (production only), `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: camera=(), microphone=(), geolocation=()`, and a Content-Security-Policy whose `img-src` allowlist matches the production storage providers we expect (R2, S3) plus `data:` and excludes MinIO; locally CSP runs in `Content-Security-Policy-Report-Only` mode with MinIO whitelisted so browser previews don't break. The middleware is registered globally via `bootstrap/app.php` `withMiddleware`.
- `AppServiceProvider::boot()` calls `URL::forceScheme('https')` when (and only when) `app()->environment('production')`. Local dev keeps `http://localhost:8000`.
- Sentry SDK is wired on both sides. DSNs default empty; the SDK is a no-op without one. A `SentryEventScrubber` invokable on the backend strips `Authorization`/`password`/`password_confirmation`/`token`/`api_key`/`FAL_API_KEY` from `event.request.headers`, `event.request.data`, and `event.extra`. A mirrored `beforeSend` on the frontend handles the same set client-side (`authorization`, `cookie`, `password`). Both scrubbers are unit-tested by direct invocation; no live Sentry call lands in CI.
- The frontend ships `/api-docs` as a public, `'use client'` route that lazy-loads `swagger-ui-react` via `next/dynamic({ ssr: false })` pointed at `/api/v1/openapi.json` (served by `l5-swagger`). The lazy split keeps the swagger bundle off every other route's JS payload, preserving the `/fish` < 200 KB gzipped budget.
- The OpenAPI `path:` cleanup is complete: every `OA\Get/Post/Patch/Delete` declaration's `path:` is now bare (`/fishes`, `/backgrounds`, `/repos/{owner}/{repo}/aquarium`, etc.). `OA\Server(url: '/api/v1')` is the single source of truth for the version prefix. The spec is regenerated, the frontend client is regenerated, and the `rewritePath` shim in `frontend/src/lib/fish/api.ts` and `frontend/src/lib/backgrounds/api.ts` is deleted — the generated client's `BASE_PATH` becomes `/api/v1` directly and the Next.js proxy at `/api/proxy/[...path]` forwards bare path segments to `BACKEND_INTERNAL_URL` (which already terminates in `/api/v1`). One regen, one shim removal, one coherent diff. (See decisions §3.1 and §3.6.)
- `.github/workflows/deploy.yml` exists, triggers on `v*` tag push, runs `railway up --service backend --detach` then `railway up --service frontend --detach`, then `curl`-checks both `/api/v1/health` and `/api/health`. It is **not exercised** in slice 7 — no tag is pushed.
- Lint warnings are cleared best-effort: `BackgroundLayer`'s `<img>` switches to `next/image` because the image source is a backend-issued signed URL of known dimensions; sprite `<img>`s stay as plain `<img>` because the `currentColor` SVG composes badly with `next/image`'s optimization pipeline (decision §3.5). The Sanctum docblock import is removed. The `font-display: swap` is asserted on the next/font Inter config.
- The final tag is `v1-rc1` (release candidate 1) — not `slice-7-…`. SPEC §17 items 10 and 12 are now ticked; v1 is feature-complete pending the first operational Railway deploy.
- A short `CHANGELOG.md` summarizes slices 1–7.

It deliberately stops there: no actual Railway deploy (operational decision), no Cloudflare/WAF, no privacy policy or ToS copy, no SRI (we ship `swagger-ui-react` via npm, not CDN), no CSP `report-uri`/`report-to` headers, no Sentry source-map upload on deploy, no Lighthouse CI gate, no visual-regression suite. (See §13.)

---

## 2. Scope

### In

**Phase A — OpenAPI path-prefix cleanup (must come first; everything downstream rides on the regen)**

- **Controller annotation sweep.** In `backend/app/Http/Controllers/Api/V1/`:
  - `FishController.php` — drop the `/api/v1/` prefix from each `path:` on `index/store/show/update/destroy/breeds`. After: `/fishes`, `/fishes`, `/fishes/{fish}`, `/fishes/{fish}`, `/fishes/{fish}`, `/fishes/breeds`.
  - `BackgroundController.php` — drop on `index/upload/generate/select/destroy`. After: `/backgrounds`, `/backgrounds/upload`, `/backgrounds/generate`, `/backgrounds/{background}/select`, `/backgrounds/{background}`.
  - `RepoAquariumController.php` — drop on `show/fork`. After: `/repos/{owner}/{repo}/aquarium`, `/repos/{owner}/{repo}/fork-to-my-aquarium`.
  - `AuthController.php`, `HealthController.php`, `GoogleAuthController.php` already use bare paths — verify, no changes.
- **`OA\Server(url: '/api/v1')`** remains on `HealthController`. Now the single source of truth.
- **Spec regen + client regen, atomic commit.**
  ```
  docker compose exec backend php artisan l5-swagger:generate
  cd frontend && npm run generate:api
  ```
  Both `backend/storage/api-docs/openapi.json` and `frontend/src/lib/api-client/` land in one commit alongside the annotation edits.
- **Frontend shim removal.** Delete `rewritePath` + `proxiedFetch` from `frontend/src/lib/fish/api.ts` and `frontend/src/lib/backgrounds/api.ts`. The files collapse to:
  ```ts
  import { Configuration, FishesApi } from '@/lib/api-client';
  const config = new Configuration({ basePath: '/api/proxy', fetchApi: fetch });
  export const fishesApi = new FishesApi(config);
  ```
  (Plus the `uploadBackground` multipart helper in `backgrounds/api.ts` stays — it already hits `/api/proxy/backgrounds/upload` directly.)
- **Verify proxy passthrough.** `/api/proxy/[...path]/route.ts` already concatenates `BACKEND_INTERNAL_URL` (terminates in `/api/v1`) + `path.join('/')` — no change. The generated client now emits `/api/proxy/fishes` and the proxy lands at `http://backend:8000/api/v1/fishes`. Confirmed in §4 below.
- **Test sweep.** Pest feature suite re-runs (no behavioral change expected; route patterns are unchanged). Playwright re-runs against the e2e overlay to confirm the proxy round-trip works without the shim.

**Phase B — Security headers + HTTPS forcing**

- **`backend/app/Http/Middleware/SecurityHeaders.php`** (new). Sets on every response:
  - `Strict-Transport-Security: max-age=31536000; includeSubDomains` — **production only.**
  - `X-Content-Type-Options: nosniff`
  - `X-Frame-Options: DENY`
  - `Referrer-Policy: strict-origin-when-cross-origin`
  - `Permissions-Policy: camera=(), microphone=(), geolocation=()`
  - **CSP composition (see table below):** `default-src 'self'; img-src 'self' https://*.r2.cloudflarestorage.com https://*.s3.amazonaws.com data:; connect-src 'self' https://api.fishbook.neri.ph; frame-ancestors 'none'`. Header name flips by env: `Content-Security-Policy` in production (enforced), `Content-Security-Policy-Report-Only` in non-production (report-only, with `http://localhost:9000` added to `img-src` so MinIO previews render in the browser).
- **Register globally** in `bootstrap/app.php` `withMiddleware`:
  ```php
  ->withMiddleware(function (Middleware $middleware): void {
      $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
  })
  ```
  Appended (not prepended) so it runs after CORS and after exception rendering, ensuring it stamps the final response.
- **`AppServiceProvider::boot()`** — add a guarded `URL::forceScheme('https')` call:
  ```php
  if ($this->app->environment('production')) {
      URL::forceScheme('https');
  }
  ```
  Strict env gate — local dev keeps `http://`.
- **Pest tests:**
  - Unit: `tests/Unit/Middleware/SecurityHeadersTest.php` — invokes the middleware with a `Request` + closure returning a stubbed `Response`, asserts each expected header appears in the result. Two test cases: `APP_ENV=production` (asserts `Content-Security-Policy` enforced + HSTS present) and `APP_ENV=local` (asserts `Content-Security-Policy-Report-Only` + no HSTS + MinIO in `img-src`).
  - Feature: `tests/Feature/SecurityHeadersFeatureTest.php` — `GET /api/v1/health` carries all headers; auth-required routes (e.g. `GET /api/v1/fishes` with bearer) also carry them. Round-trip proof, no mocking of the middleware itself.

**Phase C — Sentry SDK init (backend + frontend)**

- **Backend composer dep:** `sentry/sentry-laravel` (`^4.x`).
- **Publish:** `php artisan sentry:publish --dsn=` produces `config/sentry.php`. We edit it directly.
- **`config/sentry.php` shape:**
  ```php
  return [
      'dsn'                => env('SENTRY_LARAVEL_DSN'),                // empty → no-op
      'traces_sample_rate' => env('SENTRY_TRACES_SAMPLE_RATE', 0.1),    // 0 in tests via phpunit.xml
      'send_default_pii'   => false,
      'before_send'        => [\App\Logging\SentryEventScrubber::class, '__invoke'],
  ];
  ```
- **`backend/app/Logging/SentryEventScrubber.php`** (new) — invokable. Accepts `\Sentry\Event $event`, returns `?\Sentry\Event`. Iterates `event.request.headers` (case-insensitive), `event.request.data`, `event.extra`, replacing values for the AGENT.md §4 scrub list (`Authorization`, `password`, `password_confirmation`, `token`, `api_key`, `FAL_API_KEY`) with `'[REDACTED]'`. Never returns null (we redact, we don't drop).
- **`backend/tests/Unit/Logging/SentryEventScrubberTest.php`** — pure-PHP unit tests. Constructs a `Sentry\Event` (the SDK supports `Event::createEvent()`), populates a header (`Authorization: Bearer abc`), a body field (`password: hunter2`), an extra (`FAL_API_KEY: k_live_...`), passes the event through the scrubber, asserts every value is `'[REDACTED]'`. One test per scrub key.
- **`backend/routes/console.php`** — register `php artisan fishbook:sentry-smoke` that calls `\Sentry\captureException(new \RuntimeException('sentry smoke'))`. Gated to non-production via `unlessEnvironment('production')`. Documented in README.
- **`phpunit.xml` env** — add `SENTRY_LARAVEL_DSN=` and `SENTRY_TRACES_SAMPLE_RATE=0` so the test runner never even attempts an outbound call.
- **Frontend deps:** `@sentry/nextjs` (`^8.x`). No wizard.
- **`frontend/sentry.client.config.ts`, `sentry.server.config.ts`, `sentry.edge.config.ts`** — three small init files. Each reads the DSN from env (`NEXT_PUBLIC_SENTRY_DSN` for client; `SENTRY_DSN` server-side falling back to the same env), runs `Sentry.init({...})` only if the DSN is non-empty, and registers a `beforeSend` that:
  - Drops/replaces `event.request?.headers?.authorization` and `…?.cookie` with `'[REDACTED]'`.
  - Walks `event.extra` and `event.contexts` for keys in `['authorization', 'cookie', 'password', 'token', 'api_key']`, replacing with `'[REDACTED]'`.
  - Returns the (now-clean) event.
- **`frontend/next.config.ts`** — wrap with `withSentryConfig(nextConfig, { silent: !process.env.CI })` **only when** `process.env.NEXT_PUBLIC_SENTRY_DSN` is set; otherwise export the bare config. Avoids pulling Sentry tooling into local dev builds with no DSN.
- **`frontend/tests/unit/sentry/before-send.test.ts`** — imports `beforeSend` from `sentry.client.config.ts` (export it for testability), feeds a synthetic event with `request.headers.authorization = 'Bearer abc'`, asserts redacted. Covers headers, request body, extra, contexts.

**Phase D — Swagger UI `/api-docs` page**

- **Frontend dep:** `swagger-ui-react` (`^5.x`) + `@types/swagger-ui-react`.
- **`frontend/src/app/api-docs/page.tsx`** (new) — minimal `'use client'` page. Lazy-load `swagger-ui-react` and its CSS via `next/dynamic({ ssr: false, loading: () => <Loading /> })`. Point at the **proxied** spec URL `/api/proxy/openapi.json` so the browser hits the Next.js runtime (which forwards to the backend) instead of hard-coding the prod host — works locally and in prod.
- **Bundle isolation.** Because `next/dynamic` with `ssr: false` creates a code-split chunk loaded only when this route is visited, the swagger bundle stays out of `/fish`'s JS payload. Verified in §11.
- **`backend/routes/api.php`** — confirm `/api/v1/openapi.json` is reachable. `l5-swagger`'s default config exposes `/api/documentation` (UI) and the JSON at the configured `routes.api` (defaults to `/docs.json`). Add an explicit route:
  ```php
  Route::get('/openapi.json', fn() => response()->file(storage_path('api-docs/openapi.json')))
      ->name('openapi.json');
  ```
  This serves the **committed** spec file (which CI keeps in sync), no generation at request time.
- **Vitest smoke:** `frontend/tests/unit/app/api-docs.test.tsx` — renders `<ApiDocsPage />` with a mocked `swagger-ui-react` default export (just asserts the dynamic module path is referenced and a loading state shows initially).
- **Playwright:** `frontend/tests/e2e/05-api-docs.spec.ts` — visits `/api-docs`, waits for at least one operation header to appear (`page.getByText('/fishes')` or `/health`), asserts the page mounts and the dynamic chunk loads. Mocks the spec response via `page.route('**/openapi.json', …)` so this spec doesn't depend on the live backend serving the file (still works against the docker stack; the mock is a safety net for flake).

**Phase E — `deploy.yml` GitHub Actions workflow (write only; do not deploy)**

- **`.github/workflows/deploy.yml`** (new):
  - `on.push.tags: ['v*']`
  - Job: `deploy` on `ubuntu-latest`
  - Steps:
    1. `actions/checkout@v4`
    2. `setup-node@v4` with Node 20
    3. `npm install -g @railway/cli@latest`
    4. `railway up --service backend --detach` (env: `RAILWAY_TOKEN: ${{ secrets.RAILWAY_TOKEN }}`)
    5. `railway up --service frontend --detach`
    6. Post-deploy health-checks: a small bash loop that `curl -fsS https://api.fishbook.neri.ph/api/v1/health` and `https://fishbook.neri.ph/api/health` with retries (max 60s).
    7. On failure: workflow exits non-zero — operator handles rollback manually (Railway's UI provides a "Restore" button per deployment). No automated rollback in v1.
  - **Environment expectations documented in the workflow file's header comment**: backend service envvars from SPEC §10 (Sanctum/CORS, S3, DB, Redis, Fal AI, GitHub, Sentry); frontend service envvars from SPEC §10 (`NEXT_PUBLIC_*`, `BACKEND_INTERNAL_URL`, `SESSION_COOKIE_*`, Sentry); `APP_ENV=production` on both.
- **No tag push in slice 7.** Workflow exists; the first real exercise of it is operational and outside the slice.

**Phase F — Loose ends cleanup**

- **`<img>` → `<Image>` in `BackgroundLayer`** — the background image comes from a backend signed URL with known dimensions stored on the `backgrounds` row. Swap to `next/image` with `fill` + `priority` (it's above the fold) + the `s3.amazonaws.com` / `r2.cloudflarestorage.com` hostnames added to `next.config.ts` `images.remotePatterns`.
- **Sprite `<img>` stays.** Sprites are inline-tinted SVGs via `currentColor` already in our `getTintedSprite` pipeline. `next/image` doesn't compose with the dynamic colorization; documented in the component file's comment.
- **`AddFishDialog` react-compiler ↔ react-hook-form warning** — investigate per slice 6's note. If `react-compiler` flags the form's `register(...)` returns as a non-reactive value, gate the offending hook with `// eslint-disable-next-line react-compiler/react-compiler` plus a one-line justification linking to react-hook-form's known interop issue. If fixable cheaply (e.g. wrapping `register` calls inside a `useMemo`), prefer the fix; otherwise document and move on.
- **Sanctum `TransientToken` unused docblock import** — remove the `use` line.
- **Inter font `display: 'swap'`** — verify `next/font/google`'s `Inter({ subsets: ['latin'], display: 'swap' })` config in `frontend/src/app/layout.tsx`. Add `display: 'swap'` if missing.

**Phase G — Final v1 housekeeping**

- **`README.md`** updates:
  - Add a "Deployment" section linking `deploy.yml`; document the tag-trigger pattern (`git tag v1.0.0 && git push --tags`).
  - Add a `make deploy-prod` target whose body is a one-line echo: `echo 'Tag-push triggers .github/workflows/deploy.yml — see README §Deployment.'`. Documentation, not action.
  - Add a "v1 status" badge (`![v1](https://img.shields.io/badge/v1-rc1-blue)`) — purely cosmetic.
- **`backend/.env.example` + `frontend/.env.example`** — both already document Sentry DSN env keys from slice 1 (`SENTRY_LARAVEL_DSN=`, `NEXT_PUBLIC_SENTRY_DSN=`). No new envvars expected. Verify and adjust only if introduced.
- **`docker-compose.yml`** — no changes expected. CSP report-only in dev means MinIO previews work without compose surgery.
- **`CHANGELOG.md`** (new, top-level) — one section per slice, 1–3 bullets each: what each slice contributed in user-facing terms. Slice 7 entry self-references this design.
- **Final tag:** `v1-rc1`. Slice 7's per-task commits land on `main`; the tag is created once the slice's acceptance criteria are all green.

### Out (deferred)

- Actually pushing `v1-rc1` to a remote or actually deploying to Railway (operational).
- Cloudflare in front of Railway / WAF / DDoS rules (operational; revisit after first deploy stress test).
- Privacy policy, Terms of Service, marketing copy (product/legal scope).
- Subresource Integrity (SRI) on `swagger-ui-react` (we ship via npm; SRI applies to CDN includes).
- CSP `report-uri` / `report-to` header for violation telemetry (defer to post-launch ops slice; would couple us to Sentry's CSP endpoint).
- Sentry release tracking + source-map upload on deploy (defer until there's a real deploy history).
- Lighthouse CI gate (deferred from slice 6; still deferred).
- Percy / Chromatic visual regression (deferred from slice 6).
- Multi-region Railway, blue-green deploy, deploy hooks beyond the workflow `curl` check.
- A `/api/proxy/openapi.json` server-side cache (the spec is ~tens of KB; let it pass through).

---

## 3. Approach Decisions

Record load-bearing judgment calls so post-v1 work doesn't relitigate.

1. **Phase A (OpenAPI path-prefix cleanup) runs FIRST.** The cleanup forces one OpenAPI regen and one frontend-client regen. Every later phase that touches a route — there are none in slice 7, but Phase D adds the `/openapi.json` route which annotation-wise doesn't appear in the spec — assumes the cleanup is already in place. Doing Phase C/D/E first would either (a) require a second regen, or (b) leave the `rewritePath` shim in place longer than necessary. Phase A is also the lowest-risk phase: route patterns are unchanged, behavior is byte-identical from the user's perspective.

2. **`URL::forceScheme('https')` is strictly env-gated.** Local dev hits `http://localhost:8000`; forcing https there breaks signed URLs, login redirects, and Sanctum's cookie-domain match. We gate exclusively on `app()->environment('production')` (not on `APP_DEBUG`, not on a custom flag) — Railway's `APP_ENV=production` is the single trigger. Test path: `tests/Unit/AppServiceProviderTest.php` swaps the environment via `app()->detectEnvironment(fn () => 'production')` and asserts `url('/foo')` starts with `https://`.

3. **CSP is enforced in production, report-only locally.** A wrong CSP breaks every page; we'd rather see the report-only console warnings during local dev than ship a broken page to prod. The flip is one line — the middleware reads `app()->environment('production')` and picks the header name (`Content-Security-Policy` vs `Content-Security-Policy-Report-Only`) and the `img-src` list (with/without MinIO). The CSP composition table in §5 below documents every directive's rationale.

4. **Sentry is mocked everywhere; no live calls in CI.** DSNs default empty in `.env.example`; the SDK initializes but no-ops without one. Scrubbers are tested via direct invocation against synthetic `Sentry\Event` instances (backend) and plain object literals shaped like Sentry events (frontend). A `php artisan fishbook:sentry-smoke` artisan command exists for manual production-DSN smoke tests but is **gated to non-production**, so accidentally running it in CI is a no-op anyway.

5. **`<img>` audit decision:** swap `BackgroundLayer`'s `<img>` to `next/image` (known-dimension signed URL, above-the-fold → benefits from priority + AVIF); keep sprite `<img>` as plain `<img>` (we tint with `currentColor` in a custom pipeline — `next/image`'s optimization defeats the recoloring). Documented in `BackgroundLayer.tsx` and the sprite component. The two `<img>`-rule warnings collapse to one informational comment.

6. **Drop the `rewritePath` shim entirely, not soft-deprecate it.** Slice 7 is v1 close-out; there's no downstream consumer of the shim. The generated client's `BASE_PATH` becomes `/api/proxy` (set explicitly in `Configuration`) and the proxy passes the path through to `BACKEND_INTERNAL_URL` (which ends in `/api/v1`). One regen, one delete, one cleaner code path.

7. **`/api-docs` is always-on, no env flag.** SPEC §1 (routes table) declares `/api-docs` as a public page; SPEC §15 expects the OpenAPI spec to be public. The API surface is intentionally documented as a contract. We do **not** gate it behind `ENABLE_API_DOCS=true`. (Open question §12 #5 flags this for confirmation.)

8. **`deploy.yml` triggers on `v*` tags only.** Slice 7 creates `v1-rc1` locally, doesn't push it. The first real deploy is the operator's call. The workflow file is syntactically validated by GitHub's parser on push (a `workflow_run` won't fire, but a malformed YAML is caught immediately).

9. **`deploy.yml` runs `php artisan migrate --force` post-deploy.** Default yes — Railway's "Pre-Deploy Command" feature exists but is service-config rather than repo-tracked; doing migrate in the workflow makes the contract explicit and audit-able. The order is: backend `railway up` (which runs build) → wait for backend healthy → `railway run --service backend php artisan migrate --force` → frontend `railway up` → health-check both. (Open question §12 #2 flags this.)

10. **Tag name: `v1-rc1`** (release candidate 1), not `v1.0.0` and not `v1.0.0-rc.1`. Reasoning: `v1.0.0` implies we've already shipped to prod (we haven't), and `v1.0.0-rc.1` is strict semver but reads as ceremony for a project that's never had a numbered release. `v1-rc1` signals "code-complete, awaiting first real deploy." The first deploy gets `v1.0.0`. (Open question §12 #3.)

11. **`make smoke` is in scope.** A `make smoke` Makefile target that `curl`s every public endpoint (health, register-then-login, fishes list, breeds, repo aquarium for `vercel/next.js`, openapi.json) is short, useful for post-deploy verification, and free. It's not run by CI; it's a hand tool for the operator. (Open question §12 #4 flags this default.)

12. **CSP `report-uri` deferred.** Adding `report-uri https://o0.ingest.sentry.io/api/0/security/?sentry_key=…` would give us live CSP-violation telemetry. We defer because (a) it couples us to Sentry's CSP endpoint at exactly the moment we're testing Sentry's SDK init, and (b) CSP misconfiguration in v1 is far more likely to manifest as "MinIO image won't load locally" than as a hostile injection. Post-launch ops slice adds it.

13. **Lint cleanup is best-effort.** The `react-compiler` ↔ `react-hook-form` warning may not have a cheap fix in the current versions. We try; if the fix requires restructuring the form, we add an `eslint-disable-next-line` with a comment citing the upstream issue. Don't block the slice on a lint warning.

14. **Bundle-size guard for `/api-docs`.** Slice 6 left the `/fish` route at ~180 KB gzipped (under the AGENT.md §6 budget). Slice 7 adds `swagger-ui-react`, which is ~600 KB compressed. The `next/dynamic({ ssr: false })` split keeps it in `/api-docs`'s chunk only. We verify with `npm run build`'s route-payload output table: `/fish` JS budget must remain < 200 KB; `/api-docs` may exceed 600 KB (no budget there).

15. **`SecurityHeaders` middleware runs after CORS.** Laravel 13's CORS middleware (`HandleCors`) is registered automatically. By `append`-ing `SecurityHeaders` to the global stack, we ensure CORS preflight `OPTIONS` responses also carry our headers, and that exception responses (rendered in `withExceptions`) get them too — a 422 with our security headers is still hardened.

16. **`HSTS` preload list NOT requested in v1.** `Strict-Transport-Security: max-age=31536000; includeSubDomains` is the baseline. We deliberately omit `preload` (which would require submission to the HSTS preload list) until we've run for 90 days without a TLS hiccup. Operational decision; the middleware accepts an env-driven config so adding `preload` later is a single env-flip.

17. **`<Image>` for backgrounds requires hostname allowlist.** Add `s3.amazonaws.com` (wildcard for region buckets) and `*.r2.cloudflarestorage.com` to `next.config.ts` `images.remotePatterns`. Also `localhost:9000` for local MinIO previews. Without the allowlist, `<Image>` refuses to load remote URLs.

18. **Coverage gates** stay as slice 6 left them (backend ≥ 80% on `app/Services/` and `app/Http/Controllers/`; frontend ≥ 70% statements; `Fish` class + `useAquariumStore` at 100%). Slice 7's new surfaces (`SecurityHeaders`, `SentryEventScrubber`, `/api-docs` page, `before-send.test.ts`) are unit-covered.

---

## 4. Proxy Path Cleanup — Before / After

```
                    ┌───────── BEFORE (slice 6) ─────────┐
                    │                                     │
   Browser          │   Generated client                  │   Next.js proxy            Backend
   ───────          │   ─────────────────                 │   ────────────             ───────
   useFishesQuery() │   BASE_PATH = '/api/v1'             │
        │           │   path     = '/api/v1/fishes'       │
        ▼           │   url      = '/api/v1/api/v1/fishes'│   /api/proxy/[...path]    /api/v1/fishes
   proxiedFetch ────┤   rewritePath → '/api/proxy/fishes' ├──> path=['fishes']  ────> 200 OK
                    │                                     │
                    └─────────────────────────────────────┘

                    ┌───────── AFTER (slice 7) ──────────┐
                    │                                     │
   useFishesQuery() │   BASE_PATH = '/api/proxy'          │
        │           │   path     = '/fishes'              │
        ▼           │   url      = '/api/proxy/fishes'    │   /api/proxy/[...path]    /api/v1/fishes
        fetch ──────┤   (no shim)                         ├──> path=['fishes']  ────> 200 OK
                    │                                     │
                    └─────────────────────────────────────┘
```

The bug-prone double-prefix is gone. `OA\Server(url: '/api/v1')` stays on the backend (it tells human readers and downstream codegen consumers "this spec is mounted at /api/v1"); the frontend client's `BASE_PATH` is the proxy's mount (`/api/proxy`), and the proxy itself terminates `BACKEND_INTERNAL_URL` in `/api/v1`. One version-prefix, one place, end-to-end.

---

## 5. CSP Composition Table

| Directive | Production value | Local/report-only value | Rationale |
|---|---|---|---|
| `default-src` | `'self'` | `'self'` | Default-deny; everything explicit. |
| `script-src` | `'self'` | `'self' 'unsafe-eval'` | Next.js dev mode uses `eval` for HMR. Production has no `unsafe-eval`. |
| `style-src` | `'self' 'unsafe-inline'` | `'self' 'unsafe-inline'` | Tailwind + Next.js inline critical CSS. `unsafe-inline` is required by both; we accept the risk (XSS mitigation lives in React's escape pipeline). |
| `img-src` | `'self' https://*.r2.cloudflarestorage.com https://*.s3.amazonaws.com data:` | `'self' https://*.r2.cloudflarestorage.com https://*.s3.amazonaws.com http://localhost:9000 data:` | Production: R2 + S3 only. Local: add MinIO. `data:` covers tiny SVG data-URIs. |
| `connect-src` | `'self' https://api.fishbook.neri.ph` | `'self' http://localhost:8000` | Backend XHR/fetch. Local talks to `localhost:8000`; prod talks to `api.fishbook.neri.ph`. |
| `font-src` | `'self' data:` | `'self' data:` | Inter via next/font is self-hosted. |
| `frame-ancestors` | `'none'` | `'none'` | Prevent clickjacking. Mirrors `X-Frame-Options: DENY`. |
| `form-action` | `'self'` | `'self'` | Only same-origin form posts. |
| `base-uri` | `'self'` | `'self'` | Prevent base-URL injection. |
| `object-src` | `'none'` | `'none'` | No Flash, no plugins. |

Header name: `Content-Security-Policy` in production (enforced), `Content-Security-Policy-Report-Only` in `local`/`testing` (warnings only, no enforcement). The middleware constructs the policy string from a config-array per-directive, so test cases assert specific directive content without string-matching the full policy.

---

## 6. Sentry Init Sequence

```
   ┌── Backend boot ────────────────────────────────────────────┐
   │                                                            │
   │   composer autoload                                        │
   │       └─> Sentry\Laravel\ServiceProvider                   │
   │             └─> reads config/sentry.php                    │
   │                  │                                         │
   │                  ├─ dsn empty?  ──> no-op SDK (return)     │
   │                  │                                         │
   │                  └─ dsn set?    ──> Sentry::init(...)      │
   │                                       beforeSend hook:     │
   │                                       SentryEventScrubber  │
   │                                                            │
   │   AppServiceProvider::boot()                               │
   │       └─> if production: URL::forceScheme('https')         │
   │                                                            │
   │   Middleware stack: SecurityHeaders (appended)             │
   │                                                            │
   └────────────────────────────────────────────────────────────┘

   ┌── Frontend boot (Next.js App Router) ──────────────────────┐
   │                                                            │
   │   next.config.ts                                           │
   │       └─> if NEXT_PUBLIC_SENTRY_DSN set:                   │
   │             withSentryConfig(nextConfig, { ... })          │
   │           else: export nextConfig as-is                    │
   │                                                            │
   │   Runtime:                                                 │
   │     ├─ sentry.client.config.ts → Sentry.init(...)          │
   │     │     beforeSend hook: redact authorization, cookie,   │
   │     │     password, token, api_key in headers + body +     │
   │     │     extra + contexts                                 │
   │     │                                                      │
   │     ├─ sentry.server.config.ts → Sentry.init(...)          │
   │     │                                                      │
   │     └─ sentry.edge.config.ts → Sentry.init(...)            │
   │                                                            │
   │   The three configs are loaded by @sentry/nextjs before    │
   │   the first React render — verified in tests.              │
   │                                                            │
   └────────────────────────────────────────────────────────────┘
```

Both scrubbers are unit-tested by direct invocation. No outbound Sentry calls land in CI; the DSN is empty in `phpunit.xml` and in `frontend/vitest.config.ts`'s test env.

---

## 7. `deploy.yml` Shape

```
┌─ Trigger ────────────────────────────────────────────────────┐
│ on:                                                          │
│   push:                                                      │
│     tags: ['v*']                                             │
└──────────────────────────────────────────────────────────────┘

┌─ Job: deploy (ubuntu-latest) ────────────────────────────────┐
│  1. actions/checkout@v4                                     │
│  2. actions/setup-node@v4 (node 20)                          │
│  3. npm install -g @railway/cli@latest                       │
│  4. railway up --service backend  --detach                  │
│       env: RAILWAY_TOKEN: ${{ secrets.RAILWAY_TOKEN }}      │
│  5. wait-for healthcheck:                                   │
│       loop max 60s: curl -fsS https://api.fishbook.neri.ph/api/v1/health │
│  6. railway run --service backend php artisan migrate --force │
│  7. railway up --service frontend --detach                   │
│  8. wait-for healthcheck:                                   │
│       loop max 60s: curl -fsS https://fishbook.neri.ph/api/health │
│  9. if-failure: workflow exits non-zero (manual rollback)   │
└──────────────────────────────────────────────────────────────┘
```

Slice 7 ships the file; the first real run is operational.

---

## 8. Threat Model Touch-Points

This slice is almost entirely defensive; the threat model gets *smaller*, not larger.

| Surface | Mitigation |
|---|---|
| **Clickjacking** | `X-Frame-Options: DENY` + CSP `frame-ancestors 'none'` (defense in depth). |
| **HTTPS downgrade / SSL stripping** | HSTS with `includeSubDomains` after first prod request; `URL::forceScheme('https')` ensures all backend-issued URLs (signed S3 URLs, redirects, links in emails) use https. |
| **MIME sniffing** | `X-Content-Type-Options: nosniff` everywhere; combined with Intervention Image's re-encode pipeline (slice 4), uploads can't masquerade as scripts. |
| **Referrer leakage** | `Referrer-Policy: strict-origin-when-cross-origin` — outbound clicks to GitHub from the repo aquarium don't leak the user's full path. |
| **Camera/mic/location** | `Permissions-Policy: camera=(), microphone=(), geolocation=()` — Fishbook never asks; we deny preemptively so a future XSS can't request them either. |
| **Cross-site script/image injection** | CSP `default-src 'self'`; tight `img-src` allowlist (R2, S3, data:); `script-src 'self'` in production. Any third-party CDN script would need an explicit allowlist entry (and we don't plan any). |
| **Sentry data exfiltration** | `beforeSend` redacts auth tokens, passwords, cookies on both sides before the event leaves the process. `send_default_pii: false` on backend. DSNs default empty → no risk in dev without explicit setup. |
| **Swagger UI as attack vector** | `/api-docs` is read-only. No "Try it out" auth flow that stores tokens in DOM (we leave the default `swagger-ui-react` auth modal but no one has tokens unless they manually paste; the API surface is already public). |
| **Deploy workflow secret exposure** | `RAILWAY_TOKEN` lives in GitHub Actions secrets; `deploy.yml` references it as `${{ secrets.RAILWAY_TOKEN }}`. No echo, no `set -x` in the workflow steps that touch the token. |

---

## 9. Testing Strategy

### 9.1 Backend (Pest)

| Layer | File | What |
|---|---|---|
| Unit | `tests/Unit/Middleware/SecurityHeadersTest.php` | Two `describe` blocks: `production` and `local`. Each invokes `(new SecurityHeaders)->handle($request, fn() => new Response())` and asserts the right header set. Production case asserts HSTS present + `Content-Security-Policy` header name + no MinIO in `img-src`. Local case asserts HSTS absent + `Content-Security-Policy-Report-Only` + `http://localhost:9000` in `img-src`. |
| Feature | `tests/Feature/SecurityHeadersFeatureTest.php` | `GET /api/v1/health` returns 200 + all five baseline headers; `GET /api/v1/fishes` with bearer token same. |
| Unit | `tests/Unit/AppServiceProviderTest.php` | Swap `app()->detectEnvironment(fn() => 'production')`, re-boot `AppServiceProvider`, assert `url('/x')` is `https://…/x`. |
| Unit | `tests/Unit/Logging/SentryEventScrubberTest.php` | One `it()` per scrub key: build a `Sentry\Event` with the key populated in headers / body / extra; pipe through `SentryEventScrubber::__invoke`; assert value is `'[REDACTED]'`. |
| Feature | `tests/Feature/OpenApiSpecTest.php` | Re-read `storage/api-docs/openapi.json`, assert every `paths.*` entry is bare (no `/api/v1/` prefix). Catches a regression where someone re-adds the prefix to a future controller. |

### 9.2 Frontend (Vitest)

| Layer | File | What |
|---|---|---|
| Unit | `tests/unit/sentry/before-send.test.ts` | Synthetic event with `request.headers.authorization`, `request.headers.cookie`, `extra.password`, `contexts.token` → assert all redacted. |
| Unit | `tests/unit/app/api-docs.test.tsx` | Render `<ApiDocsPage />` with a mocked `swagger-ui-react`; assert loading state shows, then dynamic chunk import is referenced. |
| Unit | `tests/unit/lib/fish/api.test.ts` (extend) | Assert no `rewritePath` symbol exported; the `fishesApi` is constructed with `basePath: '/api/proxy'`. |
| Unit | `tests/unit/lib/backgrounds/api.test.ts` (extend) | Same as above for `backgroundsApi`; `uploadBackground` still posts directly to `/api/proxy/backgrounds/upload`. |
| Build | `npm run build` route-payload output check | Slice 7's CI step parses `npm run build`'s emitted route table and asserts `/fish` first-load JS ≤ 200 KB. `/api-docs` is allowed to be larger. |

### 9.3 E2E (Playwright)

| Spec | Covers |
|---|---|
| `05-api-docs.spec.ts` (new) | Visit `/api-docs`, wait for an operation header (`/fishes` or `/health`), screenshot on failure. Mocks the spec URL via `page.route('**/openapi.json', …)` for resilience. |
| Existing 01-04 specs | Re-run; verify the shim removal doesn't break the proxy round-trip. |

### 9.4 Coverage gates

Stay as slice 6 left them: backend ≥ 80% on `app/Services/` and `app/Http/Controllers/`; frontend ≥ 70% statements; `Fish` class + `useAquariumStore` at 100%. New surfaces are unit-covered to keep the numbers above the bar.

---

## 10. Acceptance Criteria

1. **Phase A:** `php artisan l5-swagger:generate && git diff --exit-code backend/storage/api-docs/openapi.json` exits 0. `npm run generate:api && git diff --exit-code frontend/src/lib/api-client` exits 0.
2. `grep -RIn '/api/v1/' backend/app/Http/Controllers/Api/V1` shows no `path:` declaration containing `/api/v1/` (only the `Route::pattern` and use-statement matches).
3. `grep -RIn 'rewritePath\|proxiedFetch' frontend/src` returns nothing.
4. **Phase B:** `curl -sI http://localhost:8000/api/v1/health` carries `Content-Security-Policy-Report-Only`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: …`, **no** HSTS. The same request in a `APP_ENV=production`-simulated unit test carries `Content-Security-Policy` (enforced) and HSTS.
5. `URL::forceScheme('https')` is called in `AppServiceProvider::boot()` exactly when `app()->environment('production')`. Unit-tested.
6. **Phase C:** `docker compose exec backend ./vendor/bin/pest tests/Unit/Logging/SentryEventScrubberTest.php` is green; each scrub key is redacted.
7. `cd frontend && npm test -- --run tests/unit/sentry/before-send.test.ts` is green.
8. Backend `Sentry\Laravel\ServiceProvider` loads with empty `SENTRY_LARAVEL_DSN` and the app boots normally (no error, no outbound call). Asserted by the existing app-boot smoke test.
9. **Phase D:** `cd frontend && npm test -- --run tests/unit/app/api-docs.test.tsx` is green. `npx playwright test tests/e2e/05-api-docs.spec.ts` is green.
10. `npm run build` route-payload table shows `/fish` first-load JS ≤ 200 KB gzipped; `/api-docs` is a separate chunk.
11. **Phase E:** `.github/workflows/deploy.yml` exists, parses (YAML lint), triggers on `v*` tag push, runs the four Railway steps + healthcheck. No tag is pushed.
12. **Phase F:** `npm run lint` clean (zero warnings, or all remaining ones carry an `eslint-disable-next-line` with a justification comment). `<img>` in `BackgroundLayer` is now `next/image`; sprite `<img>` retained with documented rationale.
13. **Phase G:** `README.md` has a "Deployment" section and a `make deploy-prod` target; `CHANGELOG.md` exists with entries for slices 1–7.
14. `php artisan test`, `cd frontend && npm test -- --run`, and `cd frontend && npx playwright test` (against the e2e overlay) all pass.
15. Tag `v1-rc1` is created on `main` after merge.
16. SPEC §17 acceptance items 10 (Swagger UI reachable) and 12 (security checklist §16 satisfied) are now ticked.

---

## 11. Bundle-Size Verification

`npm run build` emits a "Route (app)" table with first-load JS column. Slice 7 budget:

| Route | First-load JS budget | Slice 6 baseline | Slice 7 target |
|---|---|---|---|
| `/fish` | ≤ 200 KB gzipped | ~180 KB | ≤ 200 KB (no regression) |
| `/api-docs` | (no budget; lazy chunk) | n/a | < 700 KB (informational) |
| `/` (landing) | ≤ 100 KB | ~80 KB | ≤ 100 KB |
| `/[username]/[repo]` | ≤ 200 KB | ~150 KB | ≤ 200 KB |

The `next/dynamic({ ssr: false })` on `swagger-ui-react` is the load-bearing decision: without it, `swagger-ui-react`'s ~600 KB would be tree-shaken poorly and could leak into the shared chunk. With it, the swagger import only lands in `/api-docs`'s chunk.

---

## 12. Open Questions / Follow-Ups

- Should we add CSP `report-uri` pointed at Sentry's CSP report endpoint? Default: no for v1 (adds Sentry coupling); revisit post-launch.
- Should `deploy.yml` run `php artisan migrate --force` post-deploy? Default: yes; Railway typically wires this via a deploy hook, but doing it in the workflow is explicit. Document the call.
- Tag name: `v1-rc1` (the plan default), `v1.0.0-rc.1` (semver), or `v1.0.0`? Default: `v1-rc1` to signal it's not a hard ship until first real Railway deploy.
- Should we include a `make smoke` Makefile target that exercises every public endpoint after a deploy (health, register, login, fishes, breeds, backgrounds, public repo aquarium)? Default: yes, useful for post-deploy verification.
- Should `frontend/src/app/api-docs/page.tsx` require a flag (e.g. `ENABLE_API_DOCS=true`) to render in production, to avoid exposing the API surface? SPEC says it's a public docs page; default: always-on. Document the decision.

---

## 13. What's intentionally NOT here

- Actually pushing `v1-rc1` to a remote, or actually deploying to Railway. Operational decision, not engineering.
- WAF / DDoS protection beyond what Railway provides natively. Cloudflare in front of Railway is a later operational decision.
- Privacy policy, ToS, legal copy. Product/legal scope.
- Subresource Integrity (SRI) on the `swagger-ui-react` CDN bundle — we ship via npm, so SRI doesn't apply.
- CSP `report-uri` / `report-to` header for CSP violation reports. Defer to a post-launch ops slice.
- Sentry release tracking (uploading source maps on deploy). Add to `deploy.yml` later when there's a real deploy history.
- Performance budgets enforced in CI via Lighthouse — deferred from slice 6, still deferred.
- Visual regression testing (Percy, Chromatic). Defer.
- Multi-region Railway, blue-green deployments, automated rollback on health-check failure (manual rollback via Railway UI for v1).
- `php-fpm + nginx` for the backend container — slice 6's `artisan serve` stays for v1; prod-grade web server is a post-v1 ops slice.
- Backend rate-limit middleware tuning beyond what slices 2–5 shipped.
- Username/password reset flow (the SPEC describes auth but not reset; that's a v1.1 feature).

---

## 14. Sources

- `SPEC.md` §13 (CI/CD — `deploy.yml`), §14 (Deployment — Railway), §15 (OpenAPI / Swagger — `/api-docs` + lazy load), §16 (Security Checklist — full set), §17 acceptance criteria items 10 and 12.
- `AGENT.md` §4 (security headers, HTTPS forcing, CORS scoped to `FRONTEND_URL`, Sentry `beforeSend` drops sensitive headers), §6 (bundle budgets — `/fish` < 200 KB, swagger lazy on `/api-docs`), §7 (regenerate OpenAPI + frontend client on backend route changes; commit both).
- Slice 6 design + plan — for the docker-compose-e2e overlay, the Playwright test layout, the route-payload budget pattern, the test-isolation discipline.
- `backend/bootstrap/app.php`, `backend/routes/api.php`, `backend/app/Http/Controllers/Api/V1/*.php` — current shape of the middleware stack, route patterns, and OA path declarations.
- `frontend/src/lib/fish/api.ts`, `frontend/src/lib/backgrounds/api.ts`, `frontend/src/app/api/proxy/[...path]/route.ts` — the proxy contract and the `rewritePath` shim removed in Phase A.
