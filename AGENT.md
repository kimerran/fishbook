# AGENT.md — Coding Agent Playbook for Fishbook

You are working in the **Fishbook** monorepo: a virtual-aquarium web app with a Next.js frontend, a Laravel backend, Postgres, S3-compatible storage, and Fal AI + GitHub integrations. Read `SPEC.md` for *what* to build. This file is *how* to build it.

**Golden rule:** if anything here conflicts with `SPEC.md`, follow `SPEC.md` for behavior and follow this file for engineering practice. If they conflict on something deeper, stop and ask.

---

## 1. Stack & Pinned Versions

Always use the latest stable as of project start. If you upgrade something, upgrade in a dedicated PR with the changelog linked.

### Backend (`backend/`)
- **PHP 8.3.x** (8.4 is fine if all deps support it; verify first) — Laravel 13's minimum is 8.3.
- **Laravel 13.x** (released March 2026) — uses the streamlined application skeleton (no `app/Http/Kernel.php` — middleware lives in `bootstrap/app.php`).
- **Composer 2.7+**
- **Eloquent ORM** — *the only* DB access layer. No raw queries except in migrations or for documented performance hotspots.
- **Laravel Sanctum** — token auth.
- **Laravel Socialite** — Google OAuth.
- **`darkaonline/l5-swagger`** + **`zircote/swagger-php`** — OpenAPI annotations & spec generation.
- **`intervention/image` v3** — image processing (resize, EXIF strip, WebP re-encode).
- **`league/flysystem-aws-s3-v3`** — S3 driver.
- **Pest** (preferred) or **PHPUnit 11** for tests.
- **Larastan** (PHPStan for Laravel) level 6.
- **Laravel Pint** for code style.

### Frontend (`frontend/`)
- **Node 20 LTS** (use the version in `.nvmrc`).
- **Next.js 16.x** (16.2 LTS as of project start — App Router, React Server Components where it makes sense; the aquarium canvas is a `'use client'` boundary).
- **React 19.x** (19.2.x).
- **TypeScript 5.x** with `"strict": true`, `"noUncheckedIndexedAccess": true`.
- **Tailwind CSS 4.x**.
- **Zustand** for client state (aquarium store).
- **TanStack Query v5** for server-state caching of API calls.
- **`react-hook-form` + `zod`** for forms & validation.
- **Vitest** + **@testing-library/react** + **happy-dom** for unit/component tests.
- **Playwright** for E2E.
- **ESLint 9** (flat config) + **Prettier**.
- **`swagger-ui-react`** for `/api-docs`.

### Tooling
- **Docker Compose v2** for local services.
- **GitHub Actions** for CI.
- **Railway CLI** for deployment (CI-driven).
- **openapi-generator-cli** for client generation; **generated client is committed**.

When adding a dependency:
1. Check it's actively maintained (commit in last 12 months, no critical CVEs).
2. Prefer first-party over a thin wrapper.
3. Pin to a caret range (`^x.y.z`) and rely on Renovate/Dependabot for updates.

---

## 2. Repository Layout

```
fishbook/
├── SPEC.md                # source of truth for what to build
├── AGENT.md               # this file
├── README.md              # quickstart, links to SPEC and AGENT
├── docker-compose.yml
├── Makefile
├── .github/
│   ├── workflows/
│   │   ├── backend.yml
│   │   ├── frontend.yml
│   │   ├── e2e.yml
│   │   └── deploy.yml
│   ├── dependabot.yml
│   └── CODEOWNERS
├── backend/
│   ├── app/
│   │   ├── Http/
│   │   │   ├── Controllers/Api/V1/
│   │   │   ├── Requests/
│   │   │   ├── Resources/
│   │   │   └── Middleware/
│   │   ├── Models/
│   │   ├── Policies/
│   │   ├── Services/
│   │   │   ├── FalAi/
│   │   │   ├── Github/
│   │   │   ├── Aquarium/        # RepoAquariumGenerator etc.
│   │   │   └── Backgrounds/     # BackgroundImageProcessor
│   │   └── Console/Commands/
│   ├── config/
│   │   └── fish_breeds.php
│   ├── database/
│   │   ├── migrations/
│   │   ├── seeders/
│   │   └── factories/
│   ├── routes/api.php
│   ├── tests/
│   │   ├── Feature/
│   │   └── Unit/
│   ├── storage/api-docs/openapi.json   # committed
│   ├── phpstan.neon
│   ├── pint.json
│   └── composer.json
└── frontend/
    ├── src/
    │   ├── app/                  # App Router
    │   │   ├── (public)/         # landing, login, register
    │   │   ├── fish/             # authed aquarium
    │   │   ├── [username]/[repo]/
    │   │   ├── api-docs/
    │   │   └── api/              # Next.js route handlers (proxy + cookie ops)
    │   ├── components/
    │   │   ├── aquarium/         # AquariumCanvas, Fish class, FoodPellet, BackgroundLayer
    │   │   ├── manage/           # FishManagerModal, AddFishDialog
    │   │   └── ui/               # button, input, modal (kept minimal)
    │   ├── stores/               # zustand stores
    │   ├── lib/
    │   │   ├── api-client/       # generated from OpenAPI — DO NOT hand-edit
    │   │   ├── api.ts            # thin wrapper around generated client
    │   │   ├── auth.ts           # cookie helpers (server-side only)
    │   │   └── seeded-random.ts  # for any client-side determinism
    │   ├── hooks/
    │   └── styles/
    ├── tests/
    │   ├── unit/
    │   └── e2e/
    ├── public/sprites/fish/
    ├── tailwind.config.ts
    ├── tsconfig.json
    ├── eslint.config.mjs
    └── package.json
```

---

## 3. Coding Conventions

### General
- **Small, focused commits.** Conventional Commits (`feat:`, `fix:`, `chore:`, `refactor:`, `test:`, `docs:`).
- **Self-documenting code over comments.** Comments explain *why*, never *what*.
- **No dead code.** Remove, don't comment out.
- **No magic numbers.** Either name them or pull from config.

### Backend (Laravel)
- **Controllers are thin.** Validate via `FormRequest`, delegate to a Service (in `app/Services/...`), return an `ApiResource`. A controller method should be < 15 lines.
- **Services are testable.** Inject dependencies via the constructor. No facades inside services — inject contracts (`Filesystem`, `Cache`, `Http`) so you can swap them in tests.
- **Models stay slim.** Relationships, casts, scopes. No business logic. No HTTP. No I/O beyond Eloquent.
- **Migrations are reversible.** Always implement `down()`.
- **Use `DB::transaction(fn() => ...)` for any multi-statement write.**
- **API Resources for every response shape.** Don't return raw models or arrays.
- **Use `Str::ulid()`** for any externally visible non-PK identifier (e.g. storage keys).
- **Eager-load relationships** to avoid N+1; CI runs `BarryvdH\Debugbar` queries assertion in tests for the index endpoints.
- **Form requests** name policies/permissions; use `$this->user()->can(...)` in `authorize()`.
- **Route model binding everywhere.** Never `Fish::find($id)` in a controller — use `Route::apiResource` + implicit binding + policy.
- **Swagger annotations live on the controller method**, kept in sync with the FormRequest and Resource. CI fails if `storage/api-docs/openapi.json` is stale.

### Frontend (Next.js)
- **Server components by default.** Add `'use client'` only at the leaves that need it (the canvas, the modals with local state).
- **No `any`.** If you need an escape hatch, use `unknown` and narrow.
- **No fetch-in-component.** All server calls go through TanStack Query hooks (`useFishesQuery`, `useCreateFishMutation`, …) that call the typed API client.
- **Auth token never touches the browser JS.** Token is stored in an HttpOnly cookie set by a Next.js route handler. All browser-side API calls go to `/api/proxy/...` Next.js route handlers, which inject the token server-side and forward to the backend. (See `frontend/src/app/api/proxy/[...path]/route.ts`.)
- **No `localStorage`/`sessionStorage` for sensitive data.** Aquarium-visual state (camera, paused) can live in `localStorage`.
- **Tailwind, not custom CSS modules.** A shared `@layer components` block in `globals.css` is fine for repeated patterns.
- **One default export per component file.** Co-locate component-specific types and tests.
- **Accessibility:** every interactive element is keyboard-reachable, has a visible focus ring, and ARIA-labelled. The canvas itself is decorative but the "Manage Fishes" UI must be a real `<dialog>` or `role="dialog"` with focus trap.

### Naming
- Backend: PSR-12 + Laravel conventions (`FishController`, `StoreFishRequest`, `FishResource`, `fishes` table).
- Frontend: `PascalCase` for components and types, `camelCase` for everything else, `SCREAMING_SNAKE_CASE` for constants.

---

## 4. Security Practices (Non-Negotiable)

These are gates, not suggestions. CI will fail closed where automatable.

### Input
- **All inputs validated server-side**, even if the frontend also validates. FormRequests + Zod on the frontend are *both* required.
- **`owner`/`repo` URL params** match `^[A-Za-z0-9._-]{1,100}$` before forwarding to GitHub. Reject otherwise.
- **LLM prompts** capped at 500 chars and pass through a denylist before forwarding to Fal AI.
- **File uploads:** MIME-sniffed (not just extension-checked) via Intervention Image; dimension and size limits enforced server-side; EXIF stripped; re-encoded.

### Auth
- **bcrypt cost ≥ 12.** Configured in `config/hashing.php`.
- **Password strength:** zxcvbn score ≥ 2, length ≥ 10.
- **Generic error messages on login failure** ("Invalid credentials") — never disclose whether username exists.
- **Rate limits:** 5/min on login & register per IP **and** per username. 60/min general API. 10/hr per user on AI generation. 200/day global on AI generation.
- **Tokens:** Sanctum personal access tokens, never embedded in URLs, only in `Authorization` headers. Rotated on password change.
- **No JWTs.** Sanctum tokens only — simpler, revocable, and sufficient.

### Output
- **All user-provided strings escaped on render.** React handles this for text, but be deliberate with `dangerouslySetInnerHTML` — *never* use it on user content.
- **API responses go through API Resources.** Never `return $model->toArray()` directly — fields would leak.

### Storage
- **No public buckets.** Signed URLs (1-hour TTL) only.
- **No predictable storage paths.** Use ULIDs.
- **Soft-delete + 7-day S3 retention** for undo.

### Infra
- **HTTPS only.** `URL::forceScheme('https')` in production. `Strict-Transport-Security` header set.
- **Security headers** via middleware: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, `Content-Security-Policy` (see SPEC §16), `Permissions-Policy: camera=(), microphone=(), geolocation=()`.
- **CORS:** allow only `FRONTEND_URL` origin. `supports_credentials: true`.
- **Secrets:** never in repo. `.env` in `.gitignore`. CI uses GitHub Actions secrets. Railway env for production.
- **Dependency security:** Dependabot for both directories. `composer audit` and `npm audit --omit=dev` in CI. High/critical findings fail the build.
- **Admin seeder must fail** in production if `ADMIN_SEED_PASSWORD` is empty, shorter than 12 chars, or in a small banlist (`password`, `admin`, `changeme`, `12345678`).

### Logging
- **Scrub** `password`, `password_confirmation`, `token`, `Authorization`, `api_key`, `FAL_API_KEY` from any logged context. Configure via Monolog processors.
- **No PII in logs** beyond user id + email (and email only at WARN+).
- **Sentry** with `beforeSend` that drops requests containing the sensitive headers.

---

## 5. Testing Standards

### Required tests for every PR
- New endpoint → at least 1 feature test for happy path, 1 for auth failure, 1 for validation failure, 1 for authorization (wrong user).
- New service → unit tests covering each public method, including error paths.
- New React component with interaction → component test exercising the interaction.
- Bug fix → regression test that fails on the bug and passes after.

### Determinism
- Seed Faker / `fake()->seed(1234)` in tests that rely on random data.
- `RepoAquariumGenerator` is *contractually deterministic*: there's a snapshot test pinning the output for `vercel/next.js` stats.

### Coverage gates (CI enforced)
- Backend: ≥ 80% on `app/Services/` and `app/Http/Controllers/`.
- Frontend: ≥ 70% statement coverage; `Fish` class steering math and `useAquariumStore` are 100%.

### Speed
- Backend tests use `:memory:` SQLite **only if** features depended on PG-specific types are mocked; otherwise spin up a real Postgres in CI (preferred — the schema uses `citext`, `jsonb`, partial indexes).
- Frontend tests use `happy-dom`, not full `jsdom`, for speed.

### E2E
- Playwright covers the canonical user journey (see SPEC §17 acceptance criteria, items 1–5).
- E2E runs against `docker compose up` in CI on PRs touching either `frontend/` or `backend/`.

---

## 6. Performance Budgets

- **Aquarium canvas:** maintain 60 fps with up to **100 fish** and **20 food pellets** on a mid-range laptop. Use a single `requestAnimationFrame` loop, render all entities in one pass, avoid per-frame allocations (reuse vectors). No React re-renders inside the animation loop — the canvas is imperative.
- **API p95 latency:** < 200 ms for list endpoints with up to 1000 fish per user (paginated).
- **GitHub aquarium endpoint:** < 500 ms cached, < 2 s cold (GitHub round-trip). Always cache.
- **Background generation:** poll/wait up to 60 s; surface progress UI on the frontend.
- **Frontend bundle:** route-level JS payload < 200 KB gzipped for `/fish`. Lazy-load `swagger-ui-react` on `/api-docs`.

---

## 7. Workflow

### Local setup (one-time)
```bash
git clone …
cp backend/.env.example backend/.env
cp frontend/.env.example frontend/.env
docker compose up -d
docker compose exec backend php artisan key:generate
docker compose exec backend php artisan migrate --seed
docker compose exec backend php artisan l5-swagger:generate
cd frontend && npm ci && npx openapi-generator-cli generate -i ../backend/storage/api-docs/openapi.json -g typescript-fetch -o src/lib/api-client
```

### Daily loop
```bash
make up         # bring services up
make test       # run all tests
make lint       # pint + phpstan + eslint + tsc
```

### Before every commit
1. Tests green.
2. Lint clean.
3. If you changed any backend route or request/resource, regenerate the OpenAPI spec and the frontend client. Commit both.
4. If you added a dependency, run `composer audit` / `npm audit` and document why it's needed in the PR description.

### Pull requests
- Small. < 400 LOC diff where possible.
- Description includes: *what*, *why*, *how tested*, *screenshots/gif if UI*, *security considerations* (even if "none").
- Link the SPEC.md section(s) the PR implements.
- Self-review the diff before requesting review.

---

## 8. Things That Will Get Flagged in Review

These are the most common mistakes for this codebase. Don't make them.

- 🚫 Logic in a controller. Move it to a service.
- 🚫 `$request->all()` passed to `Model::create()`. Use `$request->validated()` *and* a resource/DTO.
- 🚫 N+1 query. Eager-load.
- 🚫 Raw `DB::statement` with string concat. Bindings or Eloquent.
- 🚫 New endpoint without OpenAPI annotations.
- 🚫 Returning a model from a controller (use a Resource).
- 🚫 `any` in TypeScript.
- 🚫 `fetch()` inside a component (use TanStack Query + the generated client).
- 🚫 Auth token read from JS-accessible storage.
- 🚫 `dangerouslySetInnerHTML` with anything user-derived.
- 🚫 `Math.random()` for anything that needs to be reproducible. Use `seeded-random.ts`.
- 🚫 React state updates inside the `requestAnimationFrame` loop.
- 🚫 New env var added without `.env.example` entry and a SPEC.md mention.
- 🚫 `composer require`/`npm install` without checking maintenance status and CVE history.
- 🚫 Committing the generated OpenAPI client without first regenerating against the current spec.
- 🚫 Disabling a test to "fix later". Either fix it or delete it.
- 🚫 Logging anything that could contain a secret or full request body.

---

## 9. When You're Stuck

- **Ambiguous behavior?** Re-read `SPEC.md`. If it's still ambiguous, raise it in the PR description as `Open question:` and pick the most conservative interpretation.
- **Library choice unclear?** Default to what Laravel/Next.js ships with. Add a third-party only when there's a clear gap.
- **Performance regression?** Profile first (`Telescope` for backend, React Profiler for frontend). Don't optimize speculatively.
- **Security uncertain?** Treat the input as hostile, the output as audited, and the network as a public park. When still in doubt, ask in the PR or open an issue tagged `security`.

---

## 10. Quick Reference

```
# Backend
php artisan migrate                    # apply migrations
php artisan migrate:fresh --seed       # nuke & reseed (DEV ONLY)
php artisan test                       # run all tests
php artisan test --filter FishTest     # one suite
php artisan l5-swagger:generate        # regenerate OpenAPI
vendor/bin/pint                        # auto-fix style
vendor/bin/phpstan analyse             # static analysis
php artisan tinker                     # REPL

# Frontend
npm run dev                            # dev server on :3000
npm run build && npm run start         # prod build locally
npm run test                           # vitest
npm run test:e2e                       # playwright
npm run lint                           # eslint
npm run typecheck                      # tsc --noEmit
npx openapi-generator-cli generate -i ../backend/storage/api-docs/openapi.json -g typescript-fetch -o src/lib/api-client

# Docker
docker compose up -d
docker compose logs -f backend
docker compose exec backend bash
docker compose down -v                 # nuke volumes too (DEV ONLY)
```

---

**Final reminder:** every change must satisfy three things — *it works* (tests prove it), *it's safe* (security checklist), and *it's maintainable* (someone unfamiliar can read it). If you can't tick all three, the change isn't done.
