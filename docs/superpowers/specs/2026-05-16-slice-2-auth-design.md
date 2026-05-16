# Fishbook — Slice 2: Auth (Design)

**Date:** 2026-05-16
**Slice:** 2 of 7 (Authentication)
**Status:** Approved — ready for implementation plan
**Depends on:** Slice 1 (Foundations) — `slice-1-foundations` tag.

Behaviour governed by [`SPEC.md`](../../../SPEC.md) §1, §2.1, §3, §7, §9, §10, §12. Engineering practice by [`AGENT.md`](../../../AGENT.md) §3, §4, §5. Visual language by [`BRAND.md`](../../../BRAND.md). When this file is ambiguous, those win.

---

## 1. Context

Slice 1 left us with a working monorepo, brand-styled landing page, OpenAPI round-trip on a single `GET /api/v1/health` endpoint, and CI green. The backend has Sanctum installed but no auth routes; the frontend ships only the public landing page; the `users` table is still the Laravel default (name/email/password — no `username`, no `google_id`, no citext).

Slice 2 makes authentication work end-to-end. After this slice:

- A new visitor can register, log in, see `/api/v1/auth/me`, and log out.
- Optional Google OAuth is wired behind `GOOGLE_OAUTH_ENABLED`; first-time Google users go through `/onboarding/username`.
- The frontend's `/login`, `/register`, and `/onboarding/username` pages are real, brand-styled, and route-protected pages.
- The Sanctum bearer token never touches browser JS. It lives in an `HttpOnly Secure SameSite=Lax` cookie set by a Next.js route handler; all browser API calls go through a Next.js proxy that injects the `Authorization` header server-side.
- Coverage gates are reinstated: ≥80% on `app/Services/` and `app/Http/Controllers/`; ≥70% statement on the frontend.

It deliberately stops there. Fish, backgrounds, repo-aquarium, Playwright, Sentry init, and the `/api-docs` Swagger UI page are all later slices.

---

## 2. Scope

### In

**Backend**
- Migration rewriting `users`: `citext` extension; `username` citext unique; `email` citext unique; `password` nullable (so OAuth-only users are legal); `google_id` varchar(64) nullable + unique; `is_admin` boolean; `email_verified_at` nullable; keep `remember_token` + timestamps. Drops `name`; drops the default `password_reset_tokens` table (deferred — no password reset in v1).
- `UserFactory` updated for the new shape.
- `User` model with `$fillable`, `$hidden`, casts (`password => 'hashed'`, `is_admin => 'boolean'`), and Sanctum's `HasApiTokens` trait.
- `UserResource` (named OpenAPI schema `UserResource`).
- `RegisterRequest` (FormRequest) — username regex `^[A-Za-z0-9_]{3,32}$`, RFC email, `password` ≥ 10 chars + `password_confirmation` + zxcvbn ≥ 2 (via `bjeavons/zxcvbn-php`).
- `LoginRequest` (FormRequest) — required `username`, required `password`.
- `App\Services\Auth\AuthService` — registers users, verifies credentials, issues tokens. Constructor-injected (`Hasher`, `Zxcvbn`). Testable.
- `App\Services\Auth\GoogleOAuthService` — wraps Socialite's `User` resolution into the application user (match-by-google_id → match-by-verified-email → create new).
- `AuthController` with `register`, `login`, `logout`, `me`. Thin — delegates to `AuthService`.
- `GoogleAuthController` with `redirect`, `callback`. Thin — delegates to `GoogleOAuthService`.
- Rate limiters via `RateLimiter::for(...)` in `AppServiceProvider::boot()`: `auth` = 5/min per IP **and** per username (combined key). Routes use `throttle:auth`.
- `routes/api.php`: the six auth routes from SPEC §2.1, plus a stub `Route::middleware('auth:sanctum')` group ready to host future endpoints.
- `AdminUserSeeder` — creates the admin user; **fails closed** in production (throws) if `ADMIN_SEED_PASSWORD` is empty, shorter than 12 chars, or in the denylist `['password','admin','changeme','12345678']`. Wired into `DatabaseSeeder`.
- `Policies\FishPolicy` and `Policies\BackgroundPolicy` — **stubs** (every method returns `false` except `viewAny`/`view` which return `true` to keep tests compilable in later slices). Registered in `AuthServiceProvider` (or its `register()` equivalent in Laravel 13's streamlined skeleton). Slice 3+ fills them in.
- OpenAPI annotations on every endpoint using **named schemas** (`#[OA\Schema(schema: 'UserResource', ...)]`, `#[OA\Schema(schema: 'AuthTokenResponse', ...)]`, `#[OA\Schema(schema: 'RegisterRequest', ...)]`, `#[OA\Schema(schema: 'LoginRequest', ...)]`, `#[OA\Schema(schema: 'ValidationError', ...)]`). Regenerated `storage/api-docs/openapi.json` committed.
- Pest feature tests for every endpoint: happy path, validation failure, auth failure (unauthed), rate-limit hit. Unit test for `AdminUserSeeder` (fails-closed conditions). Unit test for `AuthService::register` (hashing + weak-password rejection).
- `backend.yml` CI gains `--coverage --min=80` *scoped* to `app/Services/` and `app/Http/Controllers/` via PHPUnit's `<coverage>` includes.

**Frontend**
- New deps: `@tanstack/react-query`, `zustand`, `react-hook-form`, `zod`, `@hookform/resolvers`, `iron-session` (for signed/encrypted cookies so middleware can validate without a backend round-trip).
- Three Next.js route handlers:
  - `POST /api/auth/set-cookie` — body `{token, user}`; sets the iron-session encrypted cookie (HttpOnly, Secure in prod, SameSite=Lax).
  - `POST /api/auth/clear-cookie` — destroys the cookie.
  - `ALL /api/proxy/[...path]` — reads token from cookie, attaches `Authorization: Bearer <token>`, forwards method/body/query to `BACKEND_INTERNAL_URL`. Streams response back.
- `useApiClient` hook — wraps the generated `typescript-fetch` client, configured to call `/api/proxy/...` (relative URL — runs in browser) instead of the backend directly. Inject token at zero points: the proxy does it.
- `<QueryProvider>` `'use client'` boundary mounting `QueryClientProvider`, placed in root layout (or in an `(authed)` layout — we use root for simplicity since `/login` itself uses queries for the mutation).
- Zustand `useAuthStore` — `{user, isLoading, set, clear}`. **Not** persisted to localStorage. Hydrated by a `<AuthHydrator>` client component that on mount fires `useQuery('me')` against the proxy; on success populates the store; on 401 clears it.
- Pages:
  - `/login` — react-hook-form + zod; Google button rendered iff `process.env.NEXT_PUBLIC_GOOGLE_OAUTH_ENABLED === 'true'`. On success: POST `/api/auth/set-cookie`, then `router.push('/fish')` (or `/onboarding/username` if `username` is null — should not happen on local login, but the check is cheap).
  - `/register` — same pattern; on success same redirect.
  - `/onboarding/username` — authed-only; lets a freshly created Google user claim a real username. Patches via `POST /api/v1/auth/me/claim-username`. **NOTE:** the SPEC doesn't define this endpoint explicitly; we add it as a fifth auth endpoint (`POST /api/v1/auth/claim-username`, authed, body `{username}`, validates the same regex + uniqueness). Documented as part of slice 2.
- Brand-styled forms using `BRAND.md` glass surfaces (`glass-md` panel) and Sage primary buttons.
- `middleware.ts` — runs on `/fish*` and `/onboarding*`. Reads the iron-session cookie; if absent → redirect to `/login`. Doesn't hit the backend.
- Vitest component tests: `RegisterForm` (renders, submits valid, shows zod errors, submits invalid → no API call), `LoginForm` (same), `useAuthStore` (set/clear), proxy route handler (unit-tested via mocked `fetch`).
- Regenerated `frontend/src/lib/api-client/` committed.
- `frontend.yml` CI gains `--coverage` and a `--coverage.thresholds.statements=70` Vitest flag.

**Both**
- `backend/.env.example` already covers the auth-relevant vars from SPEC §10; verify only.
- `frontend/.env.example` already covers `SESSION_COOKIE_NAME`, `SESSION_COOKIE_SECRET`, `NEXT_PUBLIC_GOOGLE_OAUTH_ENABLED`. Verify and document min-length on `SESSION_COOKIE_SECRET` (≥ 32 bytes — iron-session refuses shorter).

### Out (deferred)

- Fish, Background, repo-aquarium endpoints + pages → **Slice 3+**
- Sentry init code (DSNs already stubbed) → **Slice 7**
- `swagger-ui-react` and `/api-docs` page → **Slice 7**
- Playwright auth E2E (`register → login → see /fish`) → **Slice 3** (first slice with a real authed surface)
- Password reset & email verification flows — **not in v1 SPEC**, defer indefinitely
- Admin moderation UI (`DELETE /api/v1/admin/users/{id}` from SPEC §7) — out of v1 UI scope; only the policy hook lives here as a stub
- Sliding-token expiration / device-list management

---

## 3. Approach Decisions

These are the load-bearing judgment calls; record them so future slices don't relitigate.

1. **iron-session for the cookie**, not Laravel-issued cookies. The token lives in the Next.js process; the cookie is *not* a Sanctum cookie. Reason: middleware needs to decide auth without hitting backend, and an encrypted/signed iron-session cookie is decryptable in Edge runtime with no DB. Sanctum's cookie-based stateful auth is for first-party SPA + same-origin; our frontend and backend are deliberately decoupled (Railway will give them different subdomains).
2. **Proxy pattern over CORS+credentials**. Even though the SPEC's CORS config (`supports_credentials: true`) would allow a direct fetch from the browser with a cookie, the proxy gives us (a) the cookie never leaves Next.js's origin, (b) request/response can be logged/observed in one place, (c) the token never reaches browser JS at all — closing the XSS theft vector completely. SPEC §7 mandates this.
3. **Server-cache (TanStack Query) vs UI-state (zustand) split.** TanStack Query owns the `/api/v1/auth/me` server state — staleness, retries, dedup. Zustand owns derived UI state: a single `user` reference for synchronous reads in components that don't want a hook (e.g. layout header). The store is *populated by the query*, never the source of truth.
4. **Named OpenAPI schemas everywhere.** Slice 1 used inline `JsonContent` which generated hash-named TS models (`InlineObject1` etc.). Lesson learned: in slice 2 onward, every response/request body uses a `#[OA\Schema(schema: 'Name')]` annotation so the generated client has clean filenames like `UserResource.ts`, `AuthTokenResponse.ts`. The slice 2 health endpoint stays as-is (inline) to avoid a no-op refactor commit; new endpoints all use named schemas.
5. **citext requires real Postgres for tests.** SQLite (which Laravel often defaults to for testing) has no citext. The CI workflow already spins up Postgres 17. Locally, tests run against the docker-compose `db` service via `php artisan test` inside the container — *not* via the host's Pest binary against an in-memory SQLite. The plan documents this constraint and points users at `docker compose exec backend ./vendor/bin/pest`.
6. **`AppServiceProvider::boot()` for rate limiters.** Laravel 13's streamlined skeleton has no `RouteServiceProvider`. The `RateLimiter::for()` calls move to `AppServiceProvider::boot()`. The `throttle:auth` middleware alias is registered the same way (`Route::aliasMiddleware` is auto in L13 — no action needed beyond defining the limiter).
7. **Combined IP + username throttle key.** The SPEC says "5/min per IP **and** per username." We interpret "and" as a single limiter keyed by `ip|username` (failing if *either* dimension is exceeded would be two limiters). The cleanest reading: a key like `$request->ip().':'.($request->input('username') ?? 'anon')` already deduplicates per (IP, username) pair — exactly what we want.
8. **Claim-username endpoint added.** The onboarding flow needs a server hook. Adding `POST /api/v1/auth/claim-username` (authed) keeps the OAuth flow honest. It's a small, contained addition consistent with SPEC §7 ("New user must claim a username on first login").
9. **Generic login error.** Both wrong-password and unknown-username return 422 with the *same* message: "Invalid username or password." (SPEC §7 + AGENT.md §4.) This is asserted in tests.
10. **No JWT.** Sanctum tokens only. (AGENT.md §4.)
11. **Frontend forms don't dispatch on browser without TanStack Query.** Even the login submit goes through `useMutation` so retry/loading state is centralized. The mutation calls the generated client (which calls the proxy) — no naked `fetch()` anywhere in components.

---

## 4. API Surface

All paths are under `/api/v1`. JSON in, JSON out. Errors follow `{message, errors?: {field: string[]}}` per SPEC §2.6.

### 4.1 `POST /auth/register` (public, throttled `auth`)

Request body (`RegisterRequest`):
```json
{
  "username": "kimerran",
  "email": "user@example.com",
  "password": "super-secret-123",
  "password_confirmation": "super-secret-123"
}
```
- `username`: required, string, regex `^[A-Za-z0-9_]{3,32}$`, citext-unique.
- `email`: required, RFC email, citext-unique.
- `password`: required, string, min 10, confirmed, zxcvbn ≥ 2.
- `password_confirmation`: required, string.

Response 201 (`AuthTokenResponse`):
```json
{
  "user": { /* UserResource */ },
  "token": "1|aBcDeFg..."
}
```

Failure shapes:
- 422 with `errors` object on validation failure.
- 429 on rate limit.

### 4.2 `POST /auth/login` (public, throttled `auth`)

Request body (`LoginRequest`):
```json
{ "username": "kimerran", "password": "..." }
```

Response 200 (`AuthTokenResponse`).

Failure shapes:
- 422 with `errors: {credentials: ["Invalid username or password."]}` for *any* credential mismatch (unknown user, wrong password). Same response shape, same status — no enumeration.
- 429 on rate limit.

### 4.3 `POST /auth/logout` (authed)

Revokes the current personal access token. Returns 204 No Content.

### 4.4 `GET /auth/me` (authed)

Returns 200 with `{ user: UserResource }`. 401 if unauthed (Sanctum's default).

### 4.5 `POST /auth/claim-username` (authed)

Body `{ username }`. Validates the same regex + uniqueness. Updates the row. Returns `{ user: UserResource }`. Returns 422 if user already has a non-default username (idempotency choice; slice 3+ can relax). Used by `/onboarding/username` only — the form is hidden if `user.username` doesn't look auto-generated.

### 4.6 `GET /auth/google/redirect` (public, gated)

If `GOOGLE_OAUTH_ENABLED=false`: 404. Otherwise: 302 to Google's OAuth consent URL via `Socialite::driver('google')->redirect()`.

### 4.7 `GET /auth/google/callback` (public, gated)

Receives `?code=…`. Calls `Socialite::driver('google')->stateless()->user()`. Resolves the application user via `GoogleOAuthService::resolve()`. Issues a Sanctum token. Returns a small HTML page (or a redirect) that POSTs `{token, user}` to the frontend's `/api/auth/set-cookie` — see §5 for the dance.

The simpler implementation: callback returns a `text/html` response with a tiny inline form auto-submitting to `https://<frontend-host>/api/auth/set-cookie` via a one-time signed JWT-ish nonce. The plan picks the **simpler simpler** path: the callback issues the token and returns a redirect to `https://<frontend-host>/auth/google/finish?token=<token>&new_user=<bool>`. The frontend route handler reads the query, calls `/api/auth/set-cookie`, then redirects to `/onboarding/username` (if new) or `/fish` (otherwise). This keeps the token in transit only across one cross-site redirect — acceptable because (a) it's HTTPS, (b) it's one-shot, (c) Sanctum tokens are revocable.

**Caveat:** putting a token in a query string is fine for one-time bootstrap, but the URL must be replaced (`history.replaceState`) immediately on the frontend page to avoid browser-history leakage. The plan documents this.

---

## 5. Cookie & Proxy Architecture

### 5.1 Local login dance (text sequence)

```
Browser                Next.js (FE)              Laravel (BE)
   |                       |                          |
   | POST /api/proxy/      |                          |
   |   auth/login {creds}  |                          |
   |---------------------->|                          |
   |                       | (no cookie yet → ok)     |
   |                       | forward to BE            |
   |                       |------------------------->|
   |                       |                          | validate, issue token
   |                       |<-------------------------|
   |                       |  {user, token}           |
   |                       |                          |
   | (FE useMutation)      |                          |
   |<----------------------|                          |
   |  {user, token}        |                          |
   |                       |                          |
   | POST /api/auth/       |                          |
   |   set-cookie          |                          |
   |   {token, user}       |                          |
   |---------------------->|                          |
   |                       | iron-session.save()      |
   |                       | set Set-Cookie HttpOnly  |
   |                       |   Secure SameSite=Lax    |
   |<----------------------|                          |
   |  204                  |                          |
   |                       |                          |
   | router.push('/fish')  |                          |
```

After this, every subsequent authed call goes:

```
Browser                Next.js (FE)              Laravel (BE)
   |  GET /api/proxy/      |                          |
   |   auth/me             |                          |
   |   (cookie auto-sent)  |                          |
   |---------------------->|                          |
   |                       | unseal cookie → token    |
   |                       | forward + Bearer token   |
   |                       |------------------------->|
   |                       |                          | Sanctum auth → user
   |                       |<-------------------------|
   |                       |  {user}                  |
   |<----------------------|                          |
```

### 5.2 Google OAuth dance

```
Browser  → GET /api/v1/auth/google/redirect           (BE 302 → Google)
Browser  → Google consent                             (user approves)
Browser  → GET /api/v1/auth/google/callback?code=…    (BE resolves user, issues token)
Browser  ← 302 https://<fe>/auth/google/finish?token=…&new=0
Browser  → /auth/google/finish (FE page, 'use client')
            ↳ POST /api/auth/set-cookie {token, user-from-decoded-me-call}
            ↳ history.replaceState — drop token from URL
            ↳ router.push '/fish' or '/onboarding/username'
```

### 5.3 Why a Next.js proxy

- The Sanctum token lives in Next.js only. Browser JS can't read it (HttpOnly cookie). XSS can't steal it.
- Middleware can validate the cookie at the edge (iron-session decrypts in Edge runtime) without a DB hit.
- CORS gets simpler: backend only ever sees same-origin-from-its-perspective requests from the Next.js process; we keep `FRONTEND_URL` in `allowed_origins` for legacy / direct access (e.g. the OAuth redirect callback), but the steady-state traffic doesn't need it.

### 5.4 What we lose

- TanStack Query suspense/streaming on the *first* render can't use the cookie directly inside React Server Components without a roundtrip — we'd need to read the cookie inside an RSC, then hydrate. Slice 2 uses client components for the authed pages (`/fish` doesn't exist yet anyway), so this isn't a blocker. Slice 3 may want a typed server-side fetch helper that reads cookies via `next/headers`.

---

## 6. Threat Model Touch-Points

| Threat | Mitigation in this slice |
|---|---|
| **Brute-force login** | `RateLimiter::for('auth')` 5/min keyed on `ip + username`. Returns 429 + `Retry-After`. Tested. |
| **Username enumeration** | Login error message is generic ("Invalid username or password.") on both unknown user and wrong password. Identical HTTP status (422) + identical response body shape. Tested. |
| **Register-side enumeration** | Register *does* leak existence (it must — "this username is taken" is a usability requirement). Accepted risk; rate limit dampens abuse. Documented. |
| **Token theft from JS** | Token is never in JS-accessible storage. Only HttpOnly Secure SameSite=Lax cookie. The set-cookie route handler is the *only* place the token transits Next.js with the cookie un-set, and it sets the cookie atomically. |
| **CSRF on cookie-set endpoint** | The `/api/auth/set-cookie` POST is only invoked from same-origin code (login/register success handlers). SameSite=Lax means a cross-site POST won't carry any prior cookies, and the body must contain a valid token (which a CSRF attacker doesn't have). For belt-and-braces, the handler checks `request.headers.get('origin') === process.env.NEXT_PUBLIC_APP_URL`. Tested. |
| **CSRF on backend** | Token-bearer auth; not cookie-bound at the backend. SPEC §2.6: "CSRF disabled for the API guard." Not applicable to `/api/v1/*`. |
| **OAuth code-injection / state-tampering** | Socialite's `stateless()` flow trusts the `state` round-tripped by Google; we add no extra state of our own (acceptable for this slice; revisit in slice 7 hardening). The redirect URI is locked at the Google Cloud console. |
| **OAuth account-takeover via email collision** | `GoogleOAuthService` matches by `google_id` *first*, then by `email_verified_at IS NOT NULL` *and* `email = google.email` — never by unverified email. New accounts are created with `email_verified_at = now()` (Google has already verified). |
| **Token in OAuth-finish URL** | One-shot, HTTPS only, and the frontend immediately `history.replaceState`s it out of the URL. Logged-out devices can't replay because the token is rotated on the next password change and revocable via `/auth/logout`. Documented as residual risk. |
| **Weak admin password in prod** | `AdminUserSeeder` throws if `app()->environment('production')` and the password is empty / < 12 chars / in the denylist. Tested. |
| **Replay after logout** | `POST /auth/logout` deletes the current `personalAccessToken()`. The cookie is also cleared via `/api/auth/clear-cookie`. Both must succeed; the frontend does the cookie clear first so a backend hiccup doesn't strand a still-signed-in browser. |

---

## 7. Frontend State Architecture

### 7.1 Server cache: TanStack Query

- `useMeQuery()` — `queryKey: ['auth','me']`. Fetches `GET /api/proxy/auth/me`. Stale time 5 min, retry once, retry-on-401: **false**.
- `useRegisterMutation()` / `useLoginMutation()` — call the generated client, on success POST to `/api/auth/set-cookie`, then `queryClient.setQueryData(['auth','me'], data.user)`.
- `useLogoutMutation()` — POST `/api/auth/clear-cookie` first, then POST `/api/proxy/auth/logout`, then `queryClient.removeQueries({queryKey: ['auth']})`, then `useAuthStore.getState().clear()`.

### 7.2 UI state: zustand

```ts
type AuthStore = {
  user: UserResource | null;
  set: (u: UserResource) => void;
  clear: () => void;
};
```

Used by components that don't render-via-query (header avatar, conditional menus). **No `persist` middleware** — the cookie is the persistence layer. `<AuthHydrator>` runs on mount and copies `useMeQuery().data` into the store; on `useMeQuery()` error → store cleared.

### 7.3 What lives where

| Concern | Lives in |
|---|---|
| Is there a valid token? | iron-session cookie (single source of truth) |
| Current user's `username`, `email`, `is_admin` | TanStack Query `['auth','me']` cache + mirror in zustand for sync reads |
| "Am I on the login page?" | Next.js `usePathname()` |
| Pending form values | react-hook-form |
| zod schema for username/email/password | `frontend/src/lib/auth/schemas.ts` (importable by both forms) |

---

## 8. Testing Strategy

### 8.1 Backend (Pest)

| Layer | Files | What |
|---|---|---|
| Feature | `tests/Feature/Auth/RegisterTest.php` | happy 201; weak password (zxcvbn 1) 422; duplicate username 422; duplicate email 422; mismatched confirm 422; rate limit 6th call → 429. |
| Feature | `tests/Feature/Auth/LoginTest.php` | happy 200; wrong password 422 with generic msg; unknown user 422 with **same** msg; missing fields 422; rate limit 429. |
| Feature | `tests/Feature/Auth/LogoutTest.php` | unauthed 401; happy 204; token revoked after logout (subsequent `me` → 401). |
| Feature | `tests/Feature/Auth/MeTest.php` | unauthed 401; happy 200 returns expected fields; deleted token → 401. |
| Feature | `tests/Feature/Auth/ClaimUsernameTest.php` | happy 200; invalid regex 422; collides 422; unauthed 401. |
| Feature | `tests/Feature/Auth/GoogleOAuthTest.php` | redirect disabled → 404; redirect enabled → 302 to accounts.google.com; callback creates new user (Socialite mocked); callback resolves existing user by google_id; callback resolves by verified email; callback rejects unverified-email collision. |
| Unit | `tests/Unit/Services/Auth/AuthServiceTest.php` | hashes password; rejects weak; issues token; rejects unknown username with `AuthenticationException`. |
| Unit | `tests/Unit/Database/Seeders/AdminUserSeederTest.php` | seeds in non-production with default; throws in production if empty/short/denylisted; succeeds in production with strong password. |

Coverage gate: ≥80% on `app/Services/Auth/` and `app/Http/Controllers/Api/V1/`. PHPUnit `<coverage>` config in `phpunit.xml`:

```xml
<coverage>
  <include>
    <directory>app/Services</directory>
    <directory>app/Http/Controllers</directory>
  </include>
</coverage>
```

CI runs `./vendor/bin/pest --coverage --min=80`.

### 8.2 Frontend (Vitest)

| Layer | Files | What |
|---|---|---|
| Unit | `tests/unit/lib/auth/schemas.test.ts` | zod RegisterSchema accepts valid; rejects bad username regex; rejects short password; rejects mismatched confirm. |
| Unit | `tests/unit/lib/api/proxy.test.ts` | Mocked global `fetch`. Asserts the proxy forwards method/body/query, injects `Authorization: Bearer …` from a stubbed cookie, returns the upstream response unchanged. |
| Unit | `tests/unit/stores/auth-store.test.ts` | set / clear / initial null. |
| Component | `tests/unit/components/auth/RegisterForm.test.tsx` | renders fields; submit-with-empty shows required errors and **no** mutation call; submit-with-valid calls the mutation hook (mocked) and shows pending state. |
| Component | `tests/unit/components/auth/LoginForm.test.tsx` | mirrors RegisterForm. |
| Component | `tests/unit/components/auth/GoogleButton.test.tsx` | hidden when env flag is `'false'`; visible when `'true'`; click navigates to `/api/proxy/auth/google/redirect`. |

Coverage gate: 70% statement floor across the project (`--coverage.thresholds.statements=70`). Configured in `vitest.config.ts`.

### 8.3 Out

- E2E: deferred to slice 3 (Playwright).
- The proxy is not integration-tested end-to-end against a running backend in this slice; the unit test with mocked `fetch` is the contract. Slice 3's E2E will give us live coverage.

---

## 9. Acceptance Criteria

1. `POST /api/v1/auth/register` with a valid payload returns 201 + `{user, token}`; the user appears in the `users` table with hashed password and `is_admin=false`.
2. `POST /api/v1/auth/login` with the same creds returns 200 + `{user, token}`; with wrong creds returns 422 + the generic message; with an unknown user returns the **same** 422 + same message.
3. Six failed logins in one minute (same IP, same username) yield 429 on the sixth.
4. `GET /api/v1/auth/me` with `Authorization: Bearer <token>` returns the user; without a token returns 401.
5. `POST /api/v1/auth/logout` revokes the token; the same token on a subsequent `/auth/me` returns 401.
6. With `GOOGLE_OAUTH_ENABLED=false`, `GET /api/v1/auth/google/redirect` returns 404.
7. Browsing to `http://localhost:3000/register`, filling the form, and submitting lands on `/fish` (which 404s — that's slice 3 — but the navigation succeeds and the cookie is set).
8. Refreshing after login keeps the user logged in; `/api/v1/auth/me` is fetched on hydration; the username is visible to React via the zustand store.
9. Hitting `/fish` (or `/onboarding/username`) without a cookie → 307 redirect to `/login`.
10. The Sanctum token is **not** visible in `localStorage`, `sessionStorage`, or any non-HttpOnly cookie when inspected via DevTools.
11. `php artisan l5-swagger:generate` + `git diff --exit-code storage/api-docs/openapi.json` exits 0.
12. `npm run generate:api` + `git diff --exit-code src/lib/api-client` exits 0.
13. `./vendor/bin/pest --coverage --min=80` passes with the scoped config.
14. `npm test -- --coverage` passes with the 70% statement floor.
15. `./vendor/bin/phpstan analyse` level 6 stays clean. `npm run lint`, `npm run typecheck`, `npm run build` stay clean.

---

## 10. Open Questions / Follow-Ups

- **Single-flight token rotation.** SPEC §16 says "rotated on password change." No password-change endpoint in v1. Defer until that endpoint exists.
- **Username case-handling on display.** `citext` makes uniqueness case-insensitive, but we still store whatever case the user registered with. The frontend renders verbatim. If product wants canonical lowercase, that's a slice 7 polish.
- **OAuth `state` parameter.** Socialite's stateless flow trusts Google's round-trip. If we ever serve untrusted users, we should add an HMAC-signed `state` cookie. Out of slice 2.
- **Edge runtime middleware vs node middleware.** `iron-session` works in both; we use Node (default) for now. If Vercel/Railway forces Edge, switch is one-line.
- **Token leakage through Next.js logs.** Vercel/Railway log every request. The proxy must `redact` the `Authorization` header from access logs. Implementation: explicit `logger.info('proxy', { method, path })` with no headers; we do **not** rely on framework auto-logging here. Documented as a constraint to verify in slice 7 polish.
- **`/auth/google/finish` token in URL.** A minor residual risk; the alternative is a server-rendered HTML+form-post, which is uglier and still leaks the token through HTML source if logged. Acceptable.

---

## 11. Sources

- `SPEC.md` §1 (routes), §2.1 (auth API), §2.6 (rate limits), §3 (users), §7 (auth flows), §9 (services), §10 (env), §12 (testing), §16 (security).
- `AGENT.md` §1 (versions), §3 (conventions), §4 (security non-negotiables), §5 (testing standards).
- `BRAND.md` §2 (color), §5 (glass surfaces), §6 (radii), §9 (motion) — for form styling.
- Slice 1 design + plan — for tone, structure, and CI baseline.
