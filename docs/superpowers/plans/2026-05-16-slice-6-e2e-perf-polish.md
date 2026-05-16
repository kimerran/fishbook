# Slice 6 — E2E, Perf & Polish Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` (or `superpowers:subagent-driven-development` for parallelizable chunks) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking; do **not** mark a checkbox until the step's expected output is observed.

**Goal:** Make Fishbook testable end-to-end through real browser automation, prove the canvas hits 60 fps budget, and tidy the loose ends from slices 1–5. After this slice, SPEC §17 acceptance items 1–9 + 11 are satisfied: a Playwright suite drives register → log in → add fish → hover → feed → manage → upload bg → view repo aquarium → fork against the real `docker compose` stack; the canvas cold-cache stall is gone; API ids are ULIDs; CI has an `e2e.yml` workflow.

**Architecture:** Phase A migrates `fishes` and `backgrounds` to ULID-externalized identifiers with a safe three-step migration (`NULLABLE → backfill → NOT NULL`), a `HasUlid` trait, resource changes, route-pattern swaps, and one-shot OpenAPI + frontend-client regen. Phase B adds `preloadSprites` + a synchronous `getCachedSprite` to drop the `await getTintedSprite` from the RAF loop, clamps hovered-fish to 15% maxSpeed, and ships a Vitest perf harness for 100 fish + 20 pellets. Phase C names the `HealthResponse` schema, adds `Cache-Control` to `/fishes/breeds`, clears lint warnings. Phase D adds Playwright (`@playwright/test`, `wait-on`) and four spec files driving the canonical journey against `docker compose`. Phase E ships `.github/workflows/e2e.yml`. Phase F shakes out the Dockerfiles (first real build) and ships a `docker-compose.e2e.yml` overlay that uses prod targets.

**Tech Stack:** Laravel 13 + PHP 8.3 + Pest + Larastan (no new backend deps). Next.js 16 + React 19 + TS strict + `@playwright/test@^1.49` + `wait-on@^8` (new dev deps).

**Spec:** [`docs/superpowers/specs/2026-05-16-slice-6-e2e-perf-polish-design.md`](../specs/2026-05-16-slice-6-e2e-perf-polish-design.md).

---

## Conventions

- Today's date for commit messages is **2026-05-16**.
- All backend commands use `docker compose exec backend …` so Postgres `jsonb` and Redis are real.
- Conventional Commits (`feat:`, `fix:`, `chore:`, `test:`, `docs:`, `refactor:`, `ci:`, `perf:`).
- One task = one commit. Don't squash.
- Commit trailer:
  ```
  Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
  ```
  Use heredoc form (see slice 5 plan §Conventions).
- TDD where practical: failing test first, then code, then green. Phase A skips RED-first for the migration itself (the migration *is* the test fixture) but adds tests immediately after.
- Build on `main` after slice 5's `slice-5-repo-aquarium` tag.
- Frontend tests run with `cd frontend && npm test -- --run`; backend tests with `docker compose exec backend ./vendor/bin/pest`.
- Playwright tests run with `cd frontend && npx playwright test` against the running stack.

---

## Task 1: Slice prep — verify slice 5 baseline + plan check

**Files:** (none — verification only)

- [ ] **Step 1: Confirm clean tree on `main` at slice 5 tag.**

```bash
git status
git describe --tags --abbrev=0
```

Expected: working tree clean; tag prints `slice-5-repo-aquarium`.

- [ ] **Step 2: Confirm slice 5 surfaces exist.**

```bash
test -f backend/app/Services/Github/RepoAquariumGenerator.php && echo OK-generator
test -f frontend/src/components/repo-aquarium/RepoAquariumPage.tsx && echo OK-repo-page
test -f backend/storage/api-docs/openapi.json && echo OK-openapi
docker compose up -d db redis
sleep 3
docker compose exec backend php artisan migrate --pretend | tail -5
```

Expected: all `OK-` lines; migrate-pretend lists no pending migrations.

- [ ] **Step 3: Snapshot current `Number(id)` callsites for the cleanup task.**

```bash
grep -RIn 'Number(id)' frontend/src | tee /tmp/number-id-callsites.txt
wc -l /tmp/number-id-callsites.txt
```

Expected: a non-empty list — these will all disappear by end of Phase A. (Numbers vary by slice 3/4 implementation; record the count for accounting.)

- [ ] **Step 4: No commit.** Verification only.

---

# Phase A — ULID migration

## Task 2: Backend — `HasUlid` trait (test-first)

**Files:**
- Create: `backend/app/Models/Concerns/HasUlid.php`
- Create: `backend/tests/Unit/Models/HasUlidTraitTest.php`

- [ ] **Step 1: Write the failing test.**

`backend/tests/Unit/Models/HasUlidTraitTest.php`:

```php
<?php

use App\Models\Fish;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('auto-populates ulid on create when absent', function () {
    $user = User::factory()->create();
    $fish = Fish::create([
        'user_id'   => $user->id,
        'nickname'  => 'Bubbles',
        'breed'     => 'guppy',
        'color_hex' => '#FF6B9D',
        'size'      => 12,
        'source'    => 'manual',
    ]);

    expect($fish->ulid)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
});

it('respects a supplied ulid', function () {
    $user = User::factory()->create();
    $supplied = '01HZ123456789ABCDEFGHJKMNP';
    $fish = Fish::create([
        'user_id'   => $user->id,
        'nickname'  => 'Bubbles',
        'breed'     => 'guppy',
        'color_hex' => '#FF6B9D',
        'size'      => 12,
        'source'    => 'manual',
        'ulid'      => $supplied,
    ]);

    expect($fish->ulid)->toBe($supplied);
});

it('exposes ulid as the route key name', function () {
    expect((new Fish())->getRouteKeyName())->toBe('ulid');
});
```

- [ ] **Step 2: Run — expected RED (no `ulid` column yet).**

```bash
docker compose exec backend ./vendor/bin/pest tests/Unit/Models/HasUlidTraitTest.php
```

Expected: failure (`Undefined column 'ulid'` or trait not yet present).

- [ ] **Step 3: Create the trait.**

`backend/app/Models/Concerns/HasUlid.php`:

```php
<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

trait HasUlid
{
    public static function bootHasUlid(): void
    {
        static::creating(function ($model): void {
            $model->ulid ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }
}
```

- [ ] **Step 4: Don't run tests yet — we need the migration + model wiring first (Task 3 + 4). Commit the trait file alone.**

```bash
git add backend/app/Models/Concerns/HasUlid.php backend/tests/Unit/Models/HasUlidTraitTest.php
git commit -m "$(cat <<'EOF'
feat(backend): add HasUlid trait — auto-populates ulid on creating + uses ulid as route key

Test is RED until the ulid column lands in Task 3 and the trait is applied to models in Task 4.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Backend — ULID migration (`fishes` + `backgrounds`)

**Files:**
- Create: `backend/database/migrations/2026_05_16_000030_add_ulid_to_fishes_and_backgrounds.php`

- [ ] **Step 1: Migration.**

```php
<?php

use App\Models\Background;
use App\Models\Fish;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('fishes', function (Blueprint $t) {
            $t->char('ulid', 26)->nullable()->after('id');
        });
        Schema::table('backgrounds', function (Blueprint $t) {
            $t->char('ulid', 26)->nullable()->after('id');
        });

        DB::transaction(function () {
            Fish::withTrashed()->whereNull('ulid')->cursor()->each(function ($f) {
                $f->forceFill(['ulid' => (string) Str::ulid()])->save();
            });
            Background::withTrashed()->whereNull('ulid')->cursor()->each(function ($b) {
                $b->forceFill(['ulid' => (string) Str::ulid()])->save();
            });
        });

        Schema::table('fishes', function (Blueprint $t) {
            $t->char('ulid', 26)->nullable(false)->change();
            $t->unique('ulid');
        });
        Schema::table('backgrounds', function (Blueprint $t) {
            $t->char('ulid', 26)->nullable(false)->change();
            $t->unique('ulid');
        });
    }

    public function down(): void
    {
        Schema::table('fishes', function (Blueprint $t) {
            $t->dropUnique(['ulid']);
            $t->dropColumn('ulid');
        });
        Schema::table('backgrounds', function (Blueprint $t) {
            $t->dropUnique(['ulid']);
            $t->dropColumn('ulid');
        });
    }
};
```

- [ ] **Step 2: Run + verify.**

```bash
docker compose exec backend php artisan migrate
docker compose exec db psql -U fishbook -d fishbook -c "\d fishes"   | grep ulid
docker compose exec db psql -U fishbook -d fishbook -c "\d backgrounds" | grep ulid
docker compose exec db psql -U fishbook -d fishbook -c "SELECT id, ulid FROM fishes LIMIT 5;"
```

Expected: `ulid` columns are `character(26) not null`; unique indexes present; rows show populated ULIDs.

- [ ] **Step 3: Commit.**

```bash
git add backend/database/migrations/2026_05_16_000030_add_ulid_to_fishes_and_backgrounds.php
git commit -m "$(cat <<'EOF'
feat(backend): add ulid column to fishes + backgrounds with safe backfill

Three-step migration: ADD NULL → backfill via Str::ulid() → ALTER NOT NULL + UNIQUE.
Idempotent backfill (whereNull('ulid')) allows safe retry on populated DBs.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Backend — Apply `HasUlid` trait to `Fish` and `Background`

**Files:**
- Modify: `backend/app/Models/Fish.php`
- Modify: `backend/app/Models/Background.php`

- [ ] **Step 1: Add the trait + `ulid` to `$fillable` on both models.**

In `Fish.php` and `Background.php`:

```php
use App\Models\Concerns\HasUlid;

class Fish extends Model      // ← or `Background`
{
    use HasFactory, SoftDeletes, HasUlid;

    protected $fillable = [
        'ulid',          // ← add at the top
        // ...existing fillable...
    ];
}
```

- [ ] **Step 2: Verify the trait test goes GREEN.**

```bash
docker compose exec backend ./vendor/bin/pest tests/Unit/Models/HasUlidTraitTest.php
```

Expected: all three tests pass.

- [ ] **Step 3: Run the full backend suite — expect a wave of RED in feature tests that hard-coded integer ids.**

```bash
docker compose exec backend ./vendor/bin/pest --colors=always 2>&1 | tail -40
```

Expected: trait + migration tests GREEN; some `tests/Feature/Fish/*` and `tests/Feature/Backgrounds/*` may fail because they assert `data.id` as integer. That's Task 7.

- [ ] **Step 4: Commit.**

```bash
git add backend/app/Models/Fish.php backend/app/Models/Background.php
git commit -m "$(cat <<'EOF'
feat(backend): apply HasUlid trait to Fish + Background, route-bind by ulid

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Backend — Resources emit `id` as ULID

**Files:**
- Modify: `backend/app/Http/Resources/FishResource.php`
- Modify: `backend/app/Http/Resources/BackgroundResource.php`

- [ ] **Step 1: Swap `id` in `toArray()` on both resources.**

```php
public function toArray($request): array
{
    return [
        'id'        => $this->ulid,
        // ...existing fields, omitting the bigserial...
    ];
}
```

Update the OA annotation block on each resource:

```php
/**
 * @OA\Schema(
 *     schema="FishResource",
 *     @OA\Property(property="id", type="string", format="ulid",
 *         pattern="^[0-9A-HJKMNP-TV-Z]{26}$", example="01HG5W7DZ8KX9F3N2VYRMPQABT"),
 *     ...
 * )
 */
```

- [ ] **Step 2: Sanity check via tinker.**

```bash
docker compose exec backend php artisan tinker --execute='echo json_encode(new App\Http\Resources\FishResource(App\Models\Fish::first()));'
```

Expected: `{"id":"01HZ…26chars…", "nickname":"…", ...}`. No numeric id.

- [ ] **Step 3: Commit.**

```bash
git add backend/app/Http/Resources/FishResource.php backend/app/Http/Resources/BackgroundResource.php
git commit -m "$(cat <<'EOF'
feat(backend): emit id as ulid in FishResource + BackgroundResource (SPEC §2.6)

Internal bigserial PK is never serialized. OpenAPI schemas describe id as
type=string, pattern=^[0-9A-HJKMNP-TV-Z]{26}$.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: Backend — Route patterns + `routes/api.php`

**Files:**
- Modify: `backend/routes/api.php`

- [ ] **Step 1: Swap the regex pattern on `fish` and `background` parameters.**

```php
// At top of file, after the slice-5 owner/repo patterns:
Route::pattern('fish',       '[0-9A-HJKMNP-TV-Z]{26}');
Route::pattern('background', '[0-9A-HJKMNP-TV-Z]{26}');

// Inside the auth:sanctum group:
Route::apiResource('fishes', FishController::class);
//   ↑ drop the ->where(['fish' => '[0-9]+']) chain entirely; the pattern above handles it.

Route::patch('/backgrounds/{background}/select',  [BackgroundController::class, 'select'])
    ->middleware('throttle:api')->name('backgrounds.select');
Route::delete('/backgrounds/{background}',       [BackgroundController::class, 'destroy'])
    ->middleware('throttle:api')->name('backgrounds.destroy');
//   ↑ drop the ->where(['background' => '[0-9]+']) chains.
```

- [ ] **Step 2: Verify route list.**

```bash
docker compose exec backend php artisan route:list | grep -E 'fishes/\{fish\}|backgrounds/\{background\}'
```

Expected: routes listed; constraint columns show the ULID regex.

- [ ] **Step 3: Manual probe.**

```bash
docker compose exec backend php artisan tinker --execute="
\$u = App\Models\User::first();
\$t = \$u->createToken('test')->plainTextToken;
echo \$t.PHP_EOL;
" | tee /tmp/test-token.txt
# Then hit /api/v1/fishes/{some-ulid} via curl and confirm 200; hit /api/v1/fishes/123 and confirm 404.
```

- [ ] **Step 4: Commit.**

```bash
git add backend/routes/api.php
git commit -m "$(cat <<'EOF'
feat(backend): route-bind fishes + backgrounds by ulid (drop integer pattern)

Route::pattern enforces 26-char Crockford-base32 at the routing layer; misshaped ids miss the route and 404.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: Backend — Pest test sweep + new `UlidIdentifierTest`

**Files:**
- Create: `backend/tests/Feature/UlidIdentifierTest.php`
- Modify: any slice 3/4 feature tests asserting integer ids.

- [ ] **Step 1: Write the new feature test.**

```php
<?php

use App\Models\Fish;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('emits ulid as id on fish list', function () {
    $user = User::factory()->has(Fish::factory()->count(2))->create();
    Sanctum::actingAs($user);

    $r = $this->getJson('/api/v1/fishes')->assertOk();

    foreach ($r->json('data') as $row) {
        expect($row['id'])->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
    }
});

it('resolves a fish by ulid', function () {
    $user = User::factory()->has(Fish::factory())->create();
    $fish = $user->fishes->first();
    Sanctum::actingAs($user);

    $this->getJson("/api/v1/fishes/{$fish->ulid}")
        ->assertOk()
        ->assertJsonPath('data.id', $fish->ulid);
});

it('404s on integer id (route pattern miss)', function () {
    $user = User::factory()->has(Fish::factory())->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/fishes/123')->assertNotFound();
});

it('emits ulid as id on backgrounds list', function () {
    $user = User::factory()->has(\App\Models\Background::factory()->count(2))->create();
    Sanctum::actingAs($user);

    $r = $this->getJson('/api/v1/backgrounds')->assertOk();

    foreach ($r->json('data') as $row) {
        expect($row['id'])->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
    }
});
```

- [ ] **Step 2: Run + observe failures in older feature tests.**

```bash
docker compose exec backend ./vendor/bin/pest --colors=always 2>&1 | tail -60
```

Expected: `UlidIdentifierTest` GREEN; some slice 3/4 tests RED.

- [ ] **Step 3: Fix the RED tests one at a time.** Typical patterns:

  - `$response->assertJsonPath('data.id', $fish->id)` → `$response->assertJsonPath('data.id', $fish->ulid)`
  - `$response->assertJsonPath('data.id', 1)` → use the created model's `ulid` instead of hardcoded `1`.
  - URLs that interpolate `$fish->id` → `$fish->ulid`.

- [ ] **Step 4: Full backend suite GREEN.**

```bash
docker compose exec backend ./vendor/bin/pest --colors=always
```

Expected: 0 failures.

- [ ] **Step 5: Commit.**

```bash
git add backend/tests/
git commit -m "$(cat <<'EOF'
test(backend): assert ulid id surface across fish + backgrounds; fix slice 3/4 tests

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: Backend — OpenAPI regen + commit

**Files:**
- Modify: `backend/storage/api-docs/openapi.json`

- [ ] **Step 1: Regenerate.**

```bash
docker compose exec backend php artisan l5-swagger:generate
```

- [ ] **Step 2: Inspect the diff for `id` shape changes.**

```bash
git diff --stat backend/storage/api-docs/openapi.json
git diff backend/storage/api-docs/openapi.json | grep -A2 '"id":' | head -30
```

Expected: `id` declared as `type: string`, `pattern: ^[0-9A-HJKMNP-TV-Z]{26}$` for both `FishResource` and `BackgroundResource`.

- [ ] **Step 3: Commit.**

```bash
git add backend/storage/api-docs/openapi.json
git commit -m "$(cat <<'EOF'
chore(backend): regenerate openapi.json with ulid-typed ids

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: Frontend — Regenerate API client + drop `Number(id)`

**Files:**
- Regenerate: `frontend/src/lib/api-client/**` (whole directory rewritten by openapi-generator)
- Modify: every `frontend/src/hooks/use-*.ts` that previously cast `Number(id)`
- Modify: `frontend/src/components/manage/FishManagerModal.tsx` (id-as-key usage)

- [ ] **Step 1: Regenerate the client.**

```bash
cd frontend
rm -rf src/lib/api-client
npx openapi-generator-cli generate \
  -i ../backend/storage/api-docs/openapi.json \
  -g typescript-fetch \
  -o src/lib/api-client
```

- [ ] **Step 2: Quick sanity check.**

```bash
grep -n "id: string" src/lib/api-client/models/FishResource.ts
grep -n "id: string" src/lib/api-client/models/BackgroundResource.ts
```

Expected: both grep hits exist; no `id: number`.

- [ ] **Step 3: Sweep `Number(id)` callsites listed in `/tmp/number-id-callsites.txt`.**

For each hook file (`use-fish-query.ts`, `use-create-fish-mutation.ts`, `use-update-fish-mutation.ts`, `use-delete-fish-mutation.ts`, `use-backgrounds-query.ts`, `use-upload-background-mutation.ts`, `use-select-background-mutation.ts`, `use-delete-background-mutation.ts`):

- Change signatures: `(id: number)` → `(id: string)`.
- Change query keys: `['fishes', 'one', Number(id)]` → `['fishes', 'one', id]`.
- Drop any `parseInt`, `Number`, `+id` casts on the id.

- [ ] **Step 4: TypeScript fixes — run typecheck and resolve any leftovers.**

```bash
cd frontend && npm run typecheck
```

Expected: 0 errors. If `tsc` complains about `id: number` somewhere not in the snapshot, fix it there too.

- [ ] **Step 5: Vitest sweep.**

```bash
cd frontend && npm test -- --run 2>&1 | tail -40
```

Expected: any test using a literal numeric id will fail. Fix by using string ULIDs (generate with `crypto.randomUUID().replace(/-/g, '').slice(0,26).toUpperCase()` in a test helper, or use a constant `'01HZTESTTESTTESTTESTTESTAB'`).

- [ ] **Step 6: Confirm callsites are gone.**

```bash
grep -RIn 'Number(id)' frontend/src && echo "STILL PRESENT" || echo "ALL CLEAR"
```

Expected: `ALL CLEAR`.

- [ ] **Step 7: Commit.**

```bash
cd ..
git add frontend/src/lib/api-client frontend/src/hooks frontend/src/components frontend/tests
git commit -m "$(cat <<'EOF'
refactor(frontend): regenerate API client + propagate ulid string ids through hooks/components

Drops every Number(id) cast. TanStack Query keys now use ulid strings.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

# Phase B — Canvas perf

## Task 10: Frontend — `sprite-cache` adds `preloadSprites` + `getCachedSprite` (TDD)

**Files:**
- Modify: `frontend/src/lib/aquarium/sprite-cache.ts`
- Create: `frontend/tests/unit/lib/aquarium/sprite-cache.test.ts`

- [ ] **Step 1: Write failing tests.**

```ts
import { beforeEach, describe, expect, it } from 'vitest';
import {
  getCachedSprite,
  preloadSprites,
  __resetSpriteCacheForTests,
} from '@/lib/aquarium/sprite-cache';

beforeEach(() => __resetSpriteCacheForTests());

describe('sprite-cache', () => {
  it('returns null on cache miss synchronously', () => {
    expect(getCachedSprite('guppy', '#FF6B9D')).toBeNull();
  });

  it('caches after preloadSprites', async () => {
    await preloadSprites([{ breed: 'guppy', color_hex: '#FF6B9D' }]);
    expect(getCachedSprite('guppy', '#FF6B9D')).not.toBeNull();
  });

  it('is idempotent across repeated preload calls', async () => {
    await preloadSprites([{ breed: 'guppy', color_hex: '#FF6B9D' }]);
    const first = getCachedSprite('guppy', '#FF6B9D');
    await preloadSprites([{ breed: 'guppy', color_hex: '#FF6B9D' }]);
    expect(getCachedSprite('guppy', '#FF6B9D')).toBe(first);
  });

  it('deduplicates a fish list', async () => {
    await preloadSprites([
      { breed: 'guppy', color_hex: '#FF6B9D' },
      { breed: 'guppy', color_hex: '#FF6B9D' },
      { breed: 'molly', color_hex: '#1F2937' },
    ]);
    expect(getCachedSprite('guppy', '#FF6B9D')).not.toBeNull();
    expect(getCachedSprite('molly', '#1F2937')).not.toBeNull();
  });
});
```

- [ ] **Step 2: Run — RED.**

```bash
cd frontend && npm test -- --run tests/unit/lib/aquarium/sprite-cache.test.ts
```

- [ ] **Step 3: Implement.**

Append to `sprite-cache.ts`:

```ts
export function getCachedSprite(breed: string, colorHex: string): CanvasImageSource | null {
  const key = `${breed}::${colorHex}`;
  const hit = cache.get(key);                 // ← cache is the existing Map<string, CanvasImageSource>
  return hit ?? null;
}

export async function preloadSprites(
  items: Array<{ breed: string; color_hex: string }>,
): Promise<void> {
  const seen = new Set<string>();
  const promises: Array<Promise<unknown>> = [];
  for (const { breed, color_hex } of items) {
    const key = `${breed}::${color_hex}`;
    if (seen.has(key)) continue;
    seen.add(key);
    if (!cache.has(key)) promises.push(getTintedSprite(breed, color_hex));
  }
  await Promise.all(promises);
}

// Test-only helper. NOT exported from the index barrel; tests deep-import.
export function __resetSpriteCacheForTests(): void {
  cache.clear();
}
```

- [ ] **Step 4: GREEN.**

```bash
npm test -- --run tests/unit/lib/aquarium/sprite-cache.test.ts
```

- [ ] **Step 5: Commit.**

```bash
git add frontend/src/lib/aquarium/sprite-cache.ts frontend/tests/unit/lib/aquarium/sprite-cache.test.ts
git commit -m "$(cat <<'EOF'
perf(frontend): add preloadSprites + synchronous getCachedSprite (slice 3 deferred)

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 11: Frontend — Wire `preloadSprites` into `AquariumCanvas`, drop `await` from RAF

**Files:**
- Modify: `frontend/src/components/aquarium/AquariumCanvas.tsx`

- [ ] **Step 1: Update imports and the fish-sync `useEffect`.**

```ts
import { getCachedSprite, preloadSprites } from '@/lib/aquarium/sprite-cache';
```

In the fish-sync `useEffect`, after the existing reconciliation loop:

```ts
void preloadSprites(fishes.map((f) => ({ breed: f.breed, color_hex: f.color_hex })));
```

- [ ] **Step 2: Drop `async`/`await` from the `tick` function.**

```ts
const tick = (now: number) => {              // ← was async (now)
  const dt = lastTimeRef.current ? Math.min(50, now - lastTimeRef.current) : 16;
  lastTimeRef.current = now;
  const { paused } = useAquariumStore.getState();

  if (!paused && !mq.matches && !isHidden()) {
    for (const f of fishMapRef.current.values())
      f.update(dt, pelletsRef.current, viewportRef.current);
    for (const p of pelletsRef.current) p.update(dt);
    pelletsRef.current = pelletsRef.current.filter((p) => !p.eaten && !p.isExpired(now));
  }

  ctx.clearRect(0, 0, canvas.width, canvas.height);
  for (const f of fishMapRef.current.values()) {
    const sprite = getCachedSprite(f.breed, f.color_hex);
    if (sprite) {
      f.render(ctx, sprite);
    } else {
      drawFallbackCircle(ctx, f);
    }
  }
  ctx.fillStyle = 'rgba(245, 158, 11, 0.9)';
  for (const p of pelletsRef.current) {
    ctx.beginPath();
    ctx.arc(p.position.x, p.position.y, 3, 0, Math.PI * 2);
    ctx.fill();
  }
  rafIdRef.current = requestAnimationFrame(tick);
};
```

Add a tiny local helper at the bottom of the file (or in `Fish.ts`):

```ts
function drawFallbackCircle(ctx: CanvasRenderingContext2D, f: Fish): void {
  ctx.fillStyle = f.color_hex;
  ctx.beginPath();
  ctx.arc(f.position.x, f.position.y, f.size + 4, 0, Math.PI * 2);
  ctx.fill();
}
```

- [ ] **Step 3: Assert no `await` remains.**

```bash
grep -n 'await' frontend/src/components/aquarium/AquariumCanvas.tsx
```

Expected: zero matches.

- [ ] **Step 4: Vitest sweep.**

```bash
cd frontend && npm test -- --run
```

Expected: all GREEN. If existing canvas tests fail because they relied on the previous `async tick`, update them (the change is a behavior-preserving perf fix — tests should still pass with synchronous render).

- [ ] **Step 5: Commit.**

```bash
git add frontend/src/components/aquarium/AquariumCanvas.tsx
git commit -m "$(cat <<'EOF'
perf(frontend): drop await getTintedSprite from RAF loop; preload on mount

Cold-cache slots fall back to a colored circle for 1–2 frames; the loop is
now strictly synchronous. Closes slice-3 deferred item.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 12: Frontend — Hovered-fish slow drift (`Fish.update` clamp)

**Files:**
- Modify: `frontend/src/lib/aquarium/Fish.ts`
- Create: `frontend/tests/unit/lib/aquarium/Fish.hover.test.ts`

- [ ] **Step 1: Failing test.**

```ts
import { describe, expect, it } from 'vitest';
import { Fish } from '@/lib/aquarium/Fish';

const stableRng = () => 0.5;

function makeFish(hovered: boolean): Fish {
  const f = new Fish({
    id: 'x', breed: 'guppy', color_hex: '#FF6B9D', size: 12, nickname: 'X',
    prng: stableRng, viewport: { w: 800, h: 600 }, verticalBandPreference: null,
  });
  f.hovered = hovered;
  // Force a far target so steering tries to max out the speed cap.
  f.target = { x: 10000, y: 10000 };
  return f;
}

describe('Fish hover slow-drift', () => {
  it('caps speed to 15% of maxSpeed when hovered', () => {
    const f = makeFish(true);
    for (let i = 0; i < 60; i++) f.update(16.67, [], { w: 800, h: 600 });
    const speed = Math.hypot(f.velocity.x, f.velocity.y);
    expect(speed).toBeLessThanOrEqual(f.maxSpeed * 0.15 + 0.01);
  });

  it('runs at full maxSpeed when not hovered', () => {
    const f = makeFish(false);
    for (let i = 0; i < 60; i++) f.update(16.67, [], { w: 800, h: 600 });
    const speed = Math.hypot(f.velocity.x, f.velocity.y);
    expect(speed).toBeGreaterThan(f.maxSpeed * 0.5);
  });

  it('still re-picks target when hovered', () => {
    const f = makeFish(true);
    const initialTarget = { ...f.target };
    for (let i = 0; i < 500; i++) f.update(16.67, [], { w: 800, h: 600 });
    expect(f.target).not.toEqual(initialTarget);
  });
});
```

- [ ] **Step 2: Run — RED on the first and third assertions.**

- [ ] **Step 3: Patch `Fish.update`.**

Replace the speed-cap block + the hover gate:

```ts
// Hover no longer suppresses target re-pick.
this.nextTargetAt -= dtMs;
if (this.nextTargetAt <= 0) this.pickNewTarget(vp);

// ... existing accel + velocity addition stays ...

const effectiveMax = this.hovered ? this.maxSpeed * 0.15 : this.maxSpeed;
const speed = Math.hypot(this.velocity.x, this.velocity.y);
if (speed > effectiveMax) {
  this.velocity.x = (this.velocity.x / speed) * effectiveMax;
  this.velocity.y = (this.velocity.y / speed) * effectiveMax;
}
```

- [ ] **Step 4: GREEN.**

```bash
cd frontend && npm test -- --run tests/unit/lib/aquarium/Fish.hover.test.ts
```

- [ ] **Step 5: Commit.**

```bash
git add frontend/src/lib/aquarium/Fish.ts frontend/tests/unit/lib/aquarium/Fish.hover.test.ts
git commit -m "$(cat <<'EOF'
fix(frontend): hovered fish drift instead of freezing target rotation

Resolves slice-3 deferred edge case: hovered fish keep re-picking targets but
clamp effective max speed to 15%. No warps, no freezes.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 13: Frontend — Canvas perf harness (Vitest)

**Files:**
- Create: `frontend/tests/unit/lib/aquarium/canvas-perf.test.ts`

- [ ] **Step 1: Test.**

```ts
import { describe, expect, it } from 'vitest';
import { Fish } from '@/lib/aquarium/Fish';
import { FoodPellet } from '@/lib/aquarium/FoodPellet';
import { mulberry32, hashStringToSeed } from '@/lib/aquarium/seeded-random';

const BREEDS = ['guppy', 'molly', 'neon_tetra', 'zebra_danio', 'platy'];
const COLORS = ['#FF6B9D', '#1F2937', '#3B82F6', '#9CA3AF', '#F59E0B'];

function makeFish(i: number): Fish {
  return new Fish({
    id: `f${i}`,
    breed: BREEDS[i % BREEDS.length]!,
    color_hex: COLORS[i % COLORS.length]!,
    size: 10 + (i % 10),
    nickname: `Fish-${i}`,
    prng: mulberry32(hashStringToSeed(`f${i}`)),
    viewport: { w: 1920, h: 1080 },
    verticalBandPreference: null,
  });
}

describe('canvas-perf 100 fish + 20 pellets', () => {
  it('60 ticks complete within 1.2× frame budget', () => {
    const fish = Array.from({ length: 100 }, (_, i) => makeFish(i));
    const pellets = Array.from(
      { length: 20 },
      (_, i) => new FoodPellet({ x: 100 + i * 50, y: 200 + i * 30 }),
    );
    const vp = { w: 1920, h: 1080 };

    const start = performance.now();
    for (let frame = 0; frame < 60; frame++) {
      for (const f of fish) f.update(16.67, pellets, vp);
      for (const p of pellets) p.update(16.67);
    }
    const elapsed = performance.now() - start;
    const budget = 60 * 16.67 * 1.2;

    // eslint-disable-next-line no-console
    console.log(`perf: 60 ticks took ${elapsed.toFixed(1)}ms (budget ${budget.toFixed(1)}ms)`);
    expect(elapsed).toBeLessThan(budget);
  });
});
```

- [ ] **Step 2: Run.**

```bash
cd frontend && npm test -- --run tests/unit/lib/aquarium/canvas-perf.test.ts
```

Expected: GREEN. If RED on the CI runner, the harness is doing its job — investigate per-frame allocations. (On a 2020 MacBook Air this lands at ~30–80 ms wall-clock for the synthetic 1000 ms of frames.)

- [ ] **Step 3: Commit.**

```bash
git add frontend/tests/unit/lib/aquarium/canvas-perf.test.ts
git commit -m "$(cat <<'EOF'
test(frontend): canvas perf harness — 100 fish + 20 pellets, 60-tick budget

Soft proxy for the AGENT.md §6 60-fps budget. Real 60-fps proof needs a Chrome
DevTools trace; this harness catches per-frame allocation regressions early.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

# Phase C — Loose ends

## Task 14: Backend — Name `HealthResponse` schema + drop hash-named generated model

**Files:**
- Modify: `backend/app/Http/Controllers/Api/V1/HealthController.php`

- [ ] **Step 1: Add the OpenAPI annotation.**

```php
/**
 * @OA\Schema(
 *     schema="HealthResponse",
 *     required={"status","time"},
 *     @OA\Property(property="status", type="string", example="ok"),
 *     @OA\Property(property="time",   type="string", format="date-time", example="2026-05-16T12:34:56Z"),
 * )
 *
 * @OA\Get(
 *     path="/api/v1/health",
 *     operationId="getHealth",
 *     tags={"Health"},
 *     @OA\Response(response=200, description="OK",
 *         @OA\JsonContent(ref="#/components/schemas/HealthResponse"))
 * )
 */
class HealthController
{
    public function __invoke(): \Illuminate\Http\JsonResponse
    {
        return response()->json(['status' => 'ok', 'time' => now()->toIso8601String()]);
    }
}
```

- [ ] **Step 2: Regen + delete the hash-named client file (the regen will erase it; we just confirm).**

```bash
docker compose exec backend php artisan l5-swagger:generate
cd frontend
rm -rf src/lib/api-client
npx openapi-generator-cli generate -i ../backend/storage/api-docs/openapi.json -g typescript-fetch -o src/lib/api-client
ls src/lib/api-client/models/ | grep -i 'inline\|hash\|HealthResponse'
```

Expected: `HealthResponse.ts` exists; no `InlineResponse…` or hash-named file.

- [ ] **Step 3: Commit.**

```bash
cd ..
git add backend/app/Http/Controllers/Api/V1/HealthController.php backend/storage/api-docs/openapi.json frontend/src/lib/api-client
git commit -m "$(cat <<'EOF'
fix(backend): name HealthResponse OpenAPI schema (drops hash-named generated model)

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 15: Backend — `Cache-Control` on `/fishes/breeds`

**Files:**
- Modify: `backend/app/Http/Controllers/Api/V1/FishController.php`
- Modify: `backend/tests/Feature/BreedCatalogTest.php` (extend)

- [ ] **Step 1: Test first.**

```php
it('sets Cache-Control: public, max-age=3600 on /fishes/breeds', function () {
    $this->getJson('/api/v1/fishes/breeds')
        ->assertOk()
        ->assertHeader('Cache-Control', 'public, max-age=3600');
});
```

- [ ] **Step 2: Implement.**

In `FishController::breeds`:

```php
public function breeds(): JsonResponse
{
    return response()->json($this->breedCatalog->all())
        ->header('Cache-Control', 'public, max-age=3600');
}
```

- [ ] **Step 3: Run + commit.**

```bash
docker compose exec backend ./vendor/bin/pest tests/Feature/BreedCatalogTest.php
git add backend/app/Http/Controllers/Api/V1/FishController.php backend/tests/Feature/BreedCatalogTest.php
git commit -m "$(cat <<'EOF'
perf(backend): add Cache-Control: public, max-age=3600 on /fishes/breeds (SPEC §9)

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 16: Frontend — Lint cleanup

**Files:**
- Modify: any source file with an outstanding `eslint` warning.

- [ ] **Step 1: List warnings.**

```bash
cd frontend && npm run lint 2>&1 | tail -40
```

Typical residue:
- Unused `_url`/`_init` in `app/api/proxy/[...path]/route.ts`.
- Missing `font-display` on Inter font.
- Unused TS imports left over from the regen.

- [ ] **Step 2: Resolve each:**
  - Prefix unused with `_` or use them legitimately.
  - For Inter font: `Inter({ subsets: ['latin'], display: 'swap' })`.
  - Remove unused imports.

- [ ] **Step 3: Clean.**

```bash
cd frontend && npm run lint 2>&1 | tail -10
```

Expected: 0 warnings, 0 errors.

- [ ] **Step 4: Commit.**

```bash
git add frontend/src
git commit -m "$(cat <<'EOF'
chore(frontend): clear lint warnings (unused params, Inter font-display, stale imports)

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

# Phase D — Playwright

## Task 17: Frontend — Install Playwright + config

**Files:**
- Modify: `frontend/package.json`
- Create: `frontend/playwright.config.ts`
- Create: `frontend/tests/e2e/global-setup.ts`
- Create: `frontend/tests/e2e/fixtures/aquarium-bg-1280x720.png` (binary, 1280×720)

- [ ] **Step 1: Install.**

```bash
cd frontend
npm install --save-dev @playwright/test@^1.49 wait-on@^8
```

- [ ] **Step 2: Add scripts to `package.json`.**

```json
{
  "scripts": {
    "test:e2e":         "playwright test",
    "test:e2e:headed":  "playwright test --headed",
    "test:e2e:debug":   "playwright test --debug"
  }
}
```

- [ ] **Step 3: Create `playwright.config.ts`.**

```ts
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  globalSetup: require.resolve('./tests/e2e/global-setup.ts'),
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['html', { open: 'never' }], ['github']] : 'list',
  use: {
    baseURL: 'http://localhost:3000',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
});
```

- [ ] **Step 4: Create `tests/e2e/global-setup.ts`.**

```ts
import waitOn from 'wait-on';

export default async function globalSetup(): Promise<void> {
  await waitOn({
    resources: [
      'http://localhost:3000',
      'http://localhost:8000/api/v1/health',
    ],
    timeout: 120_000,
    interval: 1_000,
  });
}
```

- [ ] **Step 5: Add fixture image.** Use the seed `default-bg.png` from slice 4 or generate one:

```bash
# If you have ImageMagick:
magick -size 1280x720 gradient:'#1a3a5c-#0a1a2c' frontend/tests/e2e/fixtures/aquarium-bg-1280x720.png
# Otherwise, copy any existing 1280×720+ PNG from the repo:
cp backend/tests/fixtures/backgrounds/sample-1280x720.png frontend/tests/e2e/fixtures/aquarium-bg-1280x720.png
```

- [ ] **Step 6: Confirm Playwright finds the config.**

```bash
cd frontend && npx playwright test --list 2>&1 | head -10
```

Expected: lists "Total: 0 tests" (no spec files yet — those are Task 19+).

- [ ] **Step 7: Commit.**

```bash
cd ..
git add frontend/package.json frontend/package-lock.json frontend/playwright.config.ts frontend/tests/e2e/global-setup.ts frontend/tests/e2e/fixtures/aquarium-bg-1280x720.png
git commit -m "$(cat <<'EOF'
chore(frontend): install Playwright + wait-on; ship playwright.config.ts + globalSetup

Chromium only; serial workers; trace on first retry; report on failure only.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 18: Frontend — E2E helpers (`users`, `auth`, `repo-mock`)

**Files:**
- Create: `frontend/tests/e2e/helpers/users.ts`
- Create: `frontend/tests/e2e/helpers/auth.ts`
- Create: `frontend/tests/e2e/helpers/repo-mock.ts`
- Create: `frontend/tests/e2e/fixtures/repo-aquarium-stats.json`

- [ ] **Step 1: `users.ts`.**

```ts
import type { Page } from '@playwright/test';

export type TestUser = { username: string; email: string; password: string };

export async function registerUser(page: Page, overrides: Partial<TestUser> = {}): Promise<TestUser> {
  const stamp = Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
  const user: TestUser = {
    username: overrides.username ?? `e2e_${stamp}`,
    email:    overrides.email    ?? `e2e-${stamp}@fishbook.test`,
    password: overrides.password ?? 'CorrectHorseBatteryStaple9!',
  };

  await page.goto('/register');
  await page.getByLabel(/username/i).fill(user.username);
  await page.getByLabel(/email/i).fill(user.email);
  await page.getByLabel(/^password$/i).fill(user.password);
  await page.getByLabel(/confirm/i).fill(user.password);
  await page.getByRole('button', { name: /register|sign up/i }).click();
  await page.waitForURL(/\/(fish|onboarding)/);
  return user;
}
```

- [ ] **Step 2: `auth.ts`.**

```ts
import type { Page } from '@playwright/test';
import type { TestUser } from './users';

export async function loginAs(page: Page, user: TestUser): Promise<void> {
  await page.goto('/login');
  await page.getByLabel(/username/i).fill(user.username);
  await page.getByLabel(/password/i).fill(user.password);
  await page.getByRole('button', { name: /log in|sign in/i }).click();
  await page.waitForURL(/\/fish/);
}

export async function logout(page: Page): Promise<void> {
  await page.getByRole('button', { name: /log out|sign out/i }).click();
  await page.waitForURL(/\/(login|$)/);
}
```

- [ ] **Step 3: `repo-mock.ts`.**

```ts
import type { Page } from '@playwright/test';
import stats from '../fixtures/repo-aquarium-stats.json' assert { type: 'json' };

export async function mockGithubApi(page: Page, owner: string, repo: string): Promise<void> {
  await page.route(`https://api.github.com/repos/${owner}/${repo}`, (route) =>
    route.fulfill({
      status: 200,
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify(stats.repo),
    }),
  );
  await page.route(
    `https://api.github.com/repos/${owner}/${repo}/contributors?per_page=1&anon=true`,
    (route) =>
      route.fulfill({
        status: 200,
        headers: {
          'content-type': 'application/json',
          link: `<https://api.github.com/repositories/x/contributors?per_page=1&page=${stats.contributors_count}>; rel="last"`,
        },
        body: JSON.stringify([stats.contributors_first_entry]),
      }),
  );
}
```

- [ ] **Step 4: `repo-aquarium-stats.json`.**

```json
{
  "repo": {
    "stargazers_count": 116000,
    "forks_count": 25000,
    "open_issues_count": 2400,
    "subscribers_count": 750,
    "language": "TypeScript",
    "created_at": "2018-06-01T00:00:00Z"
  },
  "contributors_count": 480,
  "contributors_first_entry": { "login": "vercel", "contributions": 9999 }
}
```

- [ ] **Step 5: Commit.**

```bash
git add frontend/tests/e2e/helpers frontend/tests/e2e/fixtures/repo-aquarium-stats.json
git commit -m "$(cat <<'EOF'
test(frontend): add Playwright helpers (users, auth, repo-mock) + stats fixture

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 19: Frontend — Spec `01-auth.spec.ts`

**Files:**
- Create: `frontend/tests/e2e/01-auth.spec.ts`

- [ ] **Step 1: Spec.**

```ts
import { expect, test } from '@playwright/test';
import { registerUser } from './helpers/users';

test('SPEC §17 item 1 — register, log in, see empty aquarium', async ({ page }) => {
  await registerUser(page);
  await expect(page).toHaveURL(/\/fish/);
  const canvas = page.getByTestId('aquarium-canvas');
  await expect(canvas).toBeVisible();
  await expect(canvas).toHaveAttribute('data-fish-count', '0');
});
```

(Requires Task 22's `data-fish-count` attribute on the canvas.)

- [ ] **Step 2: Defer running this until Task 22 ships the DOM hook.** Commit the spec as a forward reference.

```bash
git add frontend/tests/e2e/01-auth.spec.ts
git commit -m "$(cat <<'EOF'
test(e2e): SPEC §17 item 1 — register, log in, see empty aquarium (data-fish-count assertion pending Task 22)

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 20: Frontend — Spec `02-fish-crud.spec.ts`

**Files:**
- Create: `frontend/tests/e2e/02-fish-crud.spec.ts`

- [ ] **Step 1: Spec.**

```ts
import { expect, test } from '@playwright/test';
import { registerUser } from './helpers/users';

test('SPEC §17 items 2–5 — add, hover, feed, manage, edit, delete', async ({ page }) => {
  await registerUser(page);

  // Add fish.
  await page.getByRole('button', { name: /add fish/i }).click();
  await page.getByLabel(/nickname/i).fill('Splash');
  await page.getByLabel(/breed/i).selectOption('guppy');
  await page.getByLabel(/size/i).fill('14');
  await page.getByRole('button', { name: /save|add/i }).click();
  const canvas = page.getByTestId('aquarium-canvas');
  await expect(canvas).toHaveAttribute('data-fish-count', '1');

  // Hover.
  const box = await canvas.boundingBox();
  if (!box) throw new Error('canvas not laid out');
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
  await expect(page.getByTestId('hover-tooltip')).toContainText(/Splash/);

  // Feed (click → pellet).
  await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
  await expect(canvas).toHaveAttribute('data-pellet-count', /[1-9]/);

  // Manage modal + edit + delete.
  await page.getByRole('button', { name: /manage/i }).click();
  await page.getByPlaceholder(/search/i).fill('Splash');
  await page.getByRole('button', { name: /edit/i }).first().click();
  await page.getByLabel(/nickname/i).fill('Splashy');
  await page.getByRole('button', { name: /save/i }).click();
  await page.getByRole('button', { name: /delete/i }).first().click();
  await page.getByRole('button', { name: /confirm|yes/i }).click();
  await expect(canvas).toHaveAttribute('data-fish-count', '0');
});
```

- [ ] **Step 2: Commit.**

```bash
git add frontend/tests/e2e/02-fish-crud.spec.ts
git commit -m "$(cat <<'EOF'
test(e2e): SPEC §17 items 2–5 — fish CRUD, hover tooltip, feed-pellet

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 21: Frontend — Specs `03-backgrounds` + `04-repo-aquarium`

**Files:**
- Create: `frontend/tests/e2e/03-backgrounds.spec.ts`
- Create: `frontend/tests/e2e/04-repo-aquarium.spec.ts`

- [ ] **Step 1: `03-backgrounds.spec.ts`.**

```ts
import path from 'node:path';
import { expect, test } from '@playwright/test';
import { registerUser } from './helpers/users';

test('SPEC §17 item 6 — upload background ≥ 1280×720', async ({ page }) => {
  await registerUser(page);
  await page.getByRole('button', { name: /backgrounds/i }).click();
  await page.getByRole('tab', { name: /upload/i }).click();
  const file = path.resolve(__dirname, 'fixtures/aquarium-bg-1280x720.png');
  await page.setInputFiles('input[type="file"]', file);
  await page.getByRole('button', { name: /upload/i }).click();
  await expect(page.getByText(/active/i)).toBeVisible();
});

test.skip(
  !process.env.FAL_API_KEY,
  'SPEC §17 item 7 — generate background requires FAL_API_KEY (paid; not in CI)',
);
```

- [ ] **Step 2: `04-repo-aquarium.spec.ts`.**

```ts
import { expect, test } from '@playwright/test';
import { mockGithubApi } from './helpers/repo-mock';
import { registerUser } from './helpers/users';
import { loginAs } from './helpers/auth';

test('SPEC §17 items 8–9 — view repo aquarium then fork (authed)', async ({ page }) => {
  await mockGithubApi(page, 'vercel', 'next.js');

  // Unauthed visit.
  await page.goto('/vercel/next.js');
  await expect(page.getByTestId('aquarium-canvas')).toBeVisible();
  await expect(page.getByRole('link', { name: /sign in to fork/i })).toBeVisible();

  // Register, then revisit + fork.
  const user = await registerUser(page);
  await mockGithubApi(page, 'vercel', 'next.js');
  await page.goto('/vercel/next.js');
  await page.getByRole('button', { name: /fork to my aquarium/i }).click();
  await expect(page.getByText(/added \d+ fish/i)).toBeVisible();

  await page.goto('/fish');
  await expect(page.getByTestId('aquarium-canvas')).toHaveAttribute(
    'data-fish-count',
    /[1-9]\d*/,
  );
});
```

- [ ] **Step 3: Commit.**

```bash
git add frontend/tests/e2e/03-backgrounds.spec.ts frontend/tests/e2e/04-repo-aquarium.spec.ts
git commit -m "$(cat <<'EOF'
test(e2e): SPEC §17 items 6 (upload), 8–9 (repo aquarium + fork); item 7 skipped (no FAL_API_KEY in CI)

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 22: Frontend — DOM hooks for E2E (`data-fish-count`, `data-pellet-count`)

**Files:**
- Modify: `frontend/src/components/aquarium/AquariumCanvas.tsx`

- [ ] **Step 1: Patch.**

In the fish-sync `useEffect`, after the existing reconciliation, set the attribute:

```ts
canvasRef.current?.setAttribute('data-fish-count', String(fishMapRef.current.size));
```

For pellets, a separate effect that polls the ref via `useAquariumStore`'s subscribe, or — simpler — update the attribute from the RAF tick *only when the count changes* (track `lastPelletCount` in a ref to avoid touching DOM 60×/s):

```ts
const lastPelletCountRef = useRef(-1);
// inside tick(), after pellet filter:
if (pelletsRef.current.length !== lastPelletCountRef.current) {
  lastPelletCountRef.current = pelletsRef.current.length;
  canvas.setAttribute('data-pellet-count', String(pelletsRef.current.length));
}
```

- [ ] **Step 2: Run the canvas Vitest tests + add assertions for the attributes.**

```bash
cd frontend && npm test -- --run tests/unit/components/aquarium
```

- [ ] **Step 3: Commit.**

```bash
git add frontend/src/components/aquarium/AquariumCanvas.tsx
git commit -m "$(cat <<'EOF'
test(frontend): expose data-fish-count + data-pellet-count on the canvas for E2E assertions

Mutations happen outside the hot render path (fish-count in the sync useEffect,
pellet-count only when delta detected). No 60Hz DOM writes.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

# Phase F — Docker shakedown + Phase E — CI

## Task 23: Cross — `docker-compose.e2e.yml` overlay + first real build

**Files:**
- Create: `docker-compose.e2e.yml`

- [ ] **Step 1: Overlay file.**

```yaml
services:
  backend:
    build:
      context: ./backend
      target: app
    volumes: []                # no source mount in E2E mode
    command: php artisan serve --host=0.0.0.0 --port=8000

  frontend:
    build:
      context: ./frontend
      target: runner
    volumes: []
    command: npm run start
```

- [ ] **Step 2: First real build.**

```bash
docker compose -f docker-compose.yml -f docker-compose.e2e.yml build 2>&1 | tail -30
```

Expected: both stages build cleanly. **If failures occur (composer cache, PHP extension, sprite COPY paths, Next.js standalone output), fix in this task — each fix is its own sub-commit.**

Common failures + fixes:
- Backend `composer install` warnings about scripts → add `--no-scripts` to the deps stage.
- Frontend `next build` fails on missing `BACKEND_INTERNAL_URL` at build time → declare it in `.env.example` and ensure the Dockerfile copies `.env.example` to `.env` during build.
- Sprites missing → confirm `COPY . .` includes `public/sprites/`.

- [ ] **Step 3: Bring up the prod stack + smoke.**

```bash
docker compose -f docker-compose.yml -f docker-compose.e2e.yml up -d
sleep 10
curl -fsS http://localhost:8000/api/v1/health
curl -fsS http://localhost:3000
```

Expected: both return 200.

- [ ] **Step 4: Run migrate.**

```bash
docker compose -f docker-compose.yml -f docker-compose.e2e.yml exec -T backend php artisan migrate --force
```

- [ ] **Step 5: Run Playwright locally against the prod stack.**

```bash
cd frontend && npx playwright install --with-deps chromium
npx playwright test
```

Expected: all 4 specs pass. Investigate + fix any failures (most likely DOM-locator or timing issues — adjust spec waits, NOT app code, unless the app has a real bug).

- [ ] **Step 6: Commit (separately if there were Dockerfile fixes).**

```bash
cd ..
git add docker-compose.e2e.yml
# plus any Dockerfile patches accumulated in Step 2
git commit -m "$(cat <<'EOF'
ci: add docker-compose.e2e.yml overlay using prod build targets

Local devs keep their hot-reload base stack; E2E + CI use the production
build targets ('app' for backend, 'runner' for frontend) to exercise the
shippable images.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 24: CI — `.github/workflows/e2e.yml`

**Files:**
- Create: `.github/workflows/e2e.yml`

- [ ] **Step 1: Workflow.**

```yaml
name: e2e

on:
  pull_request:
    paths: [frontend/**, backend/**, .github/workflows/e2e.yml, docker-compose.yml, docker-compose.e2e.yml]
  push:
    branches: [main]
    paths: [frontend/**, backend/**, .github/workflows/e2e.yml, docker-compose.yml, docker-compose.e2e.yml]

jobs:
  e2e:
    runs-on: ubuntu-latest
    timeout-minutes: 25
    steps:
      - uses: actions/checkout@v4

      - uses: docker/setup-buildx-action@v3

      - name: Seed envs
        run: |
          cp backend/.env.example backend/.env
          cp frontend/.env.example frontend/.env
          echo "APP_KEY=base64:$(openssl rand -base64 32)" >> backend/.env

      - name: Build images
        run: docker compose -f docker-compose.yml -f docker-compose.e2e.yml build

      - name: Bring up stack
        run: docker compose -f docker-compose.yml -f docker-compose.e2e.yml up -d

      - name: Wait for healthchecks
        run: |
          for i in {1..120}; do
            if curl -fsS http://localhost:8000/api/v1/health \
              && curl -fsS http://localhost:3000; then
              echo healthy && exit 0
            fi
            sleep 1
          done
          docker compose -f docker-compose.yml -f docker-compose.e2e.yml logs --tail=200
          exit 1

      - name: Migrate
        run: docker compose -f docker-compose.yml -f docker-compose.e2e.yml exec -T backend php artisan migrate --force

      - uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'
          cache-dependency-path: frontend/package-lock.json

      - name: Install frontend deps + Playwright browsers
        working-directory: frontend
        run: |
          npm ci
          npx playwright install --with-deps chromium

      - name: Run Playwright
        working-directory: frontend
        run: npx playwright test

      - name: Upload report (failure only)
        if: failure()
        uses: actions/upload-artifact@v4
        with:
          name: playwright-report
          path: frontend/playwright-report
          retention-days: 14

      - name: Tear down
        if: always()
        run: docker compose -f docker-compose.yml -f docker-compose.e2e.yml down -v
```

- [ ] **Step 2: Open a PR to validate the workflow runs.**

```bash
git add .github/workflows/e2e.yml
git commit -m "$(cat <<'EOF'
ci: add e2e.yml — docker compose prod stack + Playwright on PRs

Triggers on frontend/** + backend/** changes. Builds prod images, runs migrate,
runs Playwright Chromium specs, uploads HTML report on failure (14-day retention).

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

- [ ] **Step 3: Push branch + open PR. Verify the workflow runs to completion.**

```bash
git push -u origin slice-6-e2e-perf-polish
gh pr create --title 'Slice 6 — E2E, Perf & Polish' --body 'See docs/superpowers/specs/2026-05-16-slice-6-e2e-perf-polish-design.md'
gh pr checks --watch
```

Expected: `e2e` job green; `backend`, `frontend` jobs also green.

---

## Task 25: Slice close — tag + housekeeping

**Files:** (none — repo-level)

- [ ] **Step 1: Final pre-merge gates.**

```bash
docker compose exec backend ./vendor/bin/pest --colors=always
docker compose exec backend ./vendor/bin/phpstan analyse --memory-limit=1G
docker compose exec backend ./vendor/bin/pint --test
cd frontend && npm run lint && npm run typecheck && npm test -- --run && npm run build
cd ..
```

Expected: all green.

- [ ] **Step 2: Confirm OpenAPI + client are in sync (no drift since the last regen).**

```bash
docker compose exec backend php artisan l5-swagger:generate
git diff --exit-code backend/storage/api-docs/openapi.json
cd frontend && rm -rf src/lib/api-client && \
  npx openapi-generator-cli generate -i ../backend/storage/api-docs/openapi.json -g typescript-fetch -o src/lib/api-client
git diff --exit-code src/lib/api-client
cd ..
```

Expected: both exit 0.

- [ ] **Step 3: Merge to `main`.**

- [ ] **Step 4: Tag.**

```bash
git checkout main && git pull
git tag -a slice-6-e2e-perf-polish -m "Slice 6 — E2E, Perf & Polish: ULID ids, sprite preload, Playwright suite, e2e.yml"
git push origin slice-6-e2e-perf-polish
```

- [ ] **Step 5: Update `README.md` with the new `test:e2e` script + the `docker-compose.e2e.yml` note.** Commit as `docs:`.

---

## Open Questions (carried from spec §12, to confirm before slice 7)

- Should canvas show a "loading" state during initial preload? *Default: no.*
- Should the Vitest perf harness be a hard CI gate? *Default: yes (hard-fail) with `skip-perf` PR label override.*
- Should E2E specs run in parallel (`workers: > 1`)? *Default: serial in slice 6; revisit in slice 7.*
- Should the Playwright report be uploaded on success too? *Default: failure-only.*
- Should we preserve the hash-named HealthController OpenAPI model file for one release cycle? *Default: no.*
- `docker-compose.e2e.yml` vs flipping `target` in the base file conditionally? *Default: separate overlay file.*
- Should slice 6's tag be `v1.0.0-rc1` instead of `slice-6-e2e-perf-polish`? *Default: keep slice-N through slice 7.*

---

## Sources

- Spec: `docs/superpowers/specs/2026-05-16-slice-6-e2e-perf-polish-design.md`
- `SPEC.md` §1, §6, §12, §13, §17 items 1–9 + 11.
- `AGENT.md` §3 (canvas imperative), §5 (E2E in CI), §6 (perf budgets), §10 (quick reference).
- Slice 3 deferred items (sprite preload, hovered-fish target rotation).
- Slice 5 plan — for the per-task scaffolding rhythm, commit conventions, and OpenAPI regen + client regen dance.
