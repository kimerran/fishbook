# Slice 1 — Foundations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up the Fishbook monorepo with both services booting, brand system wired, OpenAPI round-trip working, and CI green — no product features.

**Architecture:** Sibling `backend/` (Laravel 13 + PHP 8.3 + Pest) and `frontend/` (Next.js 16 + React 19 + Tailwind 4) directories under one git root. A single `GET /api/v1/health` endpoint on the backend proves the OpenAPI annotation pipeline; a styled landing page at `/` on the frontend proves the brand system. Docker Compose orchestrates Postgres, Redis, MinIO, and both services for local dev. CI runs lint, types, tests, and a "spec/client in sync" check per service.

**Tech Stack:** Laravel 13 (released March 2026), PHP 8.3, Pest, Larastan, Laravel Pint, `darkaonline/l5-swagger`, Laravel Sanctum; Next.js 16 (16.2 LTS), React 19.2, TypeScript strict, Tailwind 4 (CSS-first), Vitest, happy-dom, `@openapitools/openapi-generator-cli`; Docker Compose, GitHub Actions, Postgres 17, Redis 7, MinIO.

**Spec:** [`docs/superpowers/specs/2026-05-16-foundations-design.md`](../specs/2026-05-16-foundations-design.md).

---

## Conventions

- Today's date for commit messages is **2026-05-16**.
- All commands are run from repo root unless stated otherwise.
- Backend commands are run with `cd backend && ...` (don't use `cd backend && cd .. && ...` — use absolute or one-shot `cd`).
- Conventional Commits everywhere (`feat:`, `fix:`, `chore:`, `test:`, `docs:`, `ci:`).
- Don't squash multiple tasks into one commit — every task ends with its own commit.
- The repo already has commit `6b19ee5` containing `SPEC.md`, `BRAND.md`, `AGENT.md`, and the design doc. Build on `main`.

---

## Task 1: Repo root files

**Files:**
- Create: `.gitignore`
- Create: `README.md`

- [ ] **Step 1: Create `.gitignore`**

```
# OS / editor
.DS_Store
Thumbs.db
.idea/
.vscode/
*.swp

# Env
.env
.env.local
.env.*.local
!.env.example

# Logs
*.log
npm-debug.log*
yarn-debug.log*

# Dependencies
node_modules/
vendor/

# Build artifacts
.next/
out/
build/
dist/

# Coverage
coverage/
.nyc_output/
.phpunit.result.cache

# Laravel
backend/storage/*.key
backend/storage/framework/cache/*
!backend/storage/framework/cache/.gitkeep
backend/storage/framework/sessions/*
!backend/storage/framework/sessions/.gitkeep
backend/storage/framework/views/*
!backend/storage/framework/views/.gitkeep
backend/storage/logs/*
!backend/storage/logs/.gitkeep
backend/bootstrap/cache/*
!backend/bootstrap/cache/.gitkeep
backend/public/storage

# Docker
docker-compose.override.yml
```

- [ ] **Step 2: Create `README.md`**

```markdown
# Fishbook

> Your Zen Sanctuary, Powered by Code.

A virtual aquarium web app. Curate a school of pet fishes that swim across a full-viewport canvas, customize the background, and even turn any GitHub repository into a living aquarium.

## Documents

- [SPEC.md](./SPEC.md) — what the product does
- [BRAND.md](./BRAND.md) — how it looks
- [AGENT.md](./AGENT.md) — how to build it
- [docs/superpowers/specs/](./docs/superpowers/specs/) — slice-by-slice designs
- [docs/superpowers/plans/](./docs/superpowers/plans/) — implementation plans

## Quickstart

```bash
make up           # start Postgres, Redis, MinIO, backend, frontend
make migrate      # run Laravel migrations
make test         # run backend + frontend test suites
```

Open:
- Frontend: http://localhost:3000
- Backend: http://localhost:8000/api/v1/health
- MinIO console: http://localhost:9001 (`minioadmin` / `minioadmin`)

## Regenerating the OpenAPI spec & client

```bash
make swagger      # regenerate backend/storage/api-docs/openapi.json
make api-client   # regenerate frontend/src/lib/api-client/
```

Both regenerated artifacts are committed to git. CI verifies they're in sync with the controllers and the spec respectively.
```

- [ ] **Step 3: Commit**

```bash
git add .gitignore README.md
git commit -m "chore: add gitignore and quickstart readme"
```

---

## Task 2: Backend Laravel scaffold and dependencies

**Files:**
- Create: `backend/` (entire Laravel 13 scaffold)

- [ ] **Step 1: Create the Laravel project**

```bash
composer create-project laravel/laravel backend "13.*" --no-interaction
```

If Larastan / l5-swagger don't yet have a `^x` constraint that matches Laravel 13 when Task 2 step 3 runs, use `--with-all-dependencies` (already included) plus `--ignore-platform-req=php` only if a maintainer hasn't tagged a compatible release. Prefer waiting/upgrading the dep over downgrading Laravel.

- [ ] **Step 2: Add runtime dependencies**

```bash
cd backend && composer require laravel/sanctum darkaonline/l5-swagger --no-interaction
```

- [ ] **Step 3: Add dev dependencies**

```bash
cd backend && composer require --dev pestphp/pest pestphp/pest-plugin-laravel larastan/larastan --no-interaction --with-all-dependencies
```

- [ ] **Step 4: Install the API surface (Sanctum + routes/api.php)**

```bash
cd backend && php artisan install:api --no-interaction
```

Expected: creates `routes/api.php`, publishes Sanctum config and migration.

- [ ] **Step 5: Initialize Pest**

```bash
cd backend && ./vendor/bin/pest --init
```

Expected: creates `tests/Pest.php` and replaces `tests/TestCase.php` shape with Pest defaults.

- [ ] **Step 6: Verify Laravel boots**

```bash
cd backend && php artisan --version
```

Expected output starts with `Laravel Framework 13.`

- [ ] **Step 7: Commit**

```bash
git add backend/
git commit -m "feat(backend): scaffold Laravel 13 with sanctum, swagger, pest, larastan"
```

---

## Task 3: Backend config and env

**Files:**
- Modify: `backend/.env.example`
- Modify: `backend/bootstrap/app.php`
- Modify: `backend/config/database.php`
- Modify: `backend/config/cors.php` (or create — Laravel 13 may not publish it by default)
- Modify: `backend/config/sanctum.php`
- Modify: `backend/config/hashing.php`

- [ ] **Step 1: Publish cors and hashing configs if missing**

```bash
cd backend && php artisan config:publish cors
cd backend && php artisan config:publish hashing
```

Expected: creates `config/cors.php` and `config/hashing.php` if they don't exist; no-op otherwise.

- [ ] **Step 2: Replace `backend/.env.example` with the SPEC version**

Replace the entire file with:

```
APP_NAME=Fishbook
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8000
APP_TIMEZONE=UTC
APP_VERSION=0.0.0

LOG_CHANNEL=stack
LOG_LEVEL=debug

DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=fishbook
DB_USERNAME=fishbook
DB_PASSWORD=fishbook

REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=null

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=cookie
SESSION_LIFETIME=120

# S3 / MinIO
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=minioadmin
AWS_SECRET_ACCESS_KEY=minioadmin
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=fishbook
AWS_ENDPOINT=http://minio:9000
AWS_USE_PATH_STYLE_ENDPOINT=true

# Sanctum / CORS
SANCTUM_STATEFUL_DOMAINS=localhost:3000,fishbook.neri.ph
FRONTEND_URL=http://localhost:3000

# Admin seeding (REQUIRED in production)
ADMIN_SEED_PASSWORD=

# Fal AI
FAL_API_KEY=
FAL_DAILY_GLOBAL_LIMIT=200

# GitHub
GITHUB_TOKEN=

# Google OAuth (optional)
GOOGLE_OAUTH_ENABLED=false
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=http://localhost:8000/api/v1/auth/google/callback

# Sentry
SENTRY_LARAVEL_DSN=
```

- [ ] **Step 3: Update `backend/bootstrap/app.php` to use `/api/v1` prefix**

Find the `withRouting(...)` call and modify it to include `apiPrefix: 'api/v1'`:

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
```

- [ ] **Step 4: Set `pgsql` as default in `backend/config/database.php`**

Find the line `'default' => env('DB_CONNECTION', 'sqlite'),` and change to:

```php
'default' => env('DB_CONNECTION', 'pgsql'),
```

- [ ] **Step 5: Configure CORS in `backend/config/cors.php`**

Replace the `paths` and `allowed_origins` entries:

```php
'paths' => ['api/*', 'sanctum/csrf-cookie'],
'allowed_methods' => ['*'],
'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:3000')],
'allowed_origins_patterns' => [],
'allowed_headers' => ['*'],
'exposed_headers' => [],
'max_age' => 0,
'supports_credentials' => true,
```

- [ ] **Step 6: Set bcrypt cost in `backend/config/hashing.php`**

Find the `'bcrypt'` entry and set `'rounds' => 12,`:

```php
'bcrypt' => [
    'rounds' => env('BCRYPT_ROUNDS', 12),
    'verify' => true,
    'limit' => null,
],
```

- [ ] **Step 7: Set sanctum expiration to null in `backend/config/sanctum.php`**

Find `'expiration' => null,` and confirm it is `null` (Laravel default — leave as-is; no change needed if already null).

- [ ] **Step 8: Copy env file and generate app key**

```bash
cd backend && cp .env.example .env
cd backend && php artisan key:generate
```

- [ ] **Step 9: Commit**

```bash
git add backend/.env.example backend/bootstrap/app.php backend/config/
git commit -m "feat(backend): configure pgsql, /api/v1 prefix, cors, bcrypt rounds 12"
```

Note: `.env` is gitignored — only `.env.example` is committed.

---

## Task 4: Backend code-quality config

**Files:**
- Create: `backend/phpstan.neon`
- Create: `backend/pint.json`

- [ ] **Step 1: Create `backend/phpstan.neon`**

```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    paths:
        - app
    level: 6
    checkMissingIterableValueType: false
```

- [ ] **Step 2: Create `backend/pint.json`**

```json
{
    "preset": "laravel"
}
```

- [ ] **Step 3: Run Pint and PHPStan to verify clean baseline**

```bash
cd backend && ./vendor/bin/pint --test
cd backend && ./vendor/bin/phpstan analyse --memory-limit=512M
```

Expected: both exit 0. If Pint reports diffs against the scaffold, run `./vendor/bin/pint` (without `--test`) once, commit those fixes separately on top of this step.

- [ ] **Step 4: Commit**

```bash
git add backend/phpstan.neon backend/pint.json
git commit -m "ci(backend): add pint and phpstan level 6 config"
```

---

## Task 5: Backend health endpoint (TDD)

**Files:**
- Create: `backend/tests/Feature/HealthTest.php`
- Create: `backend/app/Http/Controllers/Api/V1/HealthController.php`
- Modify: `backend/routes/api.php`

- [ ] **Step 1: Write the failing Pest feature test**

Create `backend/tests/Feature/HealthTest.php`:

```php
<?php

it('returns ok on the health endpoint', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJson(['ok' => true])
        ->assertJsonStructure(['ok', 'version']);
});
```

- [ ] **Step 2: Run the test and confirm it fails**

```bash
cd backend && ./vendor/bin/pest --filter=HealthTest
```

Expected: 1 failed. Failure reason: 404 from missing route.

- [ ] **Step 3: Create the controller**

Create `backend/app/Http/Controllers/Api/V1/HealthController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;

class HealthController extends Controller
{
    public function __invoke(): array
    {
        return [
            'ok' => true,
            'version' => config('app.version', '0.0.0'),
        ];
    }
}
```

- [ ] **Step 4: Add `app.version` to `backend/config/app.php`**

Find the `name` entry and add `version` directly below it:

```php
'name' => env('APP_NAME', 'Laravel'),
'version' => env('APP_VERSION', '0.0.0'),
```

- [ ] **Step 5: Register the route in `backend/routes/api.php`**

Replace the file's contents with:

```php
<?php

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');
```

- [ ] **Step 6: Run the test and confirm it passes**

```bash
cd backend && ./vendor/bin/pest --filter=HealthTest
```

Expected: 1 passed.

- [ ] **Step 7: Run full Pest suite and PHPStan**

```bash
cd backend && ./vendor/bin/pest
cd backend && ./vendor/bin/phpstan analyse --memory-limit=512M
```

Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add backend/app/Http/Controllers/Api/V1/HealthController.php \
        backend/routes/api.php \
        backend/config/app.php \
        backend/tests/Feature/HealthTest.php
git commit -m "feat(backend): add /api/v1/health endpoint with feature test"
```

---

## Task 6: Backend OpenAPI annotations and generation

**Files:**
- Modify: `backend/app/Http/Controllers/Api/V1/HealthController.php`
- Modify: `backend/config/l5-swagger.php`
- Create: `backend/storage/api-docs/openapi.json`

- [ ] **Step 1: Configure l5-swagger for OpenAPI 3.1.0**

In `backend/config/l5-swagger.php`, locate the `default` documentation block and ensure:

- `'docs' => storage_path('api-docs')` (default — confirm)
- `'docs_json' => 'openapi.json'`
- `'docs_yaml' => 'openapi.yaml'`
- In `'constants'`, set `'L5_SWAGGER_CONST_HOST' => env('APP_URL', 'http://localhost:8000').'/api/v1'`

If `'openapi'` key exists at the top level, set it to `'3.1.0'`. Otherwise add it next to `'default'`:

```php
'default' => 'default',

'documentations' => [
    'default' => [
        'api' => [
            'title' => 'Fishbook API',
        ],
        'routes' => [
            'api' => 'api/documentation',
        ],
        'paths' => [
            'use_absolute_path' => env('L5_SWAGGER_USE_ABSOLUTE_PATH', true),
            'docs_json' => 'openapi.json',
            'docs_yaml' => 'openapi.yaml',
            'format_to_use_for_docs' => env('L5_FORMAT_TO_USE_FOR_DOCS', 'json'),
            'annotations' => [
                base_path('app/Http/Controllers'),
            ],
        ],
    ],
],
```

Note: l5-swagger v9+ produces OpenAPI 3.0 by default; 3.1 needs `swagger-php` v5 which `l5-swagger` v9 ships with. If `'openapi'` version is configurable, set `'3.1.0'`. If not, accept `'3.0.0'` — spec parity for the client generator is what matters, not the version string.

- [ ] **Step 2: Add OpenAPI attributes to the controller**

Replace `backend/app/Http/Controllers/Api/V1/HealthController.php` with:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use OpenApi\Attributes as OA;

#[OA\Info(version: '1.0.0', title: 'Fishbook API')]
#[OA\Server(url: '/api/v1')]
class HealthController extends Controller
{
    #[OA\Get(
        path: '/health',
        summary: 'Service liveness probe',
        tags: ['meta'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'OK',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'ok', type: 'boolean', example: true),
                        new OA\Property(property: 'version', type: 'string', example: '1.0.0'),
                    ],
                    type: 'object',
                ),
            ),
        ],
    )]
    public function __invoke(): array
    {
        return [
            'ok' => true,
            'version' => config('app.version', '0.0.0'),
        ];
    }
}
```

- [ ] **Step 3: Generate the OpenAPI spec**

```bash
cd backend && php artisan l5-swagger:generate
```

Expected: creates `backend/storage/api-docs/openapi.json` containing the `/health` path.

- [ ] **Step 4: Verify the spec has the health endpoint**

```bash
cd backend && grep -q '"/health"' storage/api-docs/openapi.json && echo OK
```

Expected: prints `OK`.

- [ ] **Step 5: Re-run tests to ensure annotations didn't break behavior**

```bash
cd backend && ./vendor/bin/pest
```

Expected: all green.

- [ ] **Step 6: Update `.gitignore` to allow the generated spec**

Confirm `backend/storage/api-docs/` is NOT excluded by the root `.gitignore`. The current ignore patterns exclude `backend/storage/framework/...` and `backend/storage/logs/`, so `backend/storage/api-docs/` is safe.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Controllers/Api/V1/HealthController.php \
        backend/config/l5-swagger.php \
        backend/storage/api-docs/openapi.json
git commit -m "feat(backend): annotate health endpoint and generate openapi spec"
```

---

## Task 7: Backend Dockerfile

**Files:**
- Create: `backend/Dockerfile`
- Create: `backend/.dockerignore`

- [ ] **Step 1: Create `backend/.dockerignore`**

```
.git
.env
.env.local
storage/logs/*
storage/framework/cache/*
storage/framework/sessions/*
storage/framework/views/*
vendor/
node_modules/
tests/
.phpunit.result.cache
```

- [ ] **Step 2: Create `backend/Dockerfile`**

```dockerfile
# Multi-stage Dockerfile for the Fishbook backend.
# Dev: docker compose mounts the source and runs `php artisan serve`.
# Prod: future slice 1.5 uses the `app` stage.

FROM php:8.3-cli-alpine AS base

RUN apk add --no-cache \
      git \
      unzip \
      libzip-dev \
      icu-dev \
      postgresql-dev \
      oniguruma-dev \
      libpng-dev \
      libjpeg-turbo-dev \
      libwebp-dev \
      freetype-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype --with-webp \
    && docker-php-ext-install pdo pdo_pgsql zip intl mbstring gd bcmath \
    && pecl install redis \
    && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# --- Dependencies stage (cached when composer.* unchanged) ---
FROM base AS deps
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --no-scripts --prefer-dist

# --- Application stage (used in prod) ---
FROM base AS app
COPY --from=deps /var/www/html/vendor ./vendor
COPY . .
RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data storage bootstrap/cache
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
```

- [ ] **Step 3: Build the image to verify it works**

```bash
docker build -t fishbook-backend:slice1 backend/
```

Expected: build succeeds, ~2–4 minutes on first run.

- [ ] **Step 4: Commit**

```bash
git add backend/Dockerfile backend/.dockerignore
git commit -m "ci(backend): add multi-stage Dockerfile"
```

---

## Task 8: Frontend Next.js scaffold and dependencies

**Files:**
- Create: `frontend/` (Next.js 16 scaffold)
- Create: `frontend/.nvmrc`
- Create: `frontend/vitest.config.ts`
- Create: `frontend/tests/unit/.gitkeep`
- Modify: `frontend/package.json` (scripts)

- [ ] **Step 1: Run `create-next-app`**

```bash
npx --yes create-next-app@latest frontend \
  --typescript --tailwind --app --eslint --src-dir \
  --import-alias "@/*" --no-turbopack --use-npm --skip-install \
  --disable-git
```

If `--disable-git` isn't recognized by the installed `create-next-app` version, fall back to:

```bash
npx --yes create-next-app@latest frontend \
  --typescript --tailwind --app --eslint --src-dir \
  --import-alias "@/*" --no-turbopack --use-npm --skip-install
rm -rf frontend/.git
```

If `--no-turbopack` isn't recognized, omit it. If `--skip-install` isn't recognized, omit it; allow it to install.

- [ ] **Step 2: Install dependencies**

```bash
cd frontend && npm install
```

- [ ] **Step 3: Add dev dependencies**

```bash
cd frontend && npm install -D \
  vitest @vitejs/plugin-react @vitest/coverage-v8 \
  @testing-library/react @testing-library/dom @testing-library/jest-dom \
  happy-dom \
  @openapitools/openapi-generator-cli \
  prettier
```

- [ ] **Step 4: Pin Node version with `.nvmrc`**

Create `frontend/.nvmrc`:

```
20
```

- [ ] **Step 5: Create `frontend/vitest.config.ts`**

```ts
import { defineConfig } from "vitest/config";
import react from "@vitejs/plugin-react";
import path from "node:path";

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      "@": path.resolve(__dirname, "./src"),
    },
  },
  test: {
    environment: "happy-dom",
    globals: false,
    include: ["tests/**/*.test.{ts,tsx}", "src/**/*.test.{ts,tsx}"],
  },
});
```

- [ ] **Step 6: Add scripts to `frontend/package.json`**

Open `frontend/package.json`. The `scripts` block (created by `create-next-app`) should already contain `dev`, `build`, `start`, `lint`. Add:

```json
{
  "scripts": {
    "dev": "next dev",
    "build": "next build",
    "start": "next start",
    "lint": "next lint",
    "typecheck": "tsc --noEmit",
    "test": "vitest",
    "generate:api": "openapi-generator-cli generate -i ../backend/storage/api-docs/openapi.json -g typescript-fetch -o src/lib/api-client --skip-validate-spec"
  }
}
```

Preserve any existing fields create-next-app added.

- [ ] **Step 7: Create the tests directory placeholder**

```bash
mkdir -p frontend/tests/unit && touch frontend/tests/unit/.gitkeep
```

- [ ] **Step 8: Verify the scaffold builds and lints**

```bash
cd frontend && npm run lint
cd frontend && npm run typecheck
cd frontend && npm run build
```

Expected: all three succeed against the create-next-app default page.

- [ ] **Step 9: Commit**

```bash
git add frontend/
git commit -m "feat(frontend): scaffold Next.js 16 with vitest, testing-library, openapi-generator"
```

---

## Task 9: Frontend brand tokens in `globals.css`

**Files:**
- Modify: `frontend/src/app/globals.css`

- [ ] **Step 1: Replace `frontend/src/app/globals.css` with the brand-wired version**

```css
@import "tailwindcss";

@theme {
  /* === Primary — Sage === */
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

  /* === Secondary — Aquatic Blue-Grey === */
  --color-secondary: #50616a;
  --color-on-secondary: #ffffff;
  --color-secondary-container: #d3e5f0;
  --color-on-secondary-container: #566770;
  --color-secondary-fixed: #d3e5f0;
  --color-secondary-fixed-dim: #b7c9d3;
  --color-on-secondary-fixed: #0c1e25;
  --color-on-secondary-fixed-variant: #384951;

  /* === Tertiary — Neutral Stone === */
  --color-tertiary: #59605d;
  --color-on-tertiary: #ffffff;
  --color-tertiary-container: #b1b7b3;
  --color-on-tertiary-container: #424846;
  --color-tertiary-fixed: #dee4e0;
  --color-tertiary-fixed-dim: #c2c8c4;
  --color-on-tertiary-fixed: #171d1b;
  --color-on-tertiary-fixed-variant: #424845;

  /* === Surface neutrals === */
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

  /* === Status === */
  --color-error: #ba1a1a;
  --color-on-error: #ffffff;
  --color-error-container: #ffdad6;
  --color-on-error-container: #93000a;

  /* === Typography === */
  --font-sans: "Inter", system-ui, sans-serif;
  --text-headline-lg: 32px;
  --text-headline-md: 20px;
  --text-body-lg: 16px;
  --text-body-md: 14px;
  --text-label-caps: 12px;

  /* === Radii === */
  --radius-lg: 8px;
  --radius-xl: 12px;

  /* === Spacing rhythm === */
  --spacing-unit: 8px;
  --spacing-gutter: 24px;
  --spacing-margin-mobile: 16px;
  --spacing-margin-desktop: 48px;
}

@layer base {
  body {
    @apply bg-background text-on-surface font-sans antialiased;
  }
}

@layer components {
  .glass-xs {
    @apply bg-white/10 backdrop-blur-sm;
  }
  .glass-sm {
    @apply bg-white/20 backdrop-blur-md border border-white/20;
  }
  .glass-md {
    @apply bg-white/40 backdrop-blur-md border border-white/20
           shadow-[0_8px_32px_rgba(0,0,0,0.04)];
  }
  .glass-lg {
    @apply bg-white/50 backdrop-blur-xl border border-white/20
           shadow-[0_8px_32px_rgba(0,0,0,0.06)];
  }
  .glass-overlay {
    @apply bg-gradient-to-t from-black/60 to-transparent backdrop-blur-[2px];
  }
  .label-caps {
    @apply uppercase tracking-[0.1em] text-[12px] font-medium leading-none;
  }
}

@media (prefers-reduced-motion: reduce) {
  *,
  *::before,
  *::after {
    animation: none !important;
    transition: none !important;
  }
}
```

- [ ] **Step 2: Verify `next build` still succeeds**

```bash
cd frontend && npm run build
```

Expected: success.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/app/globals.css
git commit -m "feat(frontend): wire brand tokens and glass utilities into globals.css"
```

---

## Task 10: Frontend RootLayout (Inter + Material Symbols)

**Files:**
- Modify: `frontend/src/app/layout.tsx`

- [ ] **Step 1: Replace `frontend/src/app/layout.tsx`**

```tsx
import type { Metadata } from "next";
import { Inter } from "next/font/google";
import "./globals.css";

const inter = Inter({
  subsets: ["latin"],
  weight: ["300", "400", "500", "700"],
  variable: "--font-inter",
});

export const metadata: Metadata = {
  title: "Fishbook — Your Zen Sanctuary, Powered by Code",
  description:
    "A virtual aquarium for the curious. Curate fish, shape atmospheres, watch repositories swim.",
};

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
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

- [ ] **Step 2: Verify the build**

```bash
cd frontend && npm run build
```

Expected: success.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/app/layout.tsx
git commit -m "feat(frontend): wire inter font and material symbols in root layout"
```

---

## Task 11: Frontend landing page (TDD)

**Files:**
- Create: `frontend/tests/unit/landing.test.tsx`
- Modify: `frontend/src/app/page.tsx`

- [ ] **Step 1: Write the failing Vitest test**

Create `frontend/tests/unit/landing.test.tsx`:

```tsx
import { render, screen } from "@testing-library/react";
import { expect, test } from "vitest";
import Landing from "@/app/page";

test("renders the brand tagline as the page H1", () => {
  render(<Landing />);
  expect(
    screen.getByRole("heading", { level: 1, name: /Your Zen Sanctuary, Powered by Code/i }),
  ).toBeInTheDocument();
});

test("renders disabled sign-in and create-account affordances", () => {
  render(<Landing />);
  const signIn = screen.getByRole("button", { name: /sign in/i });
  const create = screen.getByRole("button", { name: /create account/i });
  expect(signIn).toBeDisabled();
  expect(create).toBeDisabled();
});
```

- [ ] **Step 2: Configure `@testing-library/jest-dom` matchers**

Create `frontend/tests/setup.ts`:

```ts
import "@testing-library/jest-dom/vitest";
```

Modify `frontend/vitest.config.ts` — add `setupFiles` to the `test` block:

```ts
import { defineConfig } from "vitest/config";
import react from "@vitejs/plugin-react";
import path from "node:path";

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      "@": path.resolve(__dirname, "./src"),
    },
  },
  test: {
    environment: "happy-dom",
    globals: false,
    setupFiles: ["./tests/setup.ts"],
    include: ["tests/**/*.test.{ts,tsx}", "src/**/*.test.{ts,tsx}"],
  },
});
```

- [ ] **Step 3: Run the test and confirm it fails**

```bash
cd frontend && npm test -- --run
```

Expected: 2 failed (default scaffold page doesn't match — no matching H1, no buttons by those names).

- [ ] **Step 4: Replace `frontend/src/app/page.tsx`**

```tsx
export default function Landing() {
  return (
    <main
      className="relative min-h-screen overflow-hidden"
      style={{
        background:
          "radial-gradient(ellipse at top, #eef3f6 0%, #f8fafb 70%)",
      }}
    >
      <div
        aria-hidden
        className="absolute -top-32 -right-24 w-96 h-96
                   rounded-full blur-3xl mix-blend-multiply"
        style={{ backgroundColor: "rgba(168, 187, 162, 0.2)" }}
      />
      <div
        aria-hidden
        className="absolute bottom-0 left-12 w-80 h-80
                   rounded-full blur-2xl mix-blend-multiply"
        style={{ backgroundColor: "rgba(211, 229, 240, 0.3)" }}
      />

      <section
        className="relative mx-auto max-w-[1200px]
                   px-4 md:px-12
                   flex flex-col items-center justify-center min-h-screen
                   text-center gap-6"
      >
        <p className="label-caps text-on-surface-variant">Fishbook</p>
        <h1
          className="text-[24px] md:text-[32px] font-light leading-[1.2]
                     tracking-[0.02em] text-on-surface max-w-[24ch]"
        >
          Your Zen Sanctuary, Powered by Code.
        </h1>
        <p
          className="font-light leading-[1.6] text-on-surface-variant
                     max-w-[48ch] text-[16px]"
        >
          A virtual aquarium for the curious. Curate a school of fish, shape the
          atmosphere, and let your favorite repository become its own tide pool.
        </p>
        <div className="flex gap-4 mt-4">
          <button
            disabled
            aria-disabled
            className="glass-md rounded-full px-6 py-3 label-caps
                       text-on-surface opacity-50 cursor-not-allowed"
          >
            Sign in
          </button>
          <button
            disabled
            aria-disabled
            className="glass-sm rounded-full px-6 py-3 label-caps
                       text-on-surface-variant opacity-50 cursor-not-allowed"
          >
            Create account
          </button>
        </div>
        <p className="label-caps text-on-surface-variant/60 mt-8">
          Coming soon
        </p>
      </section>
    </main>
  );
}
```

Note: decorative blob colors use inline `style` rather than Tailwind opacity utilities to sidestep arbitrary-variant brittleness on Tailwind 4 + custom palette tokens. This matches BRAND.md §5.4 visually.

- [ ] **Step 5: Run the test and confirm it passes**

```bash
cd frontend && npm test -- --run
```

Expected: 2 passed.

- [ ] **Step 6: Run lint, typecheck, build**

```bash
cd frontend && npm run lint
cd frontend && npm run typecheck
cd frontend && npm run build
```

Expected: all three succeed.

- [ ] **Step 7: Visual smoke test (optional but recommended)**

```bash
cd frontend && npm run dev
```

Open `http://localhost:3000` in a browser. Expected:
- Pale cool-white background with two faint blobs (top-right sage-tinted, bottom-left blue-grey).
- "Fishbook" small uppercase label.
- "Your Zen Sanctuary, Powered by Code." H1 in light-weight Inter.
- Lead paragraph below.
- Two disabled glass buttons.
- "Coming soon" caption.

Kill the dev server with `Ctrl+C` after verifying.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/page.tsx \
        frontend/tests/unit/landing.test.tsx \
        frontend/tests/setup.ts \
        frontend/vitest.config.ts
git commit -m "feat(frontend): add landing page with brand-styled hero and tests"
```

---

## Task 12: Frontend health route handler

**Files:**
- Create: `frontend/src/app/api/health/route.ts`

- [ ] **Step 1: Create the route handler**

```ts
export const dynamic = "force-static";

export async function GET() {
  return Response.json({ ok: true });
}
```

- [ ] **Step 2: Verify build**

```bash
cd frontend && npm run build
```

Expected: success; `/api/health` listed in route output.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/app/api/health/route.ts
git commit -m "feat(frontend): add /api/health route handler"
```

---

## Task 13: Frontend OpenAPI client generation

**Files:**
- Create: `frontend/src/lib/api-client/` (generated)
- Create: `frontend/openapitools.json`

- [ ] **Step 1: Initialize `openapi-generator-cli` config**

Create `frontend/openapitools.json`:

```json
{
  "$schema": "./node_modules/@openapitools/openapi-generator-cli/config.schema.json",
  "spaces": 2,
  "generator-cli": {
    "version": "7.10.0"
  }
}
```

- [ ] **Step 2: Verify Java is available locally**

```bash
java -version
```

Expected: Java 11+ reports a version. If not installed, install OpenJDK 17 via the OS package manager (`sudo apt install default-jre` on Debian/Ubuntu). The CI workflow installs Java via `actions/setup-java` in Task 17.

- [ ] **Step 3: Generate the API client**

```bash
cd frontend && npm run generate:api
```

Expected: creates `frontend/src/lib/api-client/` with `apis/`, `models/`, `runtime.ts`, `index.ts`. First run may download a JRE — that's fine.

- [ ] **Step 4: Add the generated client header comment to `.gitattributes`**

Create `frontend/.gitattributes`:

```
src/lib/api-client/** linguist-generated=true
```

- [ ] **Step 5: Verify typecheck still passes with generated code**

```bash
cd frontend && npm run typecheck
```

Expected: success. If the generated client triggers TypeScript errors against `strict` + `noUncheckedIndexedAccess`, add an exclude entry to `frontend/tsconfig.json`:

Open `frontend/tsconfig.json`. In `compilerOptions`, ensure `"strict": true` is set and `"noUncheckedIndexedAccess": true` is added. Then in the top-level `"exclude"` array (sibling of `compilerOptions`), add `"src/lib/api-client"` only if typecheck still fails after adding the strictness flag — generated code is allowed to bypass strict checks.

```json
{
  "compilerOptions": {
    "strict": true,
    "noUncheckedIndexedAccess": true,
    /* …existing options… */
  },
  "exclude": ["node_modules", "src/lib/api-client"]
}
```

Re-run typecheck:

```bash
cd frontend && npm run typecheck
```

Expected: success.

- [ ] **Step 6: Commit**

```bash
git add frontend/openapitools.json \
        frontend/.gitattributes \
        frontend/tsconfig.json \
        frontend/src/lib/api-client/
git commit -m "feat(frontend): generate typescript-fetch api client from openapi spec"
```

---

## Task 14: Frontend Dockerfile

**Files:**
- Create: `frontend/Dockerfile`
- Create: `frontend/.dockerignore`

- [ ] **Step 1: Create `frontend/.dockerignore`**

```
.git
.next
node_modules
.env
.env.local
tests
coverage
```

- [ ] **Step 2: Create `frontend/Dockerfile`**

```dockerfile
# Multi-stage Dockerfile for the Fishbook frontend.
# Dev: docker compose mounts the source and runs `npm run dev`.
# Prod: future slice 1.5 uses the `runner` stage.

FROM node:20-alpine AS base
WORKDIR /app

# --- Dependencies stage ---
FROM base AS deps
COPY package.json package-lock.json ./
RUN npm ci

# --- Builder stage ---
FROM base AS builder
COPY --from=deps /app/node_modules ./node_modules
COPY . .
RUN npm run build

# --- Runner stage (prod) ---
FROM base AS runner
ENV NODE_ENV=production
COPY --from=builder /app/.next ./.next
COPY --from=builder /app/public ./public
COPY --from=builder /app/package.json /app/package-lock.json ./
COPY --from=builder /app/node_modules ./node_modules
EXPOSE 3000
CMD ["npm", "run", "start"]
```

- [ ] **Step 3: Build the image to verify**

```bash
docker build -t fishbook-frontend:slice1 frontend/
```

Expected: build succeeds.

- [ ] **Step 4: Commit**

```bash
git add frontend/Dockerfile frontend/.dockerignore
git commit -m "ci(frontend): add multi-stage Dockerfile"
```

---

## Task 15: docker-compose.yml

**Files:**
- Create: `docker-compose.yml`
- Create: `frontend/.env.example`

- [ ] **Step 1: Create `frontend/.env.example`**

```
NEXT_PUBLIC_APP_URL=http://localhost:3000
NEXT_PUBLIC_API_BASE_URL=http://localhost:8000/api/v1

BACKEND_INTERNAL_URL=http://backend:8000/api/v1
SESSION_COOKIE_NAME=fishbook_session
SESSION_COOKIE_SECRET=change-me-32-bytes-minimum-development

NEXT_PUBLIC_SENTRY_DSN=
NEXT_PUBLIC_GOOGLE_OAUTH_ENABLED=false
```

- [ ] **Step 2: Create `frontend/.env` (gitignored, used by compose)**

```bash
cp frontend/.env.example frontend/.env
```

- [ ] **Step 3: Create `docker-compose.yml` at repo root**

```yaml
services:
  db:
    image: postgres:17-alpine
    environment:
      POSTGRES_DB: fishbook
      POSTGRES_USER: fishbook
      POSTGRES_PASSWORD: fishbook
    volumes: [pgdata:/var/lib/postgresql/data]
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U fishbook"]
      interval: 5s
      retries: 10
    ports: ["5432:5432"]

  redis:
    image: redis:7-alpine
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 5s
      retries: 10
    ports: ["6379:6379"]

  minio:
    image: minio/minio:latest
    command: server /data --console-address ":9001"
    environment:
      MINIO_ROOT_USER: minioadmin
      MINIO_ROOT_PASSWORD: minioadmin
    ports: ["9000:9000", "9001:9001"]
    volumes: [miniodata:/data]

  minio-init:
    image: minio/mc:latest
    depends_on: [minio]
    entrypoint: >
      /bin/sh -c "
      until /usr/bin/mc alias set local http://minio:9000 minioadmin minioadmin; do sleep 1; done;
      /usr/bin/mc mb -p local/fishbook;
      /usr/bin/mc anonymous set download local/fishbook;
      exit 0;
      "

  backend:
    build:
      context: ./backend
      target: base
    depends_on:
      db: { condition: service_healthy }
      redis: { condition: service_healthy }
      minio: { condition: service_started }
    env_file: ./backend/.env
    ports: ["8000:8000"]
    volumes:
      - ./backend:/var/www/html
    working_dir: /var/www/html
    command: sh -c "composer install --no-interaction --no-progress && php artisan serve --host=0.0.0.0 --port=8000"

  frontend:
    build:
      context: ./frontend
      target: base
    depends_on: [backend]
    env_file: ./frontend/.env
    ports: ["3000:3000"]
    volumes:
      - ./frontend:/app
      - /app/node_modules
    working_dir: /app
    command: sh -c "npm install && npm run dev"

volumes:
  pgdata: {}
  miniodata: {}
```

- [ ] **Step 4: Bring up the stack and verify**

```bash
docker compose up -d
docker compose ps
```

Wait ~60 seconds for first-boot composer/npm installs, then verify:

```bash
curl -sf http://localhost:8000/api/v1/health
curl -sf http://localhost:3000/api/health
curl -sf -o /dev/null -w "%{http_code}\n" http://localhost:3000/
```

Expected:
- Backend curl: `{"ok":true,"version":"0.0.0"}`
- Frontend `/api/health` curl: `{"ok":true}`
- Frontend `/` curl: `200`

If backend can't connect to Postgres, the migrations haven't run yet. Run:

```bash
docker compose exec backend php artisan migrate --force
```

- [ ] **Step 5: Tear down**

```bash
docker compose down
```

(Don't pass `-v` — keep volumes for next-time bring-up.)

- [ ] **Step 6: Commit**

```bash
git add docker-compose.yml frontend/.env.example
git commit -m "feat: add docker compose stack with postgres, redis, minio, backend, frontend"
```

---

## Task 16: Makefile

**Files:**
- Create: `Makefile`

- [ ] **Step 1: Create `Makefile`**

```make
.PHONY: up down restart migrate seed test lint fmt swagger api-client build-images

up:
	docker compose up -d
	@echo "Backend:  http://localhost:8000/api/v1/health"
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
	docker compose exec backend ./vendor/bin/phpstan analyse --memory-limit=512M
	docker compose exec frontend npm run lint
	docker compose exec frontend npm run typecheck

fmt:
	docker compose exec backend ./vendor/bin/pint
	docker compose exec frontend npx prettier --write .

swagger:
	docker compose exec backend php artisan l5-swagger:generate

api-client:
	docker compose exec frontend npm run generate:api

build-images:
	docker build -t fishbook-backend:slice1 backend/
	docker build -t fishbook-frontend:slice1 frontend/
```

- [ ] **Step 2: Verify `make` targets work**

```bash
make up
sleep 30
make test
make down
```

Expected: tests run; no errors from Make about missing targets.

- [ ] **Step 3: Commit**

```bash
git add Makefile
git commit -m "feat: add Makefile with up/down/test/lint/swagger/api-client targets"
```

---

## Task 17: CI workflows

**Files:**
- Create: `.github/workflows/backend.yml`
- Create: `.github/workflows/frontend.yml`

- [ ] **Step 1: Create `.github/workflows/backend.yml`**

```yaml
name: backend
on:
  pull_request:
    paths:
      - "backend/**"
      - ".github/workflows/backend.yml"
  push:
    branches: [main]
    paths:
      - "backend/**"
      - ".github/workflows/backend.yml"

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
          --health-interval 5s
          --health-retries 10
      redis:
        image: redis:7-alpine
        ports: ["6379:6379"]
        options: >-
          --health-cmd "redis-cli ping"
          --health-interval 5s
          --health-retries 10
    defaults:
      run:
        working-directory: backend
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.3"
          extensions: pdo_pgsql, redis, gd, intl, mbstring, bcmath, zip
          tools: composer:v2
      - name: Cache composer
        uses: actions/cache@v4
        with:
          path: backend/vendor
          key: composer-${{ runner.os }}-${{ hashFiles('backend/composer.lock') }}
          restore-keys: composer-${{ runner.os }}-
      - run: composer install --prefer-dist --no-progress --no-interaction
      - run: cp .env.example .env
      - run: php artisan key:generate
      - name: Set DB host for service container
        run: |
          sed -i 's/^DB_HOST=db$/DB_HOST=127.0.0.1/' .env
          sed -i 's/^REDIS_HOST=redis$/REDIS_HOST=127.0.0.1/' .env
      - run: php artisan migrate --force
      - run: ./vendor/bin/pint --test
      - run: ./vendor/bin/phpstan analyse --memory-limit=512M
      - run: ./vendor/bin/pest
      - name: OpenAPI spec is up to date
        run: |
          php artisan l5-swagger:generate
          git diff --exit-code storage/api-docs/openapi.json
```

- [ ] **Step 2: Create `.github/workflows/frontend.yml`**

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
      - uses: actions/setup-java@v4
        with:
          distribution: temurin
          java-version: "17"
      - run: npm ci
      - run: npm run lint
      - run: npm run typecheck
      - run: npm test -- --run
      - run: npm run build
      - name: API client is in sync with backend OpenAPI spec
        run: |
          npm run generate:api
          git diff --exit-code src/lib/api-client
```

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/backend.yml .github/workflows/frontend.yml
git commit -m "ci: add backend and frontend workflows with lint/test/build/sync checks"
```

---

## Task 18: Dependabot, CODEOWNERS, and acceptance verification

**Files:**
- Create: `.github/dependabot.yml`
- Create: `.github/CODEOWNERS`

- [ ] **Step 1: Create `.github/dependabot.yml`**

```yaml
version: 2
updates:
  - package-ecosystem: composer
    directory: /backend
    schedule:
      interval: weekly
    groups:
      backend-deps:
        patterns: ["*"]

  - package-ecosystem: npm
    directory: /frontend
    schedule:
      interval: weekly
    groups:
      frontend-deps:
        patterns: ["*"]

  - package-ecosystem: github-actions
    directory: /
    schedule:
      interval: weekly

  - package-ecosystem: docker
    directory: /backend
    schedule:
      interval: weekly

  - package-ecosystem: docker
    directory: /frontend
    schedule:
      interval: weekly
```

- [ ] **Step 2: Create `.github/CODEOWNERS`**

```
# Default owner — fill in your GitHub handle when known.
# Until then, this is a placeholder; PRs won't auto-request reviewers.
*       @TODO-owner-handle
```

- [ ] **Step 3: Run acceptance criteria locally**

This task closes out slice 1 by exercising every acceptance criterion from the spec. Run each, confirm expected output.

**3a.** Fresh-environment smoke (skip if already-built artifacts are fine):

```bash
docker compose down -v
docker compose up -d
sleep 60
docker compose exec backend php artisan migrate --force
```

**3b.** Backend health endpoint:

```bash
curl -s http://localhost:8000/api/v1/health
```

Expected: `{"ok":true,"version":"0.0.0"}`

**3c.** Frontend health endpoint:

```bash
curl -s http://localhost:3000/api/health
```

Expected: `{"ok":true}`

**3d.** Landing page returns 200 and contains the tagline:

```bash
curl -s http://localhost:3000/ | grep -q "Your Zen Sanctuary, Powered by Code" && echo OK
```

Expected: prints `OK`.

**3e.** OpenAPI spec contains `/health`:

```bash
grep -q '"/health"' backend/storage/api-docs/openapi.json && echo OK
```

Expected: prints `OK`.

**3f.** Run all linters and tests:

```bash
make lint
make test
```

Expected: all clean / all pass.

**3g.** Confirm regeneration produces no diff:

```bash
make swagger
make api-client
git status backend/storage/api-docs frontend/src/lib/api-client
```

Expected: `nothing to commit, working tree clean` for both paths.

**3h.** Reduced-motion check (visual): open Chrome DevTools → Rendering → "Emulate CSS media feature: prefers-reduced-motion: reduce" → reload `http://localhost:3000`. Expected: no transitions on hover/focus.

**3i.** Tear down:

```bash
docker compose down
```

- [ ] **Step 4: Commit**

```bash
git add .github/dependabot.yml .github/CODEOWNERS
git commit -m "chore: add dependabot and codeowners stubs"
```

- [ ] **Step 5: Final tag**

Mark slice 1 as complete:

```bash
git tag -a slice-1-foundations -m "Slice 1 — Foundations complete"
git log --oneline -20
```

Expected: ~18 commits since `6b19ee5`, ending with the slice-1 tag.

---

## What's intentionally NOT here

These items appear in later slices, per the spec's §2 "Out" list:

- **Deploy workflow** (`.github/workflows/deploy.yml`) → Slice 1.5
- **E2E workflow** (`.github/workflows/e2e.yml`) and Playwright → Slice 3
- **Coverage gates** (`--coverage --min=80`) → Slice 2
- **Auth / fish / background / repo-aquarium code** → Slices 2–6
- **`react-hook-form`, `zod`, `@tanstack/react-query`, `zustand`** → Slice 2
- **Sentry init code** → Slice 7 (polish)
- **`swagger-ui-react`, `/api-docs` page** → Slice 7
- **CODEOWNERS handle** → fill in when GitHub repo is created

If a later task feels like it belongs in slice 1, push back — slice 1 is foundations, not features.
