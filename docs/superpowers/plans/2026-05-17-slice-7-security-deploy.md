# Slice 7 — Security, Observability & Deploy (v1 close-out) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` (or `superpowers:subagent-driven-development` for parallelizable chunks) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking; do **not** mark a checkbox until the step's expected output is observed.

**Goal:** Close out v1. After this slice: SPEC §16 security checklist is fully satisfied (HSTS/CSP/XFO/Referrer/Permissions middleware in place; HTTPS forced in production; Sentry SDK initialized on both sides with `beforeSend` scrubbers; CORS already scoped to `FRONTEND_URL` from slice 1); SPEC §15 (Swagger UI at `/api-docs`) is live via `swagger-ui-react` lazy-loaded through `next/dynamic`; SPEC §13 (`deploy.yml`) ships as a tag-triggered GitHub Actions workflow; the OpenAPI `path:` redundancy is cleaned up and the `rewritePath` proxy shim is deleted; slice 6's lint warnings are cleared. The final tag is `v1-rc1`.

**Architecture:** Phase A drops `/api/v1/` from controller `path:` annotations (single source of truth: `OA\Server(url: '/api/v1')`), regenerates `openapi.json` + the frontend client, deletes the `rewritePath` shim, points the generated client's `BASE_PATH` at `/api/proxy`. Phase B ships `App\Http\Middleware\SecurityHeaders`, registers it globally in `bootstrap/app.php`, gates HSTS + enforced CSP on `app()->environment('production')`, falls back to `Content-Security-Policy-Report-Only` + MinIO-friendly `img-src` locally; `AppServiceProvider::boot()` adds `URL::forceScheme('https')` under the same env gate. Phase C wires Sentry on both sides: backend gets `sentry/sentry-laravel` + a `SentryEventScrubber` invokable; frontend gets `@sentry/nextjs` + three `sentry.*.config.ts` files with a `beforeSend` redactor; DSNs default empty (SDKs no-op); scrubbers are unit-tested by direct invocation. Phase D ships `frontend/src/app/api-docs/page.tsx` as a `'use client'` route that lazy-loads `swagger-ui-react` via `next/dynamic({ ssr: false })`, plus a backend route serving the committed `openapi.json`. Phase E writes `.github/workflows/deploy.yml` triggering on `v*` tag push (not exercised in slice 7). Phase F is best-effort lint cleanup: `BackgroundLayer` swaps to `next/image`; sprite `<img>` stays with documented rationale; Sanctum unused import removed; Inter font `display: 'swap'` verified. Phase G updates `README.md`, ships `CHANGELOG.md`, tags `v1-rc1`.

**Tech Stack:** Laravel 13 + PHP 8.3 + Pest + Larastan (new backend dep: `sentry/sentry-laravel ^4.x`). Next.js 16 + React 19 + TS strict (new frontend deps: `@sentry/nextjs ^8.x`, `swagger-ui-react ^5.x`, `@types/swagger-ui-react`). No new tooling.

**Spec:** [`docs/superpowers/specs/2026-05-17-slice-7-security-deploy-design.md`](../specs/2026-05-17-slice-7-security-deploy-design.md).

---

## Conventions

- Today's date for commit messages is **2026-05-17**.
- All backend commands use `docker compose exec backend …` so Postgres `jsonb` and Redis are real.
- Conventional Commits (`feat:`, `fix:`, `chore:`, `test:`, `docs:`, `refactor:`, `ci:`, `perf:`, `build:`).
- One task = one commit. Don't squash.
- Commit trailer:
  ```
  Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
  ```
  Use heredoc form (see slice 6 plan §Conventions).
- TDD where practical: failing test first, then code, then green.
- Build on `main` after slice 6's `slice-6-e2e-perf-polish` tag.
- Frontend tests: `cd frontend && npm test -- --run`. Backend tests: `docker compose exec backend ./vendor/bin/pest`. Playwright: `cd frontend && npx playwright test`.

---

## Task 1: Slice prep — verify slice 6 baseline + plan check

**Files:** (none — verification only)

- [ ] **Step 1: Confirm clean tree on `main` at slice 6 tag.**
  ```bash
  git status
  git describe --tags --abbrev=0
  ```
  Expected: working tree clean; tag prints `slice-6-e2e-perf-polish`.

- [ ] **Step 2: Confirm the surfaces slice 7 will touch exist.**
  ```bash
  test -f backend/bootstrap/app.php && echo OK-bootstrap
  test -f backend/app/Providers/AppServiceProvider.php && echo OK-app-svc
  test -f frontend/src/lib/fish/api.ts && grep -q rewritePath frontend/src/lib/fish/api.ts && echo OK-fish-shim
  test -f frontend/src/lib/backgrounds/api.ts && grep -q rewritePath frontend/src/lib/backgrounds/api.ts && echo OK-bg-shim
  ls frontend/src/app/api-docs 2>/dev/null && echo "WARN: api-docs already exists" || echo OK-no-api-docs
  ls .github/workflows/deploy.yml 2>/dev/null && echo "WARN: deploy.yml already exists" || echo OK-no-deploy
  ```
  Expected: all `OK-` lines.

- [ ] **Step 3: Inventory current `path:` redundancy.**
  ```bash
  grep -RIn "path: '/api/v1/" backend/app/Http/Controllers/Api/V1 | tee /tmp/slice7-path-prefix.txt
  wc -l /tmp/slice7-path-prefix.txt
  ```
  Expected: a non-empty list (Fish/Background/RepoAquarium controllers). This is the surface Phase A cleans up.

- [ ] **Step 4: No commit.** Verification only.

---

# Phase A — OpenAPI path-prefix cleanup

## Task 2: Backend — drop `/api/v1/` from controller OA `path:` declarations

**Files:**
- Edit: `backend/app/Http/Controllers/Api/V1/FishController.php`
- Edit: `backend/app/Http/Controllers/Api/V1/BackgroundController.php`
- Edit: `backend/app/Http/Controllers/Api/V1/RepoAquariumController.php`

- [ ] **Step 1: Edit each controller's `OA\Get/Post/Patch/Delete` `path:` value, stripping the `/api/v1/` prefix.**
  - `FishController.php` → 6 sites: `/fishes` (index, store), `/fishes/{fish}` (show, update, destroy), `/fishes/breeds`.
  - `BackgroundController.php` → 5 sites: `/backgrounds`, `/backgrounds/upload`, `/backgrounds/generate`, `/backgrounds/{background}/select`, `/backgrounds/{background}`.
  - `RepoAquariumController.php` → 2 sites: `/repos/{owner}/{repo}/aquarium`, `/repos/{owner}/{repo}/fork-to-my-aquarium`.

- [ ] **Step 2: Sanity-grep.**
  ```bash
  grep -RIn "path: '/api/v1/" backend/app/Http/Controllers/Api/V1
  ```
  Expected: zero matches.

- [ ] **Step 3: Confirm `OA\Server(url: '/api/v1')` still present on `HealthController`.**
  ```bash
  grep -n "OA\\\\Server" backend/app/Http/Controllers/Api/V1/HealthController.php
  ```
  Expected: one match — `#[OA\Server(url: '/api/v1')]`.

- [ ] **Step 4: Do not regenerate yet** (next task is the regen + commit together).

---

## Task 3: Backend — regenerate `openapi.json`; frontend — regenerate api-client

**Files:**
- Regenerate: `backend/storage/api-docs/openapi.json`
- Regenerate: `frontend/src/lib/api-client/**`

- [ ] **Step 1: Spec regen.**
  ```bash
  docker compose up -d
  docker compose exec backend php artisan l5-swagger:generate
  ```

- [ ] **Step 2: Inspect the diff.**
  ```bash
  git diff backend/storage/api-docs/openapi.json | head -120
  ```
  Expected: every entry under `paths` is now bare (e.g. `/fishes`, `/backgrounds/upload`); no `/api/v1/api/v1/...` artifacts; `servers[0].url` still `/api/v1`.

- [ ] **Step 3: Frontend client regen.**
  ```bash
  cd frontend && npm run generate:api
  ```

- [ ] **Step 4: Inspect the client diff.**
  ```bash
  git diff frontend/src/lib/api-client | head -80
  ```
  Expected: path constants in `apis/*.ts` change from `/api/v1/fishes` etc. to `/fishes` etc. `BASE_PATH` in `runtime.ts` stays `/api/v1` (the generator uses `servers[0].url`).

- [ ] **Step 5: Commit annotation edits + both regens together.**
  ```bash
  git add backend/app/Http/Controllers/Api/V1/FishController.php \
          backend/app/Http/Controllers/Api/V1/BackgroundController.php \
          backend/app/Http/Controllers/Api/V1/RepoAquariumController.php \
          backend/storage/api-docs/openapi.json \
          frontend/src/lib/api-client
  git commit -m "$(cat <<'EOF'
  refactor(openapi): drop /api/v1 prefix from controller path: annotations

  OA\Server(url: '/api/v1') on HealthController is now the single source of truth
  for the version prefix. Regenerate spec + frontend client in the same commit so
  the diff is coherent.

  Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
  EOF
  )"
  ```

---

## Task 4: Frontend — delete `rewritePath` shim; switch generated client to `/api/proxy`

**Files:**
- Edit: `frontend/src/lib/fish/api.ts`
- Edit: `frontend/src/lib/backgrounds/api.ts`
- Edit: `frontend/tests/unit/lib/fish/api.test.ts` (if present; extend or create)
- Edit: `frontend/tests/unit/lib/backgrounds/api.test.ts` (if present; extend or create)

- [ ] **Step 1: Write failing tests first.**
  - In `frontend/tests/unit/lib/fish/api.test.ts`: assert `fishesApi['configuration'].basePath === '/api/proxy'`; assert `import('@/lib/fish/api')` exports no symbol named `rewritePath` or `proxiedFetch`.
  - Same for `backgrounds/api.ts`.

- [ ] **Step 2: Run — expected RED.**
  ```bash
  cd frontend && npm test -- --run tests/unit/lib/fish/api.test.ts tests/unit/lib/backgrounds/api.test.ts
  ```

- [ ] **Step 3: Collapse `frontend/src/lib/fish/api.ts` to:**
  ```ts
  import { Configuration, FishesApi } from '@/lib/api-client';
  const config = new Configuration({ basePath: '/api/proxy' });
  export const fishesApi = new FishesApi(config);
  ```

- [ ] **Step 4: Collapse `frontend/src/lib/backgrounds/api.ts` to the BackgroundsApi version, keeping the existing `uploadBackground` multipart helper (it already posts to `/api/proxy/backgrounds/upload` directly).**

- [ ] **Step 5: Run — expected GREEN.**
  ```bash
  cd frontend && npm test -- --run tests/unit/lib/fish/api.test.ts tests/unit/lib/backgrounds/api.test.ts
  ```

- [ ] **Step 6: Re-run the full frontend suite to catch any hook that depended on the old behavior.**
  ```bash
  cd frontend && npm test -- --run
  ```
  Expected: green.

- [ ] **Step 7: Commit.**
  ```bash
  git add frontend/src/lib/fish/api.ts frontend/src/lib/backgrounds/api.ts \
          frontend/tests/unit/lib/fish/api.test.ts frontend/tests/unit/lib/backgrounds/api.test.ts
  git commit -m "$(cat <<'EOF'
  refactor(frontend): remove rewritePath shim — generated client targets /api/proxy directly

  Now that controller path: annotations are bare and the proxy at /api/proxy/[...path]
  terminates BACKEND_INTERNAL_URL in /api/v1, the shim is unnecessary.

  Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
  EOF
  )"
  ```

---

## Task 5: Backend — feature test pinning the bare-path invariant

**Files:**
- Create: `backend/tests/Feature/OpenApiSpecTest.php`

- [ ] **Step 1: Write the test.**
  ```php
  <?php

  use function Pest\Laravel\get;

  it('emits bare paths (no /api/v1 prefix) under paths', function () {
      $spec = json_decode(file_get_contents(storage_path('api-docs/openapi.json')), true);
      foreach (array_keys($spec['paths'] ?? []) as $path) {
          expect($path)->not->toStartWith('/api/v1');
      }
  });

  it('declares /api/v1 exactly once via servers[0].url', function () {
      $spec = json_decode(file_get_contents(storage_path('api-docs/openapi.json')), true);
      expect($spec['servers'][0]['url'] ?? null)->toBe('/api/v1');
  });
  ```

- [ ] **Step 2: Run — expected GREEN (Task 3 already regenerated the spec).**
  ```bash
  docker compose exec backend ./vendor/bin/pest tests/Feature/OpenApiSpecTest.php
  ```

- [ ] **Step 3: Commit.**
  ```bash
  git add backend/tests/Feature/OpenApiSpecTest.php
  git commit -m "$(cat <<'EOF'
  test(backend): pin OpenAPI bare-path invariant — catches /api/v1 prefix regressions

  Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
  EOF
  )"
  ```

---

## Task 6: Playwright sanity re-run (no shim → still green)

**Files:** (none — verification only)

- [ ] **Step 1: Bring up the e2e overlay.**
  ```bash
  docker compose -f docker-compose.yml -f docker-compose.e2e.yml up -d --build
  docker compose exec -T backend php artisan migrate --force
  ```

- [ ] **Step 2: Run Playwright.**
  ```bash
  cd frontend && npx playwright test
  ```
  Expected: 4 specs green (auth, fish-crud, backgrounds, repo-aquarium).

- [ ] **Step 3: Tear down.**
  ```bash
  docker compose -f docker-compose.yml -f docker-compose.e2e.yml down -v
  ```

- [ ] **Step 4: No commit.** Verification gate before Phase B.

---

# Phase B — Security headers + HTTPS forcing

## Task 7: Backend — `SecurityHeaders` middleware (test-first)

**Files:**
- Create: `backend/app/Http/Middleware/SecurityHeaders.php`
- Create: `backend/tests/Unit/Middleware/SecurityHeadersTest.php`

- [ ] **Step 1: Write the failing test.**

  `tests/Unit/Middleware/SecurityHeadersTest.php`:
  ```php
  <?php

  use App\Http\Middleware\SecurityHeaders;
  use Illuminate\Http\Request;
  use Illuminate\Http\Response;

  it('sets baseline headers + enforced CSP + HSTS in production', function () {
      app()->detectEnvironment(fn () => 'production');
      $resp = (new SecurityHeaders)->handle(Request::create('/x'), fn () => new Response());
      expect($resp->headers->get('X-Content-Type-Options'))->toBe('nosniff');
      expect($resp->headers->get('X-Frame-Options'))->toBe('DENY');
      expect($resp->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
      expect($resp->headers->get('Permissions-Policy'))->toContain('camera=()');
      expect($resp->headers->get('Strict-Transport-Security'))->toContain('max-age=31536000');
      expect($resp->headers->get('Content-Security-Policy'))->not->toBeNull();
      expect($resp->headers->get('Content-Security-Policy'))->not->toContain('localhost:9000');
      expect($resp->headers->get('Content-Security-Policy-Report-Only'))->toBeNull();
  });

  it('uses report-only CSP + no HSTS + MinIO in img-src locally', function () {
      app()->detectEnvironment(fn () => 'local');
      $resp = (new SecurityHeaders)->handle(Request::create('/x'), fn () => new Response());
      expect($resp->headers->get('Strict-Transport-Security'))->toBeNull();
      expect($resp->headers->get('Content-Security-Policy'))->toBeNull();
      expect($resp->headers->get('Content-Security-Policy-Report-Only'))->toContain('localhost:9000');
  });
  ```

- [ ] **Step 2: Run — expected RED (middleware class doesn't exist yet).**

- [ ] **Step 3: Write `backend/app/Http/Middleware/SecurityHeaders.php`.** Build the CSP string from a config-array per directive (see design §5). Switch header name + `img-src` content on `$this->app->environment('production')`. Add HSTS only in production.

- [ ] **Step 4: Run — expected GREEN.**
  ```bash
  docker compose exec backend ./vendor/bin/pest tests/Unit/Middleware/SecurityHeadersTest.php
  ```

- [ ] **Step 5: Commit.**
  ```bash
  git add backend/app/Http/Middleware/SecurityHeaders.php \
          backend/tests/Unit/Middleware/SecurityHeadersTest.php
  git commit -m "$(cat <<'EOF'
  feat(backend): add SecurityHeaders middleware — HSTS/CSP/XFO/Referrer/Permissions

  Production: enforced CSP + HSTS, R2/S3 in img-src.
  Local/testing: report-only CSP + MinIO localhost in img-src, no HSTS.

  Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
  EOF
  )"
  ```

---

## Task 8: Backend — register `SecurityHeaders` globally + feature test

**Files:**
- Edit: `backend/bootstrap/app.php`
- Create: `backend/tests/Feature/SecurityHeadersFeatureTest.php`

- [ ] **Step 1: Edit `bootstrap/app.php` `withMiddleware` closure.**
  ```php
  ->withMiddleware(function (Middleware $middleware): void {
      $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
  })
  ```

- [ ] **Step 2: Write feature test.**

  `tests/Feature/SecurityHeadersFeatureTest.php`:
  ```php
  <?php

  use function Pest\Laravel\getJson;

  it('stamps security headers on public health route', function () {
      $r = getJson('/api/v1/health');
      $r->assertOk();
      expect($r->headers->get('X-Content-Type-Options'))->toBe('nosniff');
      expect($r->headers->get('X-Frame-Options'))->toBe('DENY');
      expect($r->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
      expect($r->headers->get('Permissions-Policy'))->toContain('camera=()');
  });
  ```

- [ ] **Step 3: Run — expected GREEN.**
  ```bash
  docker compose exec backend ./vendor/bin/pest tests/Feature/SecurityHeadersFeatureTest.php
  ```

- [ ] **Step 4: Verify with curl.**
  ```bash
  curl -sI http://localhost:8000/api/v1/health | grep -iE 'content-security|x-frame|x-content-type|referrer|permissions|strict-transport'
  ```
  Expected: 5 headers (CSP-Report-Only in local; no HSTS in local).

- [ ] **Step 5: Commit.**
  ```bash
  git add backend/bootstrap/app.php backend/tests/Feature/SecurityHeadersFeatureTest.php
  git commit -m "$(cat <<'EOF'
  feat(backend): register SecurityHeaders middleware globally; feature-test the stamp

  Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
  EOF
  )"
  ```

---

## Task 9: Backend — `URL::forceScheme('https')` in production

**Files:**
- Edit: `backend/app/Providers/AppServiceProvider.php`
- Create: `backend/tests/Unit/Providers/AppServiceProviderTest.php`

- [ ] **Step 1: Write failing test.**
  ```php
  it('forces https URLs in production', function () {
      app()->detectEnvironment(fn () => 'production');
      (new \App\Providers\AppServiceProvider(app()))->boot();
      expect(url('/x'))->toStartWith('https://');
  });

  it('does not force https locally', function () {
      app()->detectEnvironment(fn () => 'local');
      // re-boot to reset URL generator scheme
      expect(url('/x'))->not->toStartWith('https://');
  });
  ```

- [ ] **Step 2: Run — expected RED.**

- [ ] **Step 3: Edit `AppServiceProvider::boot()` — add:**
  ```php
  use Illuminate\Support\Facades\URL;
  // ...
  public function boot(): void
  {
      if ($this->app->environment('production')) {
          URL::forceScheme('https');
      }
  }
  ```

- [ ] **Step 4: Run — expected GREEN.**

- [ ] **Step 5: Commit.**
  ```bash
  git add backend/app/Providers/AppServiceProvider.php \
          backend/tests/Unit/Providers/AppServiceProviderTest.php
  git commit -m "$(cat <<'EOF'
  feat(backend): force https scheme on URL generator in production

  Strictly env-gated on app()->environment('production'). Local dev keeps http://.

  Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
  EOF
  )"
  ```

---

# Phase C — Sentry init (backend + frontend)

## Task 10: Backend — install `sentry/sentry-laravel`; publish + edit `config/sentry.php`

**Files:**
- Edit: `backend/composer.json` (`composer require sentry/sentry-laravel`)
- Create: `backend/config/sentry.php` (via publish)

- [ ] **Step 1: Install.**
  ```bash
  docker compose exec backend composer require sentry/sentry-laravel
  docker compose exec backend php artisan sentry:publish --dsn=
  ```

- [ ] **Step 2: Edit `config/sentry.php` to match design §2 Phase C shape:**
  ```php
  'dsn'                => env('SENTRY_LARAVEL_DSN'),
  'traces_sample_rate' => env('SENTRY_TRACES_SAMPLE_RATE', 0.1),
  'send_default_pii'   => false,
  'before_send'        => [\App\Logging\SentryEventScrubber::class, '__invoke'],
  ```

- [ ] **Step 3: Add test-env defaults to `phpunit.xml`:**
  ```xml
  <env name="SENTRY_LARAVEL_DSN" value=""/>
  <env name="SENTRY_TRACES_SAMPLE_RATE" value="0"/>
  ```

- [ ] **Step 4: Verify boot is clean.**
  ```bash
  docker compose exec backend php artisan about | grep -i sentry
  ```
  Expected: Sentry section present, DSN empty.

- [ ] **Step 5: Commit.**
  ```bash
  git add backend/composer.json backend/composer.lock backend/config/sentry.php \
          backend/phpunit.xml
  git commit -m "$(cat <<'EOF'
  build(backend): add sentry/sentry-laravel; publish config; DSN defaults empty (no-op)

  Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
  EOF
  )"
  ```

---

## Task 11: Backend — `SentryEventScrubber` invokable (test-first)

**Files:**
- Create: `backend/app/Logging/SentryEventScrubber.php`
- Create: `backend/tests/Unit/Logging/SentryEventScrubberTest.php`

- [ ] **Step 1: Write failing tests.**
  ```php
  <?php

  use App\Logging\SentryEventScrubber;
  use Sentry\Event;
  use Sentry\EventId;

  function scrub(array $request = [], array $extra = []): Event {
      $e = Event::createEvent();
      $e->setRequest($request);
      $e->setExtra($extra);
      return (new SentryEventScrubber)($e);
  }

  it('redacts Authorization header', function () {
      $e = scrub(['headers' => ['Authorization' => 'Bearer abc']]);
      expect($e->getRequest()['headers']['Authorization'])->toBe('[REDACTED]');
  });

  it('redacts password in request body', function () {
      $e = scrub(['data' => ['password' => 'hunter2']]);
      expect($e->getRequest()['data']['password'])->toBe('[REDACTED]');
  });

  it('redacts FAL_API_KEY in extra', function () {
      $e = scrub(extra: ['FAL_API_KEY' => 'k_live_xxx']);
      expect($e->getExtra()['FAL_API_KEY'])->toBe('[REDACTED]');
  });

  // Repeat for: token, api_key, password_confirmation
  ```

- [ ] **Step 2: Run — expected RED.**

- [ ] **Step 3: Implement `App\Logging\SentryEventScrubber`.** Invokable: case-insensitively iterate `headers`/`data`/`extra`; redact in place; return the (non-null) event.

- [ ] **Step 4: Run — expected GREEN.**

- [ ] **Step 5: Commit.**
  ```bash
  git add backend/app/Logging/SentryEventScrubber.php \
          backend/tests/Unit/Logging/SentryEventScrubberTest.php
  git commit -m "$(cat <<'EOF'
  feat(backend): SentryEventScrubber redacts Authorization/password/token/api_key/FAL_API_KEY

  Tested by direct invocation against synthetic Sentry\Event instances. No live calls.

  Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
  EOF
  )"
  ```

---

## Task 12: Backend — `php artisan fishbook:sentry-smoke` artisan command

**Files:**
- Edit: `backend/routes/console.php`

- [ ] **Step 1: Add command to `routes/console.php`:**
  ```php
  Artisan::command('fishbook:sentry-smoke', function () {
      throw new \RuntimeException('Sentry smoke (intentional)');
  })->purpose('Throw an exception to validate Sentry wiring; non-production only.')
    ->unlessEnvironment('production');
  ```

- [ ] **Step 2: Verify locally.**
  ```bash
  docker compose exec backend php artisan fishbook:sentry-smoke
  ```
  Expected: RuntimeException stacktrace. With DSN empty, no event is sent.

- [ ] **Step 3: Commit.**
  ```bash
  git add backend/routes/console.php
  git commit -m "$(cat <<'EOF'
  feat(backend): add fishbook:sentry-smoke artisan command (non-production only)

  Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
  EOF
  )"
  ```

---

## Task 13: Frontend — install `@sentry/nextjs`; write three `sentry.*.config.ts` files

**Files:**
- Edit: `frontend/package.json` + `package-lock.json`
- Create: `frontend/sentry.client.config.ts`
- Create: `frontend/sentry.server.config.ts`
- Create: `frontend/sentry.edge.config.ts`

- [ ] **Step 1: Install.**
  ```bash
  cd frontend && npm install --save @sentry/nextjs@^8
  ```

- [ ] **Step 2: Write each config file.** Pattern:
  ```ts
  import * as Sentry from '@sentry/nextjs';
  import { buildBeforeSend } from '@/lib/sentry/before-send';

  const dsn = process.env.NEXT_PUBLIC_SENTRY_DSN ?? process.env.SENTRY_DSN ?? '';
  if (dsn) {
    Sentry.init({
      dsn,
      tracesSampleRate: 0.1,
      beforeSend: buildBeforeSend(),
    });
  }
  ```
  `sentry.server.config.ts` reads `SENTRY_DSN` first; `sentry.edge.config.ts` same.

- [ ] **Step 3: Commit (scrubber lands in Task 14; configs reference it ahead of time, so compile may break — sequence Task 13+14 as one logical pair, commit after both).** Skip commit; proceed.

---

## Task 14: Frontend — `buildBeforeSend` scrubber (test-first)

**Files:**
- Create: `frontend/src/lib/sentry/before-send.ts`
- Create: `frontend/tests/unit/sentry/before-send.test.ts`

- [ ] **Step 1: Write failing test.**
  ```ts
  import { describe, expect, it } from 'vitest';
  import { buildBeforeSend } from '@/lib/sentry/before-send';

  describe('Sentry beforeSend scrubber', () => {
    it('redacts authorization header', () => {
      const send = buildBeforeSend();
      const ev = { request: { headers: { authorization: 'Bearer abc' } } };
      const out = send(ev as never);
      expect(out!.request!.headers!.authorization).toBe('[REDACTED]');
    });

    it('redacts cookie + password + token + api_key', () => { /* ... */ });
  });
  ```

- [ ] **Step 2: Run — expected RED.**
  ```bash
  cd frontend && npm test -- --run tests/unit/sentry/before-send.test.ts
  ```

- [ ] **Step 3: Implement `buildBeforeSend`** — walks `event.request?.headers`, `event.request?.data`, `event.extra`, `event.contexts`, replaces sensitive keys (case-insensitive) with `'[REDACTED]'`, returns event.

- [ ] **Step 4: Run — expected GREEN.**

- [ ] **Step 5: Commit Task 13 + 14 together.**
  ```bash
  git add frontend/package.json frontend/package-lock.json \
          frontend/sentry.client.config.ts frontend/sentry.server.config.ts frontend/sentry.edge.config.ts \
          frontend/src/lib/sentry/before-send.ts \
          frontend/tests/unit/sentry/before-send.test.ts
  git commit -m "$(cat <<'EOF'
  feat(frontend): wire @sentry/nextjs init + beforeSend scrubber

  DSN defaults empty (SDK no-ops). Scrubber redacts authorization/cookie/password/token/api_key
  on headers, body, extra, contexts. Unit-tested by direct invocation.

  Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
  EOF
  )"
  ```

---

## Task 15: Frontend — guard `withSentryConfig` in `next.config.ts`

**Files:**
- Edit: `frontend/next.config.ts`

- [ ] **Step 1: Wrap the config conditionally.**
  ```ts
  import { withSentryConfig } from '@sentry/nextjs';
  const baseConfig = { /* existing config */ };
  const finalConfig = process.env.NEXT_PUBLIC_SENTRY_DSN
    ? withSentryConfig(baseConfig, { silent: !process.env.CI })
    : baseConfig;
  export default finalConfig;
  ```

- [ ] **Step 2: Verify build still succeeds with empty DSN.**
  ```bash
  cd frontend && npm run build
  ```
  Expected: green; route-payload table emitted.

- [ ] **Step 3: Commit.**
  ```bash
  git add frontend/next.config.ts
  git commit -m "$(cat <<'EOF'
  feat(frontend): gate withSentryConfig on NEXT_PUBLIC_SENTRY_DSN — no-op when unset

  Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
  EOF
  )"
  ```

---

# Phase D — Swagger UI `/api-docs` page

## Task 16: Backend — explicit `/openapi.json` route serving the committed spec

**Files:**
- Edit: `backend/routes/api.php`

- [ ] **Step 1: Add the route (alongside `/health`).**
  ```php
  Route::get('/openapi.json', fn () =>
      response()->file(storage_path('api-docs/openapi.json'), [
          'Content-Type' => 'application/json',
          'Cache-Control' => 'public, max-age=300',
      ])
  )->name('openapi.json');
  ```

- [ ] **Step 2: Verify.**
  ```bash
  curl -s http://localhost:8000/api/v1/openapi.json | jq '.info.title'
  ```
  Expected: `"Fishbook API"`.

- [ ] **Step 3: Commit.**
  ```bash
  git add backend/routes/api.php
  git commit -m "$(cat <<'EOF'
  feat(backend): serve committed openapi.json at /api/v1/openapi.json with 5-min cache

  Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
  EOF
  )"
  ```

---

## Task 17: Frontend — install `swagger-ui-react`; write `/api-docs` page (test-first)

**Files:**
- Edit: `frontend/package.json`
- Create: `frontend/src/app/api-docs/page.tsx`
- Create: `frontend/tests/unit/app/api-docs.test.tsx`

- [ ] **Step 1: Install.**
  ```bash
  cd frontend && npm install --save swagger-ui-react @types/swagger-ui-react
  ```

- [ ] **Step 2: Write failing Vitest smoke.**
  ```tsx
  import { render, screen } from '@testing-library/react';
  import { vi } from 'vitest';
  vi.mock('swagger-ui-react', () => ({ default: () => <div data-testid="swagger-stub" /> }));
  import ApiDocsPage from '@/app/api-docs/page';
  it('renders the lazy swagger surface', async () => {
    render(<ApiDocsPage />);
    expect(await screen.findByTestId('swagger-stub')).toBeInTheDocument();
  });
  ```

- [ ] **Step 3: Write the page.**
  ```tsx
  'use client';
  import dynamic from 'next/dynamic';
  import 'swagger-ui-react/swagger-ui.css';
  const SwaggerUI = dynamic(() => import('swagger-ui-react'), { ssr: false });
  export default function ApiDocsPage() {
    return <SwaggerUI url="/api/proxy/openapi.json" />;
  }
  ```

- [ ] **Step 4: Run — expected GREEN.**
  ```bash
  cd frontend && npm test -- --run tests/unit/app/api-docs.test.tsx
  ```

- [ ] **Step 5: Verify bundle isolation.**
  ```bash
  cd frontend && npm run build
  ```
  Inspect the route-payload table: `/fish` first-load JS should be unchanged from slice 6 (≤ 200 KB); `/api-docs` shows a separate large chunk. **Hard gate**: if `/fish` regresses above 200 KB, revert and investigate.

- [ ] **Step 6: Commit.**
  ```bash
  git add frontend/package.json frontend/package-lock.json \
          frontend/src/app/api-docs/page.tsx \
          frontend/tests/unit/app/api-docs.test.tsx
  git commit -m "$(cat <<'EOF'
  feat(frontend): /api-docs page — swagger-ui-react lazy-loaded via next/dynamic

  Spec URL points at /api/proxy/openapi.json so localhost + prod work without host coupling.
  ssr: false keeps the swagger chunk off other routes; /fish bundle budget preserved.

  Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
  EOF
  )"
  ```

---

## Task 18: Playwright — `/api-docs` smoke spec

**Files:**
- Create: `frontend/tests/e2e/05-api-docs.spec.ts`

- [ ] **Step 1: Write the spec.**
  ```ts
  import { test, expect } from '@playwright/test';
  test('/api-docs renders operations from openapi.json', async ({ page }) => {
    await page.goto('/api-docs');
    // Wait for swagger-ui-react to mount and render at least one operation tag.
    await expect(page.getByText('/fishes', { exact: false }).first()).toBeVisible({ timeout: 20_000 });
  });
  ```

- [ ] **Step 2: Run against the e2e overlay.**
  ```bash
  docker compose -f docker-compose.yml -f docker-compose.e2e.yml up -d --build
  cd frontend && npx playwright test tests/e2e/05-api-docs.spec.ts
  docker compose -f docker-compose.yml -f docker-compose.e2e.yml down -v
  ```
  Expected: green.

- [ ] **Step 3: Commit.**
  ```bash
  git add frontend/tests/e2e/05-api-docs.spec.ts
  git commit -m "$(cat <<'EOF'
  test(e2e): /api-docs renders at least one operation from openapi.json

  Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
  EOF
  )"
  ```

---

# Phase E — `deploy.yml` workflow

## Task 19: Write `.github/workflows/deploy.yml` (do not push tags)

**Files:**
- Create: `.github/workflows/deploy.yml`

- [ ] **Step 1: Write the workflow per design §7.** Trigger on `v*` tags. Steps: checkout → setup-node@20 → install Railway CLI → `railway up --service backend --detach` → curl loop on `https://api.fishbook.neri.ph/api/v1/health` (60s timeout) → `railway run --service backend php artisan migrate --force` → `railway up --service frontend --detach` → curl loop on `https://fishbook.neri.ph/api/health`. Use `${{ secrets.RAILWAY_TOKEN }}` env. Add a header comment documenting required service envvars from SPEC §10 + `APP_ENV=production`.

- [ ] **Step 2: YAML lint locally.**
  ```bash
  npx --yes yaml-lint .github/workflows/deploy.yml
  ```

- [ ] **Step 3: Confirm slice 7 does NOT push a tag.** Document in the workflow header comment.

- [ ] **Step 4: Commit.**
  ```bash
  git add .github/workflows/deploy.yml
  git commit -m "$(cat <<'EOF'
  ci: add deploy.yml — railway up on v* tag push (not exercised in slice 7)

  Backend deploy → migrate → frontend deploy → healthcheck both domains.
  Manual rollback via Railway UI on failure (v1 simplification).

  Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
  EOF
  )"
  ```

---

# Phase F — Loose ends cleanup

## Task 20: Frontend — `BackgroundLayer` swaps `<img>` to `next/image`; sprite `<img>` retained

**Files:**
- Edit: `frontend/src/components/aquarium/BackgroundLayer.tsx`
- Edit: `frontend/next.config.ts` (`images.remotePatterns`)
- Edit: any sprite component using `<img>` — add documentation comment only.

- [ ] **Step 1: Add `images.remotePatterns` for `*.r2.cloudflarestorage.com`, `*.s3.amazonaws.com`, `localhost:9000`.**

- [ ] **Step 2: Edit `BackgroundLayer` to use `<Image fill priority alt="" src={...} />`. Keep the gradient-fallback path unchanged.**

- [ ] **Step 3: Run frontend tests.**
  ```bash
  cd frontend && npm test -- --run
  ```

- [ ] **Step 4: Run lint and verify warning count drops.**
  ```bash
  cd frontend && npm run lint
  ```

- [ ] **Step 5: Commit.**
  ```bash
  git add frontend/src/components/aquarium/BackgroundLayer.tsx frontend/next.config.ts
  git commit -m "$(cat <<'EOF'
  perf(frontend): BackgroundLayer uses next/image for signed-URL backgrounds

  Sprite <img> retained because next/image defeats the currentColor tinting pipeline.

  Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
  EOF
  )"
  ```

---

## Task 21: Frontend — `AddFishDialog` react-compiler ↔ react-hook-form warning; Sanctum unused import; Inter font display swap

**Files:**
- Edit: `frontend/src/components/manage/AddFishDialog.tsx` (or wherever the warning sits)
- Edit: `backend/app/Models/User.php` (or wherever the unused `TransientToken` docblock import is)
- Edit: `frontend/src/app/layout.tsx` (verify Inter `display: 'swap'`)

- [ ] **Step 1: Investigate the react-compiler warning.** If a cheap fix exists (wrap a non-reactive value in `useMemo`, or convert a `register(...)` spread to a stable ref), apply it. Otherwise add `// eslint-disable-next-line react-compiler/react-compiler` directly above the offending line with a one-line comment citing the react-hook-form interop tracking issue.

- [ ] **Step 2: Remove unused `TransientToken` docblock import** from User model or wherever PHPStan/Pint flagged it.

- [ ] **Step 3: Verify `Inter({ subsets: ['latin'], display: 'swap' })` in `layout.tsx`.** Add `display: 'swap'` if absent.

- [ ] **Step 4: Run lint + typecheck + tests.**
  ```bash
  cd frontend && npm run lint && npm run typecheck && npm test -- --run
  docker compose exec backend ./vendor/bin/pint --test
  docker compose exec backend ./vendor/bin/phpstan analyse
  ```
  Expected: green or all remaining warnings carry a justification comment.

- [ ] **Step 5: Commit.**
  ```bash
  git add frontend/src/components/manage/AddFishDialog.tsx \
          frontend/src/app/layout.tsx \
          backend/app/Models/User.php
  git commit -m "$(cat <<'EOF'
  chore: clear slice-6 lint warnings best-effort

  - AddFishDialog: react-compiler interop note (fix or disable+justify)
  - User: drop unused TransientToken docblock import
  - layout: ensure Inter font display: 'swap'

  Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
  EOF
  )"
  ```

---

# Phase G — Final v1 housekeeping

## Task 22: README, CHANGELOG, `make deploy-prod`, `make smoke`

**Files:**
- Edit: `README.md`
- Create: `CHANGELOG.md`
- Edit: `Makefile`

- [ ] **Step 1: README.**
  - Add a "Deployment" section linking `.github/workflows/deploy.yml`; document the tag-trigger pattern.
  - Add v1 status badge.
  - Document `make deploy-prod` and `make smoke`.

- [ ] **Step 2: CHANGELOG.**
  - One section per slice (1–7) with 1–3 bullets each, end-user terms.

- [ ] **Step 3: Makefile.**
  - `deploy-prod`: one-line echo pointing at README §Deployment.
  - `smoke`: a curl chain hitting `/api/v1/health`, `/api/v1/fishes/breeds`, `/api/v1/openapi.json`, `/api/v1/repos/vercel/next.js/aquarium`, with `set -e` so any non-200 fails. Configurable host via `HOST=...` env.

- [ ] **Step 4: Run `make smoke` against the local stack.**
  ```bash
  docker compose up -d
  make smoke
  ```
  Expected: all 200s.

- [ ] **Step 5: Commit.**
  ```bash
  git add README.md CHANGELOG.md Makefile
  git commit -m "$(cat <<'EOF'
  docs: README deployment section, CHANGELOG, make deploy-prod + make smoke

  Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
  EOF
  )"
  ```

---

## Task 23: Full-suite green gate

**Files:** (none — verification only)

- [ ] **Step 1: Backend.**
  ```bash
  docker compose exec backend ./vendor/bin/pest
  docker compose exec backend ./vendor/bin/phpstan analyse
  docker compose exec backend ./vendor/bin/pint --test
  ```

- [ ] **Step 2: Frontend.**
  ```bash
  cd frontend && npm run lint && npm run typecheck && npm test -- --run && npm run build
  ```

- [ ] **Step 3: Playwright against e2e overlay (5 specs now).**
  ```bash
  docker compose -f docker-compose.yml -f docker-compose.e2e.yml up -d --build
  docker compose exec -T backend php artisan migrate --force
  cd frontend && npx playwright test
  docker compose -f docker-compose.yml -f docker-compose.e2e.yml down -v
  ```

- [ ] **Step 4: Manual CSP smoke in browser DevTools.**
  - `docker compose up -d`
  - Open `http://localhost:3000/fish` in Chrome
  - DevTools → Console: no CSP violations (or only the expected report-only warnings)
  - DevTools → Network: response headers show `Content-Security-Policy-Report-Only` on backend XHRs
  - Upload a background; verify the MinIO preview renders without a CSP block

- [ ] **Step 5: No commit.** Verification gate.

---

## Task 24: Tag `v1-rc1`

**Files:** (none — git operation only)

- [ ] **Step 1: Confirm `main` is green and at the head of slice 7's commits.**
  ```bash
  git log --oneline -n 25
  ```

- [ ] **Step 2: Create the tag (do not push automatically — operator decision).**
  ```bash
  git tag -a v1-rc1 -m "Fishbook v1 release candidate 1 — security, observability, deploy.yml ready"
  git tag --list | grep v1-rc1
  ```

- [ ] **Step 3: Document next steps for the operator.**
  - Push the tag: `git push origin v1-rc1` (triggers `deploy.yml` — only do this when Railway is provisioned).
  - Provision Railway envvars (SPEC §10).
  - First real deploy → if green, retag as `v1.0.0` for the public release name.

- [ ] **Step 4: No commit; tag operation only.**

---

## Verification matrix — acceptance criteria → task IDs

| Acceptance criterion (design §10) | Verified by Task |
|---|---|
| 1, 2, 3 — Phase A regen + bare paths + shim removal | 2, 3, 4 |
| 4, 5 — Security headers + URL::forceScheme | 7, 8, 9 |
| 6, 7, 8 — Sentry SDK init + scrubbers + no-op without DSN | 10, 11, 13, 14, 15 |
| 9, 10 — `/api-docs` page + bundle isolation | 16, 17, 18 |
| 11 — `deploy.yml` exists | 19 |
| 12 — Lint cleanup | 20, 21 |
| 13 — README + CHANGELOG | 22 |
| 14 — Full-suite green | 23 |
| 15 — Tag `v1-rc1` | 24 |
| 16 — SPEC §17 items 10 + 12 ticked | Implicit on full slice merge |

---

## Open Questions / Confirmations (carried from design §12)

- CSP `report-uri` to Sentry? **Default: no for v1.** *Confirm.*
- `deploy.yml` runs `php artisan migrate --force` post-deploy? **Default: yes (Task 19 includes it).** *Confirm.*
- Tag name: `v1-rc1` vs `v1.0.0-rc.1` vs `v1.0.0`? **Default: `v1-rc1` (Task 24).** *Confirm.*
- `make smoke` target? **Default: yes (Task 22).** *Confirm.*
- `/api-docs` requires `ENABLE_API_DOCS=true`? **Default: always-on.** *Confirm.*

---

## What's intentionally NOT in this plan

(Mirrors design §13.) No actual `v1-rc1` push or Railway deploy; no Cloudflare/WAF; no privacy policy/ToS; no SRI; no CSP report-uri/report-to; no Sentry source-map upload on deploy; no Lighthouse CI gate; no Percy/Chromatic; no blue-green; no `php-fpm + nginx` (still `artisan serve`); no auth-reset flow.
