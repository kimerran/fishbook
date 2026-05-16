# Fishbook — Slice 1: Foundations (Design)

**Date:** 2026-05-16
**Slice:** 1 of 7 (Foundations)
**Status:** Approved — ready for implementation plan

This document specifies the first ship-slice for Fishbook. It establishes the monorepo, brand system, and CI pipeline that every later slice will build on. It does **not** implement any product features.

Behaviour is governed by [`SPEC.md`](../../../SPEC.md), engineering practice by [`AGENT.md`](../../../AGENT.md), visual language by [`BRAND.md`](../../../BRAND.md). When this file is ambiguous, those win.

---

## 1. Context

The Fishbook repo currently contains only `SPEC.md`, `AGENT.md`, and `BRAND.md` — no code, no commits. The spec describes seven mostly-independent subsystems (auth, fish CRUD, canvas/animation, background upload, AI background generation, GitHub repo aquarium, polish & launch). Shipping all of them in one design produces an unreadable plan; instead, the work is decomposed into seven sequential slices.

**Slice 1 is foundations only.** Its job is to make every subsequent slice cheap to start: a working monorepo, a working brand, a working CI pipeline, and exactly one trivial endpoint proving the OpenAPI generator round-trips.

Deploy to Railway is **deferred** until external accounts (GitHub org, Railway, R2, Fal AI, Sentry, OAuth) are provisioned. A later "Slice 1.5 — first deploy" will pick up that work; slices 2–3 will be validated locally until then.

---

## 2. Scope

### In

- Monorepo with `frontend/` (Next.js 15 + React 19 + TypeScript strict + Tailwind 4) and `backend/` (Laravel 12 + PHP 8.3 + Pest) sibling directories.
- `docker-compose.yml` at repo root: `db` (Postgres 17), `redis`, `minio`, `minio-init`, `backend`, `frontend` services with healthchecks.
- `Makefile` with `up`, `down`, `restart`, `migrate`, `seed`, `test`, `lint`, `fmt`, `swagger`, `api-client` targets.
- `.env.example` files for both services, verbatim from `SPEC.md` §10.
- **Backend:** Laravel skeleton, Sanctum installed (no auth routes yet), `darkaonline/l5-swagger` installed, single endpoint `GET /api/v1/health` returning `{ok, version}`, OpenAPI annotations via PHP 8 attributes, `storage/api-docs/openapi.json` generated and committed.
- **Frontend:** Next.js App Router. Brand tokens from `BRAND.md` §2–§9 wired into Tailwind 4's CSS-first config (`@theme` directives in `globals.css`). Inter loaded via `next/font/google` with weights `300/400/500/700`. Material Symbols Outlined loaded via Google Fonts stylesheet `<link>` in `RootLayout`. `@layer components` block exposes `.glass-xs/sm/md/lg/overlay` and `.label-caps` utilities. Landing page at `/` (hero, tagline, two disabled glass buttons "Sign in" / "Create account", `prefers-reduced-motion` honored). Next.js route `GET /api/health` returning `{ok: true}`.
- **OpenAPI client:** `frontend/src/lib/api-client/` generated from the backend spec via `openapi-generator-cli generate ... -g typescript-fetch`. Committed to git. `npm run generate:api` wraps the command.
- **CI:** `.github/workflows/backend.yml` and `frontend.yml`. Backend runs Pint, Larastan (level 6), Pest, and a "swagger is in sync" check. Frontend runs ESLint, `tsc --noEmit`, Vitest, `next build`, and an "api-client is in sync" check.
- **Tests:** one Pest feature test (`GET /api/v1/health` → 200 + shape); one Vitest test (landing renders the brand tagline). Both prove the test runners work.
- `.github/dependabot.yml` covering composer, npm, github-actions, and the two Dockerfiles, grouped by ecosystem, weekly.
- `.github/CODEOWNERS` stub.
- `README.md` quickstart pointing at `SPEC.md`, `BRAND.md`, `AGENT.md`.

### Out (deferred to later slices)

- All deploy infrastructure (no `deploy.yml`, no Railway config, no production env handling).
- E2E / Playwright (first appears in slice 3, once a real flow exists).
- Sentry initialization code (env stubs only).
- Coverage gates (reinstated in slice 2 when real services exist).
- Any auth, fish, background, or repo-aquarium code.
- Dark-mode classes beyond what `BRAND.md` §12 says to leave in place.
- Pre-creating empty service/component subdirectories — they appear with the slices that need them.
- `swagger-ui-react` and the `/api-docs` page — deferred to a polish slice.
- `react-hook-form`, `zod`, `@tanstack/react-query`, `zustand` — added in slice 2 when forms and queries need them.

---

## 3. Approach Decisions

These were the load-bearing judgment calls made during brainstorming. Recording them so future slices don't re-litigate.

1. **Local-only foundations.** Deploy to Railway is deferred (Slice 1.5) until external accounts exist. Stub all third-party env vars.
2. **Approach A — skeleton only, no ghost structure.** Folders like `app/Services/Aquarium/` and `src/components/aquarium/` are created by the slice that introduces them, not pre-seeded with `.gitkeep`.
3. **Landing page in slice 1.** Not strictly required by the SPEC for foundations, but it stress-tests the brand system (Inter weight 300, sage palette, glass tiers, decorative blobs) on a real surface before three more slices are built on top.
4. **Tailwind 4 CSS-first config.** `@theme` directives in `globals.css` are the v4 default and map cleanly to BRAND tokens. `tailwind.config.ts` still exists for content paths and plugins only — not for tokens.
5. **Material Symbols via Google Fonts stylesheet link**, not the npm package — simpler, no bundle bloat, matches `BRAND.md` §7 with no behaviour difference.
6. **Two-Dockerfile setup** (one per service), both multi-stage. Implied by `docker-compose.yml`'s `build:` directives; made explicit here.
7. **`make migrate` is a separate step**, not folded into `make up`. Migration failures are more legible when run on demand; one-command bring-up is a future ergonomic improvement, not a slice-1 requirement.
8. **Coverage gate suspended for slice 1.** SPEC §13 specifies `--coverage --min=80`; with one trivial endpoint this is meaningless. CI still runs tests, just without the threshold. Threshold returns in slice 2.

---

## 4. Repository Structure

```
fishbook/
├── SPEC.md
├── AGENT.md
├── BRAND.md
├── README.md
├── Makefile
├── docker-compose.yml
├── .gitignore
├── .github/
│   ├── workflows/
│   │   ├── backend.yml
│   │   └── frontend.yml
│   ├── dependabot.yml
│   └── CODEOWNERS
├── docs/
│   └── superpowers/specs/
│       └── 2026-05-16-foundations-design.md
├── backend/
│   ├── app/
│   │   ├── Http/
│   │   │   └── Controllers/Api/V1/HealthController.php
│   │   └── Providers/                       # default Laravel set
│   ├── bootstrap/app.php                    # L12 streamlined middleware config
│   ├── config/                              # default L12 set + l5-swagger.php
│   ├── database/
│   │   ├── migrations/                      # default users / sanctum / jobs / cache only
│   │   └── seeders/DatabaseSeeder.php       # empty
│   ├── routes/
│   │   ├── api.php                          # health route only
│   │   └── web.php                          # default
│   ├── storage/api-docs/openapi.json        # generated, committed
│   ├── tests/
│   │   ├── Feature/HealthTest.php
│   │   └── Pest.php
│   ├── .env.example
│   ├── composer.json
│   ├── phpstan.neon
│   ├── pint.json
│   └── Dockerfile
└── frontend/
    ├── src/
    │   ├── app/
    │   │   ├── layout.tsx
    │   │   ├── page.tsx                     # landing
    │   │   ├── globals.css                  # @theme + @layer components
    │   │   └── api/health/route.ts
    │   └── lib/api-client/                  # generated; committed
    ├── public/                              # empty (sprites land in slice 3)
    ├── tests/unit/landing.test.tsx
    ├── .env.example
    ├── .nvmrc
    ├── eslint.config.mjs
    ├── next.config.ts
    ├── package.json
    ├── postcss.config.mjs
    ├── tailwind.config.ts
    ├── tsconfig.json
    ├── vitest.config.ts
    └── Dockerfile
```

Folders not in this tree are **not created in slice 1**. Slice 2+ adds them.

---

## 5. Backend Skeleton

### Bootstrap commands

```bash
composer create-project laravel/laravel backend "12.*"
cd backend
composer require laravel/sanctum darkaonline/l5-swagger
composer require --dev pestphp/pest pestphp/pest-plugin-laravel larastan/larastan
php artisan install:api
./vendor/bin/pest --init
php artisan vendor:publish --provider="L5Swagger\L5SwaggerServiceProvider"
```

### Configuration changes from Laravel defaults

| File | Change |
|---|---|
| `config/database.php` | Default connection `pgsql`. Parse `DATABASE_URL` when present (Railway-ready, harmless locally). |
| `config/sanctum.php` | `expiration = null`. |
| `config/cors.php` | `allowed_origins = [env('FRONTEND_URL', 'http://localhost:3000')]`, `supports_credentials = true`. |
| `config/l5-swagger.php` | annotation path `app/Http/Controllers`; output `storage/api-docs/openapi.json`; OpenAPI 3.1.0; title "Fishbook API", version "1.0.0". |
| `config/hashing.php` | bcrypt rounds = 12 (per `AGENT.md` §4). |
| `bootstrap/app.php` | Register API routes at `routes/api.php` with prefix `api/v1` and `throttle:api` (60/min). |
| `phpstan.neon` | larastan extension, level 6, paths `app/`. |
| `pint.json` | preset `laravel`. |

### The health endpoint

`app/Http/Controllers/Api/V1/HealthController.php`:

```php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use OpenApi\Attributes as OA;

#[OA\Info(version: "1.0.0", title: "Fishbook API")]
#[OA\Server(url: "/api/v1")]
class HealthController extends Controller
{
    #[OA\Get(
        path: "/health",
        summary: "Service liveness probe",
        tags: ["meta"],
        responses: [
            new OA\Response(response: 200, description: "OK",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "ok", type: "boolean", example: true),
                        new OA\Property(property: "version", type: "string", example: "1.0.0"),
                    ]
                )
            )
        ]
    )]
    public function __invoke(): array
    {
        return ['ok' => true, 'version' => config('app.version', '0.0.0')];
    }
}
```

Route in `routes/api.php`:

```php
Route::get('/health', App\Http\Controllers\Api\V1\HealthController::class);
```

Mounted under `/api/v1` by `bootstrap/app.php` → final URL `GET /api/v1/health`.

### Test

`tests/Feature/HealthTest.php`:

```php
it('returns ok on the health endpoint', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJson(['ok' => true])
        ->assertJsonStructure(['ok', 'version']);
});
```

### Dockerfile

PHP 8.3-cli base, installs `pdo_pgsql`, `redis`, `gd` (needed by Intervention Image in slice 4), `intl`, `mbstring`. Multi-stage: `deps` (composer install) → `app` (copy + serve). The dev compose target uses `composer install` (with dev deps) and runs `php artisan serve --host=0.0.0.0 --port=8000`.

---

## 6. Frontend Skeleton

### Bootstrap commands

```bash
npx create-next-app@latest frontend \
  --typescript --tailwind --app --eslint --src-dir --import-alias "@/*" --no-turbopack
cd frontend
npm i -D vitest @vitejs/plugin-react @testing-library/react @testing-library/dom \
        happy-dom @vitest/coverage-v8 @openapitools/openapi-generator-cli prettier
```

### `src/app/globals.css` (skeleton)

```css
@import "tailwindcss";

@theme {
  /* Brand palette — BRAND.md §2 (full token set wired here) */
  --color-primary: #52634e;
  --color-on-primary: #ffffff;
  --color-primary-container: #a8bba2;
  --color-on-primary-container: #3b4b38;
  --color-primary-fixed: #d5e8cd;
  --color-primary-fixed-dim: #b9ccb2;
  --color-on-primary-fixed: #101f0f;
  --color-on-primary-fixed-variant: #3a4b38;
  --color-inverse-primary: #b9ccb2;
  --color-surface-tint: #52634e;

  --color-secondary: #50616a;
  --color-on-secondary: #ffffff;
  --color-secondary-container: #d3e5f0;
  --color-on-secondary-container: #566770;
  --color-secondary-fixed: #d3e5f0;
  --color-secondary-fixed-dim: #b7c9d3;
  --color-on-secondary-fixed: #0c1e25;
  --color-on-secondary-fixed-variant: #384951;

  --color-tertiary: #59605d;
  --color-on-tertiary: #ffffff;
  --color-tertiary-container: #b1b7b3;
  --color-on-tertiary-container: #424846;
  --color-tertiary-fixed: #dee4e0;
  --color-tertiary-fixed-dim: #c2c8c4;
  --color-on-tertiary-fixed: #171d1b;
  --color-on-tertiary-fixed-variant: #424845;

  --color-background: #f8fafb;
  --color-surface: #f8fafb;
  --color-surface-bright: #f8fafb;
  --color-surface-dim: #d8dadb;
  --color-surface-container-lowest: #ffffff;
  --color-surface-container-low: #f2f4f5;
  --color-surface-container: #eceeef;
  --color-surface-container-high: #e6e8e9;
  --color-surface-container-highest: #e1e3e4;
  --color-surface-variant: #e1e3e4;
  --color-on-background: #191c1d;
  --color-on-surface: #191c1d;
  --color-on-surface-variant: #444841;
  --color-outline: #747871;
  --color-outline-variant: #c4c8bf;
  --color-inverse-surface: #2e3132;
  --color-inverse-on-surface: #eff1f2;

  --color-error: #ba1a1a;
  --color-on-error: #ffffff;
  --color-error-container: #ffdad6;
  --color-on-error-container: #93000a;

  /* Typography — BRAND.md §3 */
  --font-sans: "Inter", system-ui, sans-serif;
  --text-headline-lg: 32px;
  --text-headline-md: 20px;
  --text-body-lg: 16px;
  --text-body-md: 14px;
  --text-label-caps: 12px;

  /* Radii — BRAND.md §6 */
  --radius-lg: 8px;
  --radius-xl: 12px;

  /* Spacing rhythm — BRAND.md §4.1 */
  --spacing-unit: 8px;
  --spacing-gutter: 24px;
  --spacing-margin-mobile: 16px;
  --spacing-margin-desktop: 48px;
}

@layer base {
  body { @apply bg-background text-on-surface font-sans antialiased; }
}

@layer components {
  .glass-xs { @apply bg-white/10 backdrop-blur-sm; }
  .glass-sm { @apply bg-white/20 backdrop-blur-md border border-white/20; }
  .glass-md { @apply bg-white/40 backdrop-blur-md border border-white/20
                     shadow-[0_8px_32px_rgba(0,0,0,0.04)]; }
  .glass-lg { @apply bg-white/50 backdrop-blur-xl border border-white/20
                     shadow-[0_8px_32px_rgba(0,0,0,0.06)]; }
  .glass-overlay { @apply bg-gradient-to-t from-black/60 to-transparent
                          backdrop-blur-[2px]; }
  .label-caps {
    @apply uppercase tracking-[0.1em] text-[12px] font-medium leading-none;
  }
}

@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation: none !important;
    transition: none !important;
  }
}
```

### `src/app/layout.tsx`

```tsx
import { Inter } from "next/font/google";
import "./globals.css";

const inter = Inter({
  subsets: ["latin"],
  weight: ["300", "400", "500", "700"],
  variable: "--font-inter",
});

export const metadata = {
  title: "Fishbook — Your Zen Sanctuary, Powered by Code",
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en" className={inter.variable}>
      <head>
        <link
          rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0"
        />
      </head>
      <body>{children}</body>
    </html>
  );
}
```

### `src/app/page.tsx` — landing

```tsx
export default function Landing() {
  return (
    <main className="relative min-h-screen overflow-hidden
                     bg-gradient-radial from-[#eef3f6] to-[#f8fafb]">
      <div aria-hidden className="absolute -top-32 -right-24 w-96 h-96
                                  bg-primary-container/20 rounded-full
                                  blur-3xl mix-blend-multiply" />
      <div aria-hidden className="absolute bottom-0 left-12 w-80 h-80
                                  bg-secondary-container/30 rounded-full
                                  blur-2xl mix-blend-multiply" />

      <section className="relative mx-auto max-w-[1200px]
                          px-margin-mobile md:px-margin-desktop
                          flex flex-col items-center justify-center min-h-screen
                          text-center gap-6">
        <p className="label-caps text-on-surface-variant">Fishbook</p>
        <h1 className="text-[24px] md:text-[32px] font-light leading-[1.2]
                       tracking-[0.02em] text-on-surface max-w-[24ch]">
          Your Zen Sanctuary, Powered by Code.
        </h1>
        <p className="text-body-lg font-light leading-[1.6] text-on-surface-variant
                      max-w-[48ch]">
          A virtual aquarium for the curious. Curate a school of fish, shape the
          atmosphere, and let your favorite repository become its own tide pool.
        </p>
        <div className="flex gap-4 mt-4">
          <button disabled aria-disabled
                  className="glass-md rounded-full px-6 py-3 label-caps
                             text-on-surface opacity-50 cursor-not-allowed">
            Sign in
          </button>
          <button disabled aria-disabled
                  className="glass-sm rounded-full px-6 py-3 label-caps
                             text-on-surface-variant opacity-50 cursor-not-allowed">
            Create account
          </button>
        </div>
        <p className="label-caps text-on-surface-variant/60 mt-8">Coming soon</p>
      </section>
    </main>
  );
}
```

### `src/app/api/health/route.ts`

```ts
export const dynamic = "force-static";
export async function GET() {
  return Response.json({ ok: true });
}
```

### Vitest config

`vitest.config.ts`: `environment: 'happy-dom'`, JSX via `@vitejs/plugin-react`, alias `@` → `./src`.

### Test

`tests/unit/landing.test.tsx`:

```ts
import { render, screen } from "@testing-library/react";
import { expect, test } from "vitest";
import Landing from "@/app/page";

test("renders the brand tagline", () => {
  render(<Landing />);
  expect(screen.getByRole("heading", { level: 1 }))
    .toHaveTextContent(/Your Zen Sanctuary, Powered by Code/i);
});
```

### API client generation

`package.json` script:

```
"generate:api": "openapi-generator-cli generate -i ../backend/storage/api-docs/openapi.json -g typescript-fetch -o src/lib/api-client"
```

Generated client is committed. CI runs `npm run generate:api` and `git diff --exit-code src/lib/api-client` — fails on drift.

### Dockerfile

Node 20-alpine base. Multi-stage: `deps` (`npm ci`) → `builder` (`npm run build`) → `runner` (`npm start`). Dev compose target stays at `npm run dev`.

---

## 7. Docker, Makefile, CI

### `docker-compose.yml`

Verbatim from `SPEC.md` §11. The backend service uses `command: php artisan serve --host=0.0.0.0 --port=8000` and relies on `make migrate` for explicit migration runs (deliberate: failures more legible when on demand).

### `Makefile`

```make
.PHONY: up down restart migrate seed test lint fmt swagger api-client

up:
	docker compose up -d
	@echo "Backend:  http://localhost:8000"
	@echo "Frontend: http://localhost:3000"
	@echo "MinIO:    http://localhost:9001 (minioadmin/minioadmin)"

down:
	docker compose down

restart: down up

migrate:
	docker compose exec backend php artisan migrate --force

seed:
	docker compose exec backend php artisan db:seed --force

test:
	docker compose exec backend ./vendor/bin/pest
	docker compose exec frontend npm test -- --run

lint:
	docker compose exec backend ./vendor/bin/pint --test
	docker compose exec backend ./vendor/bin/phpstan analyse
	docker compose exec frontend npm run lint
	docker compose exec frontend npm run typecheck

fmt:
	docker compose exec backend ./vendor/bin/pint
	docker compose exec frontend npx prettier --write .

swagger:
	docker compose exec backend php artisan l5-swagger:generate

api-client:
	docker compose exec frontend npm run generate:api
```

### `.github/workflows/backend.yml`

```yaml
name: backend
on:
  pull_request: { paths: ["backend/**", ".github/workflows/backend.yml"] }
  push: { branches: [main], paths: ["backend/**", ".github/workflows/backend.yml"] }

jobs:
  test:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:17-alpine
        env:
          POSTGRES_DB: fishbook
          POSTGRES_USER: fishbook
          POSTGRES_PASSWORD: fishbook
        ports: ["5432:5432"]
        options: >-
          --health-cmd "pg_isready -U fishbook"
          --health-interval 5s --health-retries 10
      redis:
        image: redis:7-alpine
        ports: ["6379:6379"]
    defaults:
      run:
        working-directory: backend
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.3"
          extensions: pdo_pgsql, redis, gd, intl, mbstring
          tools: composer:v2
      - run: composer install --prefer-dist --no-progress --no-interaction
      - run: cp .env.example .env
      - run: php artisan key:generate
      - run: php artisan migrate --force
      - run: ./vendor/bin/pint --test
      - run: ./vendor/bin/phpstan analyse
      - run: ./vendor/bin/pest
      - name: Swagger spec is up to date
        run: |
          php artisan l5-swagger:generate
          git diff --exit-code storage/api-docs/openapi.json
```

### `.github/workflows/frontend.yml`

```yaml
name: frontend
on:
  pull_request:
    paths:
      - "frontend/**"
      - "backend/storage/api-docs/openapi.json"
      - ".github/workflows/frontend.yml"
  push:
    branches: [main]
    paths:
      - "frontend/**"
      - "backend/storage/api-docs/openapi.json"
      - ".github/workflows/frontend.yml"

jobs:
  build:
    runs-on: ubuntu-latest
    defaults:
      run:
        working-directory: frontend
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: "20"
          cache: "npm"
          cache-dependency-path: frontend/package-lock.json
      - run: npm ci
      - run: npm run lint
      - run: npm run typecheck
      - run: npm test -- --run
      - run: npm run build
      - name: API client is in sync
        run: |
          npm run generate:api
          git diff --exit-code src/lib/api-client
```

### Dependabot (`.github/dependabot.yml`)

Weekly updates for:
- `composer` in `/backend`
- `npm` in `/frontend`
- `github-actions` in `/`
- `docker` in `/backend` and `/frontend`

Grouped by ecosystem.

### `CODEOWNERS`

Stub `* @<github-handle>` — owner handle to be filled when known.

### README.md

~40 lines: what Fishbook is (one line), local setup (`make up && make migrate && open http://localhost:3000`), pointers to `SPEC.md`, `BRAND.md`, `AGENT.md`, regeneration commands for the OpenAPI spec and API client.

---

## 8. Acceptance Criteria

1. Fresh clone + `make up` + `make migrate` + `make test` works end-to-end on a clean machine with Docker installed.
2. `http://localhost:3000/` renders the styled landing page with hero, tagline, two disabled glass buttons, decorative blobs, and a "Coming soon" caption. Visually matches the brand mood described in `BRAND.md`.
3. `http://localhost:8000/api/v1/health` returns `{ok: true, version: "0.0.0"}` and the same endpoint is present in `storage/api-docs/openapi.json`.
4. `http://localhost:3000/api/health` returns `{ok: true}`.
5. CI is green on a PR that touches a trivial line in each service.
6. Larastan level 6, Pint, ESLint, `tsc --noEmit`, and `next build` are all clean.
7. `npm run generate:api` regenerates `frontend/src/lib/api-client/` to a no-diff state against the committed copy.
8. `prefers-reduced-motion` disables transitions on the landing page (verified via DevTools emulation).

---

## 9. Open Questions / Follow-Ups

None blocking slice 1.

Deferred items tracked elsewhere:
- **Slice 1.5 — first deploy** (Railway service provisioning, domain DNS, `deploy.yml`) once external accounts are ready.
- **Coverage gate reinstatement** in slice 2's backend.yml.
- **`e2e.yml` + Playwright** added in slice 3.
- **CODEOWNERS handle** filled in when the GitHub repo and owner are known.

---

## 10. Sources

- `SPEC.md` §10 (env vars), §11 (Docker compose), §13 (CI workflows), §15 (OpenAPI / Swagger).
- `AGENT.md` §1 (stack & versions), §2 (repository layout), §3 (coding conventions), §4 (security non-negotiables).
- `BRAND.md` §2 (color system), §3 (typography), §4 (layout & spacing), §5 (glass system), §6 (radii), §7 (iconography), §9 (motion).
