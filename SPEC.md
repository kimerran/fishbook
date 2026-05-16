# Fishbook — Full-Stack Application Specification

> A virtual aquarium web app. Users curate a school of customizable pet fishes that swim across a full-viewport canvas, feed them with clicks, customize the background (uploaded image or AI-generated), and even turn any GitHub repository into a living aquarium driven by repo stats.

- **Production domain:** `fishbook.neri.ph`
- **Repository layout:** monorepo with `frontend/` (Next.js) and `backend/` (Laravel) sibling directories.
- **Deployment target:** Railway (two services: `fishbook-frontend`, `fishbook-backend`, plus managed Postgres and a Redis plugin).

---

## 1. Routes & Pages (Next.js — App Router)

| Path | Page | Auth | Notes |
|---|---|---|---|
| `/` | Landing / public demo aquarium | Public | Hero, marketing copy, "Try a Demo Tank" CTA, login & register links. Shows a small read-only aquarium with a few default fish. |
| `/login` | Login | Public | Username + password form. Optional Google OAuth button. |
| `/register` | Register | Public | Username, email, password, password_confirmation. |
| `/fish` | **My Aquarium** | Authed | Full-viewport canvas. Click drops food. "Manage Fishes" button opens modal. Background settings panel (collapsible). |
| `/fish/settings` | Aquarium settings page | Authed | Manage backgrounds (upload / generate / select). Not strictly required if the modal in `/fish` covers it, but kept for deep-linking. |
| `/[username]/[repo]` | **GitHub Repo Aquarium** | Public (read-only) | Fetches repo stats from GitHub API, deterministically generates a fish set from those stats, renders animated aquarium. Authed users can "Fork to My Aquarium" to copy the generated set into their own tank. |
| `/api-docs` | Swagger UI | Public | Embeds backend OpenAPI spec (read-only viewer). |
| `/404`, `/500` | Error pages | Public | |

### Layout & Components (frontend)
- **`AquariumCanvas`** — full-viewport `<canvas>` (or WebGL via `@react-three/fiber` if 2D canvas is too limiting; the spec defaults to plain 2D Canvas API for predictability). Renders all fish each frame.
- **`Fish` (instance class, not component)** — internal renderable. Tracks position, velocity, target, size, color, breed sprite, nickname, hover state, eating state.
- **`FoodPellet`** — short-lived rendered entity created on `mousedown`. Sinks with gravity. Eaten on collision with a fish.
- **`BackgroundLayer`** — renders either the uploaded image, the AI-generated image, or a CSS gradient fallback. Sits behind the canvas.
- **`FishManagerModal`** — table-style modal with search input (debounced), filter (breed, color), sort (name, breed, created_at, size), and CRUD actions per row. Uses a paginated query against the backend.
- **`AddFishDialog`** — breed picker, color picker, size slider, nickname input.
- **`BackgroundPanel`** — upload tab + generate-from-prompt tab + library tab.
- **`HoverNameTooltip`** — floating label that follows the hovered fish.
- **`AuthLayout` / `AppLayout`** — shared layouts.
- **`useAquariumStore`** — Zustand store for client-side fish state, food pellets, background, hover target.
- **`useApiClient`** — wraps the generated OpenAPI client; injects bearer token from secure cookie / `HttpOnly` session.

### Fish Movement Behavior (client-side, deterministic enough for tests)
- Each fish has `position {x, y}`, `velocity {vx, vy}`, `target {x, y}`, `breed`, `size`, `color`.
- Every `1500–4500 ms` (jittered per-fish) the fish picks a new random target within the viewport (with 5% margin).
- Each frame, the fish steers toward its target with capped acceleration (`maxAccel`), capped speed (`maxSpeed` scales inversely with size), and a tiny sinusoidal vertical bob for realism.
- Fish flip horizontally based on `vx` sign.
- When food exists, the *nearest* food within `feedingRadius` becomes the target for the *closest* fish; on contact the food is consumed and a small "satisfied" animation plays (size bump + emit bubbles). Eating gives a `+1` to in-memory `hunger_satisfied` counter; this is *not* persisted server-side (food is purely a visual interaction).
- Hovering a fish (pointer-position inside its AABB) shows its nickname tooltip and pauses target re-selection for that fish until the pointer leaves.

---

## 2. Backend API (Laravel)

All endpoints under `/api/v1`. JSON in, JSON out. Auth via Laravel Sanctum (token in `Authorization: Bearer …`). CSRF disabled for the API guard; frontend uses the bearer token from the cookie-bound session.

### 2.1 Auth
| Method | Path | Auth | Body | Returns |
|---|---|---|---|---|
| POST | `/api/v1/auth/register` | Public | `{username, email, password, password_confirmation}` | `{user, token}` |
| POST | `/api/v1/auth/login` | Public | `{username, password}` | `{user, token}` |
| POST | `/api/v1/auth/logout` | Authed | — | `204` |
| GET | `/api/v1/auth/me` | Authed | — | `{user}` |
| GET | `/api/v1/auth/google/redirect` | Public | — | `302` to Google |
| GET | `/api/v1/auth/google/callback` | Public | `?code=…` | `{user, token}` |

Passwords are `bcrypt`ed via Laravel's hashing. Tokens are personal access tokens via Sanctum, scoped `fishbook`.

### 2.2 Fishes
| Method | Path | Auth | Notes |
|---|---|---|---|
| GET | `/api/v1/fishes` | Authed | Query params: `search`, `breed`, `color`, `sort` (`name|breed|created_at|size`), `direction` (`asc|desc`), `page`, `per_page` (max 100). Returns paginated list. |
| POST | `/api/v1/fishes` | Authed | Body: `{nickname, breed, color_hex, size}` |
| GET | `/api/v1/fishes/{id}` | Authed (owner) | |
| PATCH | `/api/v1/fishes/{id}` | Authed (owner) | Any subset of `{nickname, color_hex, size}`. `breed` is immutable. |
| DELETE | `/api/v1/fishes/{id}` | Authed (owner) | Soft delete. |
| GET | `/api/v1/fishes/breeds` | Public | Returns the static breed catalog (id, label, min/max size, default color, sprite key). |

### 2.3 Backgrounds
| Method | Path | Auth | Notes |
|---|---|---|---|
| GET | `/api/v1/backgrounds` | Authed | User's saved backgrounds. |
| POST | `/api/v1/backgrounds/upload` | Authed | `multipart/form-data`, field `image`. Validates: min `1280×720`, max `5 MB`, MIME `image/jpeg|png|webp`. Uploads to S3, returns signed URL. |
| POST | `/api/v1/backgrounds/generate` | Authed | Body: `{prompt: string (3–500 chars), aspect_ratio?: "16:9"|"3:2"|"1:1"}`. Calls Fal AI flux 2 turbo, stores the resulting image in S3, returns record. Rate-limited (see §9). |
| PATCH | `/api/v1/backgrounds/{id}/select` | Authed (owner) | Marks this background as the user's active background. |
| DELETE | `/api/v1/backgrounds/{id}` | Authed (owner) | Soft delete + S3 object removal queued. |

### 2.4 GitHub Repo Aquarium
| Method | Path | Auth | Notes |
|---|---|---|---|
| GET | `/api/v1/repos/{owner}/{repo}/aquarium` | Public | Returns `{stats, fish_set: Fish[]}`. Cached in Redis for 10 minutes per `(owner, repo)`. |
| POST | `/api/v1/repos/{owner}/{repo}/fork-to-my-aquarium` | Authed | Materializes the deterministic fish_set into the user's owned `fishes` rows. Returns `{added: int}`. |

### 2.5 Documentation
- `GET /api/v1/openapi.json` — OpenAPI 3.1 spec (generated from controllers/requests/resources via `darkaonline/l5-swagger` + `zircote/swagger-php` PHPDoc annotations).
- `GET /api-docs` (frontend) — embeds Swagger UI and points at `/api/v1/openapi.json`.
- Client SDK generation: documented in `README.md` using `openapi-generator-cli`, e.g.
  ```
  openapi-generator-cli generate -i https://fishbook.neri.ph/api/v1/openapi.json -g typescript-fetch -o frontend/src/lib/api-client
  ```

### 2.6 Cross-cutting
- All responses use Laravel API Resources (`FishResource`, `BackgroundResource`, `UserResource`) for shape consistency.
- Pagination follows Laravel's standard `data / links / meta` envelope.
- All write endpoints validate via `FormRequest` classes.
- Errors follow `{message, errors?: {field: string[]}}` with appropriate HTTP status.
- Rate limits: `throttle:auth` (5/min) on login/register, `throttle:generate` (10/hour) on AI generation, `throttle:api` (60/min) elsewhere — all configured in `RouteServiceProvider`.

---

## 3. Database Schema (PostgreSQL via Eloquent)

All tables use `bigserial` PKs, `timestamps`, and `softDeletes` where noted.

### `users`
| Column | Type | Notes |
|---|---|---|
| id | bigserial PK | |
| username | citext UNIQUE NOT NULL | case-insensitive |
| email | citext UNIQUE NOT NULL | |
| password | varchar(255) NOT NULL | bcrypt hash; nullable only if `google_id` present and no local password set |
| google_id | varchar(64) UNIQUE NULL | for Google OAuth |
| is_admin | boolean NOT NULL DEFAULT false | |
| email_verified_at | timestamp NULL | |
| remember_token | varchar(100) NULL | |
| created_at / updated_at | timestamp | |

Seeded admin: `username=admin`, password from `ADMIN_SEED_PASSWORD` env (required to be non-empty in production; seeder fails closed otherwise).

### `fishes` (soft-delete)
| Column | Type | Notes |
|---|---|---|
| id | bigserial PK | |
| user_id | bigint FK → users.id (ON DELETE CASCADE) | indexed |
| nickname | varchar(40) NOT NULL | trimmed; 1–40 chars |
| breed | varchar(40) NOT NULL | enum-validated server-side against breed catalog |
| color_hex | char(7) NOT NULL | `#RRGGBB` |
| size | smallint NOT NULL | 1–100 scale, validated per-breed min/max |
| source | varchar(20) NOT NULL DEFAULT 'manual' | `manual` \| `github_repo` |
| source_ref | varchar(255) NULL | e.g. `vercel/next.js` if `source=github_repo` |
| created_at / updated_at / deleted_at | timestamp | |

Indexes: `(user_id, deleted_at)`, `(user_id, breed)`, `(user_id, created_at)`.

### `backgrounds` (soft-delete)
| Column | Type | Notes |
|---|---|---|
| id | bigserial PK | |
| user_id | bigint FK → users.id (ON DELETE CASCADE) | indexed |
| kind | varchar(16) NOT NULL | `upload` \| `generated` \| `preset` |
| storage_key | varchar(255) NOT NULL | S3 object key |
| width | integer NOT NULL | |
| height | integer NOT NULL | |
| prompt | text NULL | if `kind=generated` |
| is_active | boolean NOT NULL DEFAULT false | only one active per user (partial unique index) |
| created_at / updated_at / deleted_at | timestamp | |

Partial unique index: `CREATE UNIQUE INDEX one_active_bg_per_user ON backgrounds(user_id) WHERE is_active = true AND deleted_at IS NULL;`

### `repo_aquarium_cache`
| Column | Type | Notes |
|---|---|---|
| id | bigserial PK | |
| owner | varchar(100) NOT NULL | |
| repo | varchar(100) NOT NULL | |
| stats_json | jsonb NOT NULL | raw subset of GitHub API response |
| fish_set_json | jsonb NOT NULL | deterministically derived fish list |
| fetched_at | timestamp NOT NULL | |

Unique index `(owner, repo)`. Used as a durable second-tier cache behind Redis.

### `personal_access_tokens` (Laravel Sanctum default).
### `failed_jobs`, `jobs`, `cache`, `sessions` — standard Laravel tables.

---

## 4. Fish Breed Catalog (Initial Small-Fish Set)

These are configured in `backend/config/fish_breeds.php` and served via `GET /api/v1/fishes/breeds`. Sprites live in `frontend/public/sprites/fish/<breed>.svg` (one SVG per breed, recolorable via `currentColor`).

| Breed ID | Display Name | Size Range | Default Color | Notes |
|---|---|---|---|---|
| `guppy` | Guppy | 8–18 | `#FF6B9D` | Highly customizable color. |
| `molly` | Molly | 12–22 | `#1F2937` | Slightly larger than guppy. |
| `neon_tetra` | Neon Tetra | 6–12 | `#3B82F6` | Schools well; renders faster in groups. |
| `zebra_danio` | Zebra Danio | 8–14 | `#9CA3AF` | Striped overlay on color. |
| `platy` | Platy | 10–18 | `#F59E0B` | Round body. |
| `endler` | Endler's Livebearer | 5–10 | `#10B981` | The smallest. |
| `cherry_barb` | Cherry Barb | 10–16 | `#DC2626` | |
| `white_cloud_minnow` | White Cloud Minnow | 7–13 | `#E5E7EB` | |
| `otocinclus` | Otocinclus | 6–10 | `#6B7280` | Bottom-leaning; lower vertical band preference. |
| `cory_catfish` | Cory Catfish | 12–20 | `#78716C` | Bottom dweller; stays in lower 30% of viewport. |

`size` on a fish is clamped to the breed's range server-side.

---

## 5. GitHub → Aquarium Mapping

Stats fetched from `https://api.github.com/repos/{owner}/{repo}` (unauthenticated public reads, with optional `GITHUB_TOKEN` for higher rate limits). One additional call to `/contributors?per_page=1` (read `Link` header `last` page) for contributor count.

The mapping is **deterministic** for `(owner, repo, stats_snapshot)` so the same repo always renders the same fish — colors and positions are derived from a seeded PRNG with `seed = hash(owner + '/' + repo)`.

### Stats consumed
- `stargazers_count` → primary driver of fish count and rare breeds
- `forks_count` → schools of small fish
- `open_issues_count` → cleaner fish (Otocinclus)
- `subscribers_count` (watchers) → mid-tier fish
- `contributors_count` → distinct-colored Guppies
- `language` → color theme accent applied to a portion of the fish
- `age_days = now - created_at` → biases the Cory Catfish (the "elder" fish)

### Non-linear scaling (logarithmic tiers)

To prevent the count from blowing up on popular repos, every stat is bucketed via a tier table. Pseudocode:

```
def tier(value, breakpoints):
    # breakpoints = [10, 50, 200, 1000, 5000, 20000, 100000]
    # returns 0..len(breakpoints)
    for i, bp in enumerate(breakpoints):
        if value < bp: return i
    return len(breakpoints)
```

| Stat | Breakpoints | Output |
|---|---|---|
| stars | [10, 50, 200, 1000, 5000, 20000, 100000] | tier 0..7 |
| forks | [5, 25, 100, 500, 2500, 10000] | tier 0..6 |
| issues | [1, 10, 50, 200, 1000] | tier 0..5 |
| watchers | [5, 20, 100, 500, 2500] | tier 0..5 |
| contributors | [1, 5, 20, 100, 500] | tier 0..5 |

### Fish allocation table (per tier)

| Source | Tier 0 | 1 | 2 | 3 | 4 | 5 | 6 | 7 |
|---|---|---|---|---|---|---|---|---|
| **stars → guppy** | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 |
| **stars → neon_tetra (school)** | 0 | 0 | 3 | 5 | 7 | 9 | 11 | 13 |
| **stars → molly (rare)** | 0 | 0 | 0 | 1 | 2 | 3 | 4 | 5 |
| **stars → cherry_barb (very rare)** | 0 | 0 | 0 | 0 | 1 | 2 | 3 | 4 |
| **forks → zebra_danio** | 0 | 1 | 2 | 3 | 4 | 5 | 6 | — |
| **issues → otocinclus** | 0 | 1 | 2 | 3 | 4 | 5 | — | — |
| **watchers → platy** | 0 | 1 | 2 | 3 | 4 | 5 | — | — |
| **contributors → endler** | 0 | min(N, 3) | min(N, 6) | min(N, 10) | min(N, 15) | min(N, 20) | — | — |
| **age → cory_catfish** | 0 if age<180d else 1 if <2y else 2 if <5y else 3 | | | | | | | |

Total is naturally capped (worst case ≈ 65 fish for a `>100k-star, >10k-fork, >1k-issue, >2.5k-watcher, >500-contrib, >5y` mega-repo), which is renderable.

### Color derivation
- Each fish gets `base_color = breed.default_color`.
- A `language_color` is looked up from a static map (the one [github-linguist](https://github.com/ozh/github-colors) publishes — vendored as a JSON file). 30% of the fish (selected by the seeded PRNG) blend toward `language_color` at 50% alpha.
- Contributors-derived Endlers each get a hue rotation derived from a stable hash of their login (fetched via `/contributors?per_page=20`).

### Determinism contract
Same `(owner, repo, stats)` → identical `fish_set_json`. The cache key is `repo:{owner}/{repo}:v1`. The version suffix bumps when the algorithm changes.

---

## 6. Background Customization

### Upload
- Client validates min `1280×720`, max `5 MB`, JPEG/PNG/WebP before upload.
- Backend re-validates with Intervention Image:
  - Reads dimensions; rejects below `1280×720` with `422`.
  - Rejects MIMEs not in allow-list.
  - Strips EXIF, re-encodes to WebP at quality 85, max long edge 2560 px.
  - Stores at `backgrounds/u{user_id}/{ulid}.webp` in S3.

### Generate via Fal AI flux 2 turbo
- Endpoint calls Fal AI's REST API (`https://fal.run/fal-ai/flux-2/turbo`) with `prompt`, `image_size` derived from `aspect_ratio`, and `num_images: 1`.
- Server-side prompt validation: 3–500 chars, run through a basic content filter (`Str::contains` blocklist + a `BadWords` regex) before forwarding. Reject obvious NSFW / disallowed terms with `422`.
- Response image URL is fetched server-side, re-encoded (same pipeline as upload), and stored in S3. The Fal request id and prompt are stored on the `backgrounds` row for audit.
- Rate limit: 10 generations per user per hour, 100 per day, 500 per month (in-memory counter in Redis via `rate-limiter`).
- Cost guard: a hard global daily ceiling (env `FAL_DAILY_GLOBAL_LIMIT`, default 200) protects against bill blow-ups; exceeding it returns `503` with `Retry-After`.

### Selection
- Exactly one `is_active = true` per user; `PATCH /backgrounds/{id}/select` flips inside a transaction.

---

## 7. Authentication

### Local (username + password)
- Registration: username `^[a-zA-Z0-9_]{3,32}$`, email RFC-valid, password ≥ 10 chars with `zxcvbn`-style strength check via `bjeavons/zxcvbn-php` (score ≥ 2 required), `password_confirmation` matches.
- Login: throttled 5/min/IP and 5/min/username. Generic error message ("Invalid credentials") to prevent enumeration.
- Tokens issued via Sanctum. Frontend stores token in an `HttpOnly`, `Secure`, `SameSite=Lax` cookie set by a tiny Next.js route handler (`/api/auth/set-cookie`). Client never reads the token from JS — `useApiClient` calls a Next.js proxy route that injects the cookie's token into the outgoing `Authorization` header.

### Google OAuth (optional, behind `GOOGLE_OAUTH_ENABLED` env)
- Laravel Socialite (`laravel/socialite`).
- On callback, match by `google_id` → fall back to matching by verified email → otherwise create new user with `username = sluggify(name) + random4`, `password = null`.
- New user must claim a username on first login (interstitial page `/onboarding/username`).

### Authorization
- Policies for `Fish` and `Background`: only owner can read/write/delete. Admin (`is_admin = true`) can read all but writes are still owner-restricted, except an admin-only `DELETE /api/v1/admin/users/{id}` (for moderation; out of scope for v1 UI but the policy hooks are in place).

### Admin seed
- Database seeder creates one user: `username=admin`, `email=admin@fishbook.local`, `password=<ADMIN_SEED_PASSWORD env>`, `is_admin=true`.
- Seeder **must fail** if `APP_ENV=production` and `ADMIN_SEED_PASSWORD` is empty, the default `password`, or shorter than 12 chars.

---

## 8. File Storage (S3-Compatible)

- Production: any S3-compatible provider (AWS S3, Cloudflare R2, Backblaze B2). Configured via standard Laravel `filesystems.s3` driver.
- Local dev: MinIO (see Docker compose §13).
- Bucket layout:
  - `backgrounds/u{user_id}/{ulid}.webp`
  - `sprites/…` (read-only, deployed alongside frontend assets, **not** in S3 — kept here only for reference).
- Public access pattern: backgrounds are served via short-lived signed URLs (TTL 1 hour) issued by the backend in `BackgroundResource`. No public bucket policy.
- Cleanup: a daily Laravel scheduled job removes S3 objects for backgrounds soft-deleted more than 7 days ago.

---

## 9. Third-Party Services

| Service | Purpose | Env vars |
|---|---|---|
| Fal AI (`flux-2/turbo`) | Background generation | `FAL_API_KEY`, `FAL_DAILY_GLOBAL_LIMIT` |
| GitHub REST API | Repo stats for `/{user}/{repo}` | `GITHUB_TOKEN` (optional, for higher rate limit) |
| Google OAuth | Optional social login | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`, `GOOGLE_OAUTH_ENABLED` |
| S3-compatible storage | File storage | `AWS_*` standard set, `AWS_ENDPOINT` (for MinIO/R2), `AWS_USE_PATH_STYLE_ENDPOINT=true` for MinIO |
| Railway | Hosting | platform-injected |
| Sentry (recommended) | Error tracking | `SENTRY_DSN_FRONTEND`, `SENTRY_DSN_BACKEND` |

Rate-limit & timeout policy: every outbound HTTP call uses Guzzle with `connect_timeout=5s`, `timeout=30s` (Fal AI: 60s), retries 2x on 5xx/timeouts with exponential backoff.

---

## 10. Configuration & Environment Variables

### `backend/.env.example`
```
APP_NAME=Fishbook
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8000
APP_TIMEZONE=UTC

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

### `frontend/.env.example`
```
# Public (exposed to browser via NEXT_PUBLIC_ prefix)
NEXT_PUBLIC_APP_URL=http://localhost:3000
NEXT_PUBLIC_API_BASE_URL=http://localhost:8000/api/v1

# Server-only (used in route handlers / server actions)
BACKEND_INTERNAL_URL=http://backend:8000/api/v1
SESSION_COOKIE_NAME=fishbook_session
SESSION_COOKIE_SECRET=change-me-32-bytes-min

NEXT_PUBLIC_SENTRY_DSN=
NEXT_PUBLIC_GOOGLE_OAUTH_ENABLED=false
```

---

## 11. Development Setup (Docker Compose)

`docker-compose.yml` at repo root:

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

  redis:
    image: redis:7-alpine
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]

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
    build: ./backend
    depends_on:
      db: { condition: service_healthy }
      redis: { condition: service_healthy }
      minio: { condition: service_started }
    env_file: ./backend/.env
    ports: ["8000:8000"]
    volumes: ["./backend:/var/www/html"]
    command: php artisan serve --host=0.0.0.0 --port=8000

  frontend:
    build: ./frontend
    depends_on: [backend]
    env_file: ./frontend/.env
    ports: ["3000:3000"]
    volumes:
      - ./frontend:/app
      - /app/node_modules
    command: npm run dev

volumes:
  pgdata: {}
  miniodata: {}
```

`make` targets (optional but recommended): `make up`, `make down`, `make migrate`, `make seed`, `make test`, `make lint`.

---

## 12. Testing

### Backend (PHPUnit / Pest)
- Feature tests for every endpoint (happy path + auth failures + validation failures).
- Unit tests for:
  - `RepoAquariumGenerator` (deterministic seeded output, tier boundaries).
  - `BackgroundImageProcessor` (dimension check, WebP re-encode, EXIF strip).
  - `FalAiClient` (mocked Guzzle handler).
  - `GithubStatsClient` (mocked Guzzle handler, pagination math for contributors).
- Coverage gate: `≥ 80%` on `app/Services/` and `app/Http/Controllers/`.

### Frontend (Vitest + React Testing Library)
- Unit tests for `Fish` class (steering math, AABB hover detection, eating collision).
- Unit tests for `useAquariumStore` actions.
- Component tests for `FishManagerModal` (search debounce, sort, delete confirm flow), `AddFishDialog`, `BackgroundPanel`.
- Integration tests (mocked API client) for `/fish` and `/{owner}/{repo}` page flows.
- Playwright smoke test (CI-only, against the `docker compose` stack): register → login → add fish → see it in the canvas → open manager → delete it.

### CI gates
- All tests pass.
- `phpstan`/`larastan` level 6 clean.
- `eslint`, `tsc --noEmit`, `prettier --check` clean.
- Build succeeds for both services.

---

## 13. CI/CD — GitHub Actions

Workflows in `.github/workflows/`:

### `backend.yml`
- Triggers: PR + push to `main`, paths `backend/**` or workflow itself.
- Matrix: PHP 8.3.
- Steps:
  1. Checkout
  2. Setup PHP, Composer cache
  3. `composer install --prefer-dist --no-progress`
  4. Spin up Postgres + Redis services
  5. `cp .env.example .env`, `php artisan key:generate`
  6. `php artisan migrate --force`
  7. `vendor/bin/phpstan analyse`
  8. `vendor/bin/pint --test` (Laravel Pint for code style)
  9. `php artisan test --coverage --min=80`

### `frontend.yml`
- Triggers: PR + push to `main`, paths `frontend/**` or workflow itself.
- Node 20 (LTS).
- Steps:
  1. Checkout
  2. Setup Node, `npm ci`
  3. `npm run lint`
  4. `npm run typecheck` (tsc --noEmit)
  5. `npm run test -- --coverage`
  6. `npm run build`

### `e2e.yml`
- Triggers: PR + push to `main`.
- Boots `docker compose up -d`, waits for healthchecks, runs Playwright.

### `deploy.yml`
- Triggers: push to `main` (after CI passes).
- Uses Railway's CLI / GitHub integration to deploy. Two services updated independently based on changed paths.

---

## 14. Deployment (Railway)

- **`fishbook-backend` service:** PHP 8.3, runs `php-fpm` + `nginx` (via the standard Laravel-on-Railway Nixpacks config or a `Dockerfile`). Workers: 1 web + 1 queue worker (`php artisan queue:work --sleep=3 --tries=3 --max-time=3600`).
- **`fishbook-frontend` service:** Node 20, `npm run build && npm run start`.
- **Postgres:** Railway managed Postgres plugin. Connection string injected via `DATABASE_URL`; Laravel parses it in `config/database.php`.
- **Redis:** Railway managed Redis plugin.
- **Object storage:** Cloudflare R2 (S3-compatible) recommended in production; configured via the same `AWS_*` env vars.
- **Domains:** `fishbook.neri.ph` → frontend service. `api.fishbook.neri.ph` → backend service. Frontend's `NEXT_PUBLIC_API_BASE_URL` points at `https://api.fishbook.neri.ph/api/v1`.
- **TLS:** Railway-managed certificates.
- **Health checks:** `GET /up` (Laravel default), `GET /api/health` (frontend route returns 200).
- **Migrations:** Run on deploy via a `release` phase: `php artisan migrate --force && php artisan db:seed --class=ProductionSeeder --force`.

---

## 15. OpenAPI / Swagger

- Spec lives at `backend/storage/api-docs/openapi.json`, regenerated from PHP annotations via `php artisan l5-swagger:generate` (also wired into CI to ensure it's committed up-to-date — CI fails on diff).
- Spec served at `GET /api/v1/openapi.json` (no auth).
- Frontend `/api-docs` page embeds Swagger UI (via `swagger-ui-react` or static iframe).
- Client generation instructions (in README): `openapi-generator-cli generate -i https://api.fishbook.neri.ph/api/v1/openapi.json -g typescript-fetch -o frontend/src/lib/api-client`. The generated client is *committed* to keep builds deterministic; a CI step verifies it's in sync with the spec.

---

## 16. Security Checklist (must be satisfied before v1)

- [ ] All inputs validated server-side (FormRequest); no trusting client.
- [ ] Bcrypt for passwords with cost ≥ 12.
- [ ] Sanctum tokens 64+ random bytes; rotated on password change.
- [ ] HTTPS enforced (`URL::forceScheme('https')` in production).
- [ ] CSRF: not needed for token-auth API, but `SameSite=Lax` cookie + bearer token from server-side proxy mitigates token theft from XSS.
- [ ] XSS: all user-provided strings (nicknames, prompts) escaped on render; sprites are static SVGs, not user-uploaded.
- [ ] SQL injection: Eloquent / parameter binding only — no raw queries with concatenation.
- [ ] File upload validation server-side (MIME sniff via `Intervention\Image`, not just extension); EXIF stripped.
- [ ] LLM prompt: server-side blocklist; prompts logged for audit only, not exposed in public endpoints.
- [ ] GitHub repo endpoint sanitizes `owner` and `repo` against `^[A-Za-z0-9._-]{1,100}$` before forwarding.
- [ ] Rate limits on auth, generation, and general API (see §2.6).
- [ ] CORS: allow only `FRONTEND_URL` origin.
- [ ] Security headers: `Strict-Transport-Security`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-Frame-Options: DENY`, CSP (`default-src 'self'; img-src 'self' https://*.r2.cloudflarestorage.com data:; connect-src 'self' https://api.fishbook.neri.ph`).
- [ ] Dependabot / Renovate enabled.
- [ ] Secrets only in Railway env, never in repo. `.env` in `.gitignore`. `.env.example` committed.
- [ ] Admin seeder fails closed in production if `ADMIN_SEED_PASSWORD` is missing or weak.
- [ ] Soft-delete + 7-day grace before S3 purge gives an "undo" window.
- [ ] No PII beyond email; logs scrub `password`, `token`, `Authorization` headers.

---

## 17. Acceptance Criteria (v1)

1. A new user can register, log in, see an empty aquarium at `/fish`.
2. The user can add a Guppy or Molly with a chosen color, size, and nickname; the fish appears on the canvas immediately and swims around.
3. Hovering a fish shows its nickname.
4. Clicking the canvas drops food; the nearest fish swims to it and eats it.
5. The user can open the Manage Fishes modal and search, filter by breed, sort by name, edit a nickname, and delete a fish.
6. The user can upload a 1280×720+ background; rejecting a smaller image shows a clear error.
7. The user can generate a background by entering a prompt; the result is saved and immediately set as active.
8. Visiting `/vercel/next.js` renders an aquarium with a stable, deterministic fish set derived from current repo stats, with no auth required.
9. A logged-in user on a repo aquarium page can click "Fork to My Aquarium" and have all those fish added to their account.
10. Swagger UI at `/api-docs` is reachable and renders the full spec.
11. CI is green on `main`; `npm run build` and `php artisan optimize` both succeed.
12. All items in §16 are checked off.
