# Slice 5 — GitHub Repo Aquarium Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` (or `superpowers:subagent-driven-development` for parallelizable chunks) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking; do **not** mark a checkbox until the step's expected output is observed.

**Goal:** Ship the public GitHub-repo aquarium end-to-end. After this slice, a visitor (authed or not) can hit `/{owner}/{repo}` and see a deterministic aquarium derived from that repo's GitHub stats; authed users see a "Fork to My Aquarium" button that materializes those fish into their own owned `fishes` rows. SPEC §17 acceptance items **8** and **9** are satisfied.

**Architecture:** Backend adds a `repo_aquarium_cache` table (no soft-delete; cache rows overwritten), a `GithubStatsClient` (Laravel `Http` against `https://api.github.com` with `Authorization: Bearer $GITHUB_TOKEN` optional, `connectTimeout(5)->timeout(30)->retry(2,1000)`, `allow_redirects: false`), a `RepoAquariumGenerator` (deterministic from `crc32($owner.'/'.$repo)`, tier math + allocation table per SPEC §5, ≤ 100-fish cap), a `BreedAccentMap` config service, a `RepoAquariumService` orchestrating L1-Redis-10min → L2-DB → L3-GitHub with a `Cache::lock(…)->block(5, …)` around the L3 path, a `RepoAquariumController` (public `show` + authed `fork`), `Route::pattern` constraints on `owner`/`repo`, named OpenAPI schemas (`RepoAquariumResponse`, `RepoForkResponse`, `RepoStats`, `RepoFishItem`, `RepoAquariumErrorResponse`) with explicit `operationId`s. Frontend adds a Server Component at `app/[username]/[repo]/page.tsx` that fetches `BACKEND_INTERNAL_URL` directly (bypassing the iron-session proxy — the endpoint is public), a `RepoAquariumPage` client component (stats panel + Fork CTA + read-only canvas), a `readOnly` prop on `AquariumCanvas` that suppresses CRUD UI but keeps food-dropping live, and a `useForkRepoMutation` hook.

**Tech Stack:** Laravel 13 + PHP 8.3 + Pest + Larastan (no new backend deps; uses built-in `Http` and `Cache::lock`). Next.js 16 + React 19 + TS strict (no new frontend deps).

**Spec:** [`docs/superpowers/specs/2026-05-16-slice-5-repo-aquarium-design.md`](../specs/2026-05-16-slice-5-repo-aquarium-design.md).

---

## Conventions

- Today's date for commit messages is **2026-05-16**.
- All backend commands use `docker compose exec backend …` so Postgres `jsonb` and Redis are real.
- Conventional Commits (`feat:`, `fix:`, `chore:`, `test:`, `docs:`, `refactor:`, `ci:`).
- One task = one commit. Don't squash.
- Commit trailer:
  ```
  Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
  ```
  Use heredoc form (see slice 4 plan §Conventions).
- TDD for every endpoint and service method: failing test first, then code, then green.
- Build on `main` after slice 4's `slice-4-backgrounds` tag.
- Frontend tests run with `cd frontend && npm test -- --run`; backend tests run with `docker compose exec backend ./vendor/bin/pest`.

---

## Task 1: Slice prep — verify slice 4 baseline + GitHub token presence

**Files:**
- (none — read-only verification)

- [ ] **Step 1: Confirm clean tree on `main` at slice 4 tag.**

```bash
git status
git describe --tags --abbrev=0
```

Expected: working tree clean; tag prints `slice-4-backgrounds`.

- [ ] **Step 2: Confirm slice-3 frontend surfaces exist + slice-4 OpenAPI is in sync.**

```bash
test -f frontend/src/lib/aquarium/seeded-random.ts && echo OK-seeded-random
test -f frontend/src/components/aquarium/AquariumCanvas.tsx && echo OK-canvas
test -f backend/storage/api-docs/openapi.json && echo OK-openapi
docker compose up -d db redis
sleep 3
docker compose exec backend php artisan migrate --pretend | head -5
```

Expected: all `OK-` lines print; migrate-pretend shows the slice 4 background migration as applied.

- [ ] **Step 3: Confirm `GITHUB_TOKEN` slot is present in `.env.example`.**

```bash
grep -n '^GITHUB_TOKEN=' backend/.env.example
```

Expected: line exists (may be empty value — that's fine; anonymous fallback works).

- [ ] **Step 4: No commit.** Verification only.

---

## Task 2: Backend — `config/services.php` `github` block

**Files:**
- Modify: `backend/config/services.php`

- [ ] **Step 1: Append a `github` block at the end of the returned array.**

```php
'github' => [
    'token'              => env('GITHUB_TOKEN'),
    'base_url'           => env('GITHUB_BASE_URL', 'https://api.github.com'),
    'user_agent'         => env('GITHUB_USER_AGENT', 'Fishbook/1.0 (+https://fishbook.neri.ph)'),
    'cache_ttl_seconds'  => (int) env('GITHUB_CACHE_TTL', 600),
    'lock_ttl_seconds'   => (int) env('GITHUB_LOCK_TTL', 60),
    'lock_block_seconds' => (int) env('GITHUB_LOCK_BLOCK', 5),
    'cache_key_version'  => 'v1',
    'language_colors'    => [
        'JavaScript' => '#F7DF1E', 'TypeScript' => '#3178C6', 'Python' => '#3776AB',
        'Ruby'       => '#CC342D', 'Go'         => '#00ADD8', 'Rust'   => '#DEA584',
        'PHP'        => '#777BB4', 'Java'       => '#ED8B00', 'C'      => '#A8B9CC',
        'C++'        => '#00599C', 'Shell'      => '#89E051',
    ],
],
```

- [ ] **Step 2: Sanity check.**

```bash
docker compose exec backend php -r "var_dump(config('services.github.cache_ttl_seconds'));"
```

Expected: `int(600)`.

- [ ] **Step 3: Commit.**

```bash
git add backend/config/services.php
git commit -m "$(cat <<'EOF'
chore(backend): add services.github config block (token, base_url, cache TTL, lock TTL, language colors)
EOF
)"
```

---

## Task 3: Backend — `repo_aquarium_cache` migration

**Files:**
- Create: `backend/database/migrations/2026_05_16_000020_create_repo_aquarium_cache_table.php`

- [ ] **Step 1: Create the migration.**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repo_aquarium_cache', function (Blueprint $table) {
            $table->id();
            $table->string('owner', 100);
            $table->string('repo', 100);
            $table->jsonb('stats_json');
            $table->jsonb('fish_set_json');
            $table->timestamp('fetched_at');

            $table->unique(['owner', 'repo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repo_aquarium_cache');
    }
};
```

- [ ] **Step 2: Run + verify.**

```bash
docker compose exec backend php artisan migrate
docker compose exec db psql -U fishbook -d fishbook -c "\d repo_aquarium_cache"
```

Expected: columns `id, owner, repo, stats_json (jsonb), fish_set_json (jsonb), fetched_at`; unique index `repo_aquarium_cache_owner_repo_unique`.

- [ ] **Step 3: Commit.**

```bash
git add backend/database/migrations/2026_05_16_000020_create_repo_aquarium_cache_table.php
git commit -m "$(cat <<'EOF'
feat(backend): create repo_aquarium_cache table with unique (owner, repo) (SPEC §3)
EOF
)"
```

---

## Task 4: Backend — `RepoAquariumCache` model

**Files:**
- Create: `backend/app/Models/RepoAquariumCache.php`

- [ ] **Step 1: Model.**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RepoAquariumCache extends Model
{
    protected $table = 'repo_aquarium_cache';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['owner', 'repo', 'stats_json', 'fish_set_json', 'fetched_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'stats_json'    => 'array',
            'fish_set_json' => 'array',
            'fetched_at'    => 'datetime',
        ];
    }

    /** @param Builder<RepoAquariumCache> $q */
    public function scopeNotStale(Builder $q, int $ttlSeconds): Builder
    {
        return $q->where('fetched_at', '>=', now()->subSeconds($ttlSeconds));
    }
}
```

- [ ] **Step 2: Phpstan.**

```bash
docker compose exec backend ./vendor/bin/phpstan analyse app/Models/RepoAquariumCache.php
```

Expected: 0 errors.

- [ ] **Step 3: Commit.**

```bash
git add backend/app/Models/RepoAquariumCache.php
git commit -m "$(cat <<'EOF'
feat(backend): add RepoAquariumCache Eloquent model with notStale scope
EOF
)"
```

---

## Task 5: Backend — Github exception classes

**Files:**
- Create: `backend/app/Exceptions/Github/RepoNotFoundException.php`
- Create: `backend/app/Exceptions/Github/RepoForbiddenException.php`
- Create: `backend/app/Exceptions/Github/GithubUnavailableException.php`

- [ ] **Step 1: Three thin exception classes.**

```php
<?php
// RepoNotFoundException.php
namespace App\Exceptions\Github;
use RuntimeException;
class RepoNotFoundException extends RuntimeException {}
```

```php
<?php
// RepoForbiddenException.php
namespace App\Exceptions\Github;
use RuntimeException;
class RepoForbiddenException extends RuntimeException {}
```

```php
<?php
// GithubUnavailableException.php
namespace App\Exceptions\Github;
use RuntimeException;
class GithubUnavailableException extends RuntimeException {}
```

- [ ] **Step 2: Commit.**

```bash
git add backend/app/Exceptions/Github/
git commit -m "$(cat <<'EOF'
feat(backend): add Github exception classes (RepoNotFound, RepoForbidden, GithubUnavailable)
EOF
)"
```

---

## Task 6: Backend — `BreedAccentMap` service + test

**Files:**
- Create: `backend/app/Services/Github/BreedAccentMap.php`
- Create: `backend/tests/Unit/Services/Github/BreedAccentMapTest.php`

- [ ] **Step 1: Test first.**

```php
<?php
namespace Tests\Unit\Services\Github;

use App\Services\Github\BreedAccentMap;
use Tests\TestCase;

class BreedAccentMapTest extends TestCase
{
    public function test_known_language_returns_hex(): void
    {
        $m = app(BreedAccentMap::class);
        $this->assertSame('#3178C6', $m->for('TypeScript'));
        $this->assertSame('#3776AB', $m->for('Python'));
    }

    public function test_unknown_language_returns_null(): void
    {
        $this->assertNull(app(BreedAccentMap::class)->for('Pony'));
    }

    public function test_null_language_returns_null(): void
    {
        $this->assertNull(app(BreedAccentMap::class)->for(null));
    }
}
```

- [ ] **Step 2: Service.**

```php
<?php
namespace App\Services\Github;

use Illuminate\Contracts\Config\Repository;

class BreedAccentMap
{
    public function __construct(private readonly Repository $config) {}

    public function for(?string $language): ?string
    {
        if ($language === null) {
            return null;
        }
        $map = (array) $this->config->get('services.github.language_colors', []);
        return $map[$language] ?? null;
    }
}
```

- [ ] **Step 3: Run.**

```bash
docker compose exec backend ./vendor/bin/pest tests/Unit/Services/Github/BreedAccentMapTest.php
```

Expected: 3 passing.

- [ ] **Step 4: Commit.**

```bash
git add backend/app/Services/Github/BreedAccentMap.php backend/tests/Unit/Services/Github/BreedAccentMapTest.php
git commit -m "$(cat <<'EOF'
feat(backend): add BreedAccentMap config-backed language→hex lookup
EOF
)"
```

---

## Task 7: Backend — `GithubStatsClient` (TDD)

**Files:**
- Create: `backend/app/Services/Github/GithubStatsClient.php`
- Create: `backend/tests/Unit/Services/Github/GithubStatsClientTest.php`

- [ ] **Step 1: Test first.** Use `Http::fake()` to stub each scenario.

```php
<?php
namespace Tests\Unit\Services\Github;

use App\Exceptions\Github\GithubUnavailableException;
use App\Exceptions\Github\RepoForbiddenException;
use App\Exceptions\Github\RepoNotFoundException;
use App\Services\Github\GithubStatsClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

class GithubStatsClientTest extends TestCase
{
    public function test_happy_path_returns_normalized_stats(): void
    {
        Http::fake([
            'api.github.com/repos/vercel/next.js' => Http::response([
                'stargazers_count'  => 116000,
                'forks_count'       => 25000,
                'open_issues_count' => 2400,
                'subscribers_count' => 750,
                'language'          => 'TypeScript',
                'created_at'        => now()->subDays(2900)->toISOString(),
            ], 200),
            'api.github.com/repos/vercel/next.js/contributors*' => Http::response(
                [['login' => 'a']],
                200,
                ['Link' => '<https://api.github.com/repositories/...?per_page=1&page=480>; rel="last"']
            ),
        ]);

        $stats = app(GithubStatsClient::class)->fetchStats('vercel', 'next.js');

        $this->assertSame(116000, $stats['stars']);
        $this->assertSame(25000,  $stats['forks']);
        $this->assertSame(2400,   $stats['issues']);
        $this->assertSame(750,    $stats['watchers']);
        $this->assertSame(480,    $stats['contributors']);
        $this->assertSame('TypeScript', $stats['language']);
        $this->assertEqualsWithDelta(2900, $stats['age_days'], 1);
    }

    public function test_404_throws_repo_not_found(): void
    {
        Http::fake(['api.github.com/repos/*' => Http::response([], 404)]);
        $this->expectException(RepoNotFoundException::class);
        app(GithubStatsClient::class)->fetchStats('no', 'such-repo');
    }

    public function test_403_throws_repo_forbidden(): void
    {
        Http::fake(['api.github.com/repos/*' => Http::response([], 403)]);
        $this->expectException(RepoForbiddenException::class);
        app(GithubStatsClient::class)->fetchStats('priv', 'repo');
    }

    public function test_5xx_retried_then_throws(): void
    {
        Http::fake(['api.github.com/repos/*' => Http::response([], 502)]);
        $this->expectException(GithubUnavailableException::class);
        app(GithubStatsClient::class)->fetchStats('flaky', 'repo');
    }

    public function test_no_link_header_falls_back_to_body_count(): void
    {
        Http::fake([
            'api.github.com/repos/tiny/repo' => Http::response([
                'stargazers_count' => 1, 'forks_count' => 0, 'open_issues_count' => 0,
                'subscribers_count' => 1, 'language' => null,
                'created_at' => now()->subDays(10)->toISOString(),
            ], 200),
            'api.github.com/repos/tiny/repo/contributors*' => Http::response([['login' => 'solo']], 200),
        ]);
        $this->assertSame(1, app(GithubStatsClient::class)->fetchStats('tiny', 'repo')['contributors']);
    }

    public function test_empty_contributors_returns_zero(): void
    {
        Http::fake([
            'api.github.com/repos/empty/repo' => Http::response([
                'stargazers_count' => 0, 'forks_count' => 0, 'open_issues_count' => 0,
                'subscribers_count' => 0, 'language' => null,
                'created_at' => now()->subDays(1)->toISOString(),
            ], 200),
            'api.github.com/repos/empty/repo/contributors*' => Http::response([], 200),
        ]);
        $this->assertSame(0, app(GithubStatsClient::class)->fetchStats('empty', 'repo')['contributors']);
    }

    public function test_bearer_header_set_when_token_present(): void
    {
        config()->set('services.github.token', 'ghp_TESTTOKEN');
        Http::fake(['api.github.com/*' => Http::response([
            'stargazers_count'=>0,'forks_count'=>0,'open_issues_count'=>0,'subscribers_count'=>0,
            'language'=>null,'created_at'=>now()->toISOString(),
        ], 200)]);
        app(GithubStatsClient::class)->fetchStats('o', 'r');
        Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'Bearer ghp_TESTTOKEN'));
    }

    public function test_no_bearer_header_when_token_absent(): void
    {
        config()->set('services.github.token', null);
        Http::fake(['api.github.com/*' => Http::response([
            'stargazers_count'=>0,'forks_count'=>0,'open_issues_count'=>0,'subscribers_count'=>0,
            'language'=>null,'created_at'=>now()->toISOString(),
        ], 200)]);
        app(GithubStatsClient::class)->fetchStats('o', 'r');
        Http::assertSent(fn ($req) => ! $req->hasHeader('Authorization'));
    }

    public function test_token_not_in_logs(): void
    {
        config()->set('services.github.token', 'ghp_SECRETLEAK');
        $handler = new TestHandler();
        Log::getLogger()->pushHandler($handler);

        Http::fake(['api.github.com/*' => Http::response([
            'stargazers_count'=>0,'forks_count'=>0,'open_issues_count'=>0,'subscribers_count'=>0,
            'language'=>null,'created_at'=>now()->toISOString(),
        ], 200)]);
        app(GithubStatsClient::class)->fetchStats('o', 'r');

        foreach ($handler->getRecords() as $rec) {
            $this->assertStringNotContainsString('ghp_SECRETLEAK', json_encode($rec));
            $this->assertStringNotContainsString('Bearer ', json_encode($rec));
        }
    }
}
```

- [ ] **Step 2: Implement.**

```php
<?php
namespace App\Services\Github;

use App\Exceptions\Github\GithubUnavailableException;
use App\Exceptions\Github\RepoForbiddenException;
use App\Exceptions\Github\RepoNotFoundException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

class GithubStatsClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly Repository $config,
    ) {}

    /** @return array{stars:int,forks:int,issues:int,watchers:int,contributors:int,language:?string,age_days:int,fetched_at:CarbonImmutable} */
    public function fetchStats(string $owner, string $repo): array
    {
        $repoBody = $this->callRepo($owner, $repo);
        $contributors = $this->callContributors($owner, $repo);

        $createdAt = CarbonImmutable::parse($repoBody['created_at']);
        return [
            'stars'        => (int) ($repoBody['stargazers_count'] ?? 0),
            'forks'        => (int) ($repoBody['forks_count'] ?? 0),
            'issues'       => (int) ($repoBody['open_issues_count'] ?? 0),
            'watchers'     => (int) ($repoBody['subscribers_count'] ?? 0),
            'contributors' => $contributors,
            'language'     => $repoBody['language'] ?? null,
            'age_days'     => (int) $createdAt->diffInDays(now()),
            'fetched_at'   => CarbonImmutable::now(),
        ];
    }

    /** @return array<string, mixed> */
    private function callRepo(string $owner, string $repo): array
    {
        $r = $this->client()->get("/repos/{$owner}/{$repo}");
        $this->guard($r);
        return $r->json() ?? [];
    }

    private function callContributors(string $owner, string $repo): int
    {
        $r = $this->client()->get("/repos/{$owner}/{$repo}/contributors", ['per_page' => 1, 'anon' => 'true']);
        $this->guard($r);

        $link = $r->header('Link');
        if (is_string($link) && preg_match('/page=(\d+)>;\s*rel="last"/', $link, $m)) {
            return (int) $m[1];
        }
        $body = $r->json();
        return is_array($body) ? count($body) : 0;
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        $req = $this->http
            ->baseUrl((string) $this->config->get('services.github.base_url'))
            ->withHeaders([
                'Accept'               => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent'           => (string) $this->config->get('services.github.user_agent'),
            ])
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout(5)
            ->timeout(30)
            ->retry(2, 1000, function ($ex, $req) {
                if ($ex instanceof ConnectionException) return true;
                return $ex instanceof \Illuminate\Http\Client\RequestException
                    && in_array($ex->response->status(), [429, 500, 502, 503, 504], true);
            }, throw: false);

        $token = $this->config->get('services.github.token');
        if (is_string($token) && $token !== '') {
            $req = $req->withToken($token); // sets `Authorization: Bearer …`
        }
        return $req;
    }

    private function guard(Response $r): void
    {
        if ($r->status() === 404) throw new RepoNotFoundException();
        if ($r->status() === 403) throw new RepoForbiddenException();
        if ($r->serverError() || $r->status() === 429) {
            Log::warning('github_unavailable', ['status' => $r->status()]);
            throw new GithubUnavailableException("GitHub responded {$r->status()}");
        }
        if (! $r->successful()) {
            throw new GithubUnavailableException("GitHub unexpected status {$r->status()}");
        }
    }
}
```

- [ ] **Step 3: Run.**

```bash
docker compose exec backend ./vendor/bin/pest tests/Unit/Services/Github/GithubStatsClientTest.php
```

Expected: 9 passing.

- [ ] **Step 4: Commit.**

```bash
git add backend/app/Services/Github/GithubStatsClient.php backend/tests/Unit/Services/Github/GithubStatsClientTest.php
git commit -m "$(cat <<'EOF'
feat(backend): add GithubStatsClient (Http-injected, retry+timeout, anon fallback, no-redirect SSRF guard)
EOF
)"
```

---

## Task 8: Backend — fixtures for `vercel/next.js` (stats + expected fish)

**Files:**
- Create: `backend/tests/fixtures/repo_aquarium/vercel-nextjs-stats.json`
- Create: `backend/tests/fixtures/repo_aquarium/vercel-nextjs-fish.json` (placeholder — generated in Task 10)

- [ ] **Step 1: Hand-fabricated stats fixture.**

```json
{
  "stars": 116000,
  "forks": 25000,
  "issues": 2400,
  "watchers": 750,
  "contributors": 480,
  "language": "TypeScript",
  "age_days": 2900,
  "fetched_at": "2026-05-16T12:00:00+00:00"
}
```

- [ ] **Step 2: Empty fish fixture (will be replaced after Task 10 generator runs once).**

```json
[]
```

- [ ] **Step 3: Commit (placeholder; Task 10 finalises).**

```bash
git add backend/tests/fixtures/repo_aquarium/
git commit -m "$(cat <<'EOF'
test(backend): add hand-fabricated vercel/next.js repo aquarium fixtures (stats + fish placeholder)
EOF
)"
```

---

## Task 9: Backend — `RepoAquariumGenerator` (TDD)

**Files:**
- Create: `backend/app/Services/Github/RepoAquariumGenerator.php`
- Create: `backend/tests/Unit/Services/Github/RepoAquariumGeneratorTest.php`

- [ ] **Step 1: Test first — determinism, tier boundaries, 100-cap.**

```php
<?php
namespace Tests\Unit\Services\Github;

use App\Services\Github\RepoAquariumGenerator;
use Tests\TestCase;

class RepoAquariumGeneratorTest extends TestCase
{
    private function stats(array $overrides = []): array
    {
        return array_merge([
            'stars' => 100, 'forks' => 30, 'issues' => 20, 'watchers' => 25,
            'contributors' => 6, 'language' => 'TypeScript', 'age_days' => 500,
        ], $overrides);
    }

    public function test_deterministic(): void
    {
        $gen = app(RepoAquariumGenerator::class);
        $a = $gen->generate('vercel', 'next.js', $this->stats());
        $b = $gen->generate('vercel', 'next.js', $this->stats());
        $this->assertSame(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR));
    }

    public function test_tier_boundary_stars(): void
    {
        $below = app(RepoAquariumGenerator::class)->generate('a','b', $this->stats(['stars' => 9]));
        $above = app(RepoAquariumGenerator::class)->generate('a','b', $this->stats(['stars' => 10]));
        $guppyBelow = collect($below)->where('breed','guppy')->count();
        $guppyAbove = collect($above)->where('breed','guppy')->count();
        $this->assertSame(1, $guppyBelow);
        $this->assertSame(2, $guppyAbove);
    }

    public function test_100_fish_cap(): void
    {
        $out = app(RepoAquariumGenerator::class)->generate('big','repo', $this->stats([
            'stars' => 200000, 'forks' => 50000, 'issues' => 5000,
            'watchers' => 10000, 'contributors' => 1000, 'age_days' => 4000,
        ]));
        $this->assertLessThanOrEqual(100, count($out));
    }

    public function test_every_fish_carries_source(): void
    {
        foreach (app(RepoAquariumGenerator::class)->generate('vercel','next.js', $this->stats()) as $f) {
            $this->assertSame('github_repo', $f['source']);
            $this->assertSame('vercel/next.js', $f['source_ref']);
            $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/i', $f['color_hex']);
            $this->assertMatchesRegularExpression('/^repo-vercel-next\.js-\d+$/', $f['id']);
        }
    }

    public function test_age_to_cory_catfish_buckets(): void
    {
        $gen = app(RepoAquariumGenerator::class);
        $young  = $gen->generate('x','y', $this->stats(['age_days' => 30]));
        $mid    = $gen->generate('x','y', $this->stats(['age_days' => 365]));
        $older  = $gen->generate('x','y', $this->stats(['age_days' => 1500]));
        $oldest = $gen->generate('x','y', $this->stats(['age_days' => 4000]));

        $count = fn ($arr) => collect($arr)->where('breed','cory_catfish')->count();
        $this->assertSame(0, $count($young));
        $this->assertSame(1, $count($mid));
        $this->assertSame(2, $count($older));
        $this->assertSame(3, $count($oldest));
    }
}
```

- [ ] **Step 2: Implement.** Encode the SPEC §5 allocation table verbatim; emit fish in stable order; cap at 100 via even downsampling.

```php
<?php
namespace App\Services\Github;

use App\Config\BreedCatalog;          // from slice 3
use Random\Engine\Mt19937;
use Random\Randomizer;

class RepoAquariumGenerator
{
    private const STARS_BPS        = [10, 50, 200, 1000, 5000, 20000, 100000];
    private const FORKS_BPS        = [5, 25, 100, 500, 2500, 10000];
    private const ISSUES_BPS       = [1, 10, 50, 200, 1000];
    private const WATCHERS_BPS     = [5, 20, 100, 500, 2500];
    private const CONTRIBUTORS_BPS = [1, 5, 20, 100, 500];
    private const MAX_FISH         = 100;

    // [breed => [counts per tier]]; tier index clamps at array end.
    private const ALLOC_STARS_GUPPY        = [1,2,3,4,5,6,7,8];
    private const ALLOC_STARS_NEON_TETRA   = [0,0,3,5,7,9,11,13];
    private const ALLOC_STARS_MOLLY        = [0,0,0,1,2,3,4,5];
    private const ALLOC_STARS_CHERRY_BARB  = [0,0,0,0,1,2,3,4];
    private const ALLOC_FORKS_ZEBRA_DANIO  = [0,1,2,3,4,5,6];
    private const ALLOC_ISSUES_OTOCINCLUS  = [0,1,2,3,4,5];
    private const ALLOC_WATCHERS_PLATY     = [0,1,2,3,4,5];
    private const ALLOC_CONTRIBUTORS_ENDLER_CAPS = [0,3,6,10,15,20];

    public function __construct(
        private readonly BreedAccentMap $accents,
        private readonly BreedCatalog $breeds,
    ) {}

    /** @return list<array{id:string,breed:string,color_hex:string,size:int,nickname:string,source:string,source_ref:string}> */
    public function generate(string $owner, string $repo, array $stats): array
    {
        $rng = new Randomizer(new Mt19937(crc32($owner.'/'.$repo)));
        $accent = $this->accents->for($stats['language'] ?? null);

        $plan = [];
        $tier = fn (int $v, array $bps) => $this->tier($v, $bps);

        // stars
        $tStars = $tier((int) $stats['stars'], self::STARS_BPS);
        $this->push($plan, 'guppy',       self::ALLOC_STARS_GUPPY[$tStars] ?? 8);
        $this->push($plan, 'neon_tetra',  self::ALLOC_STARS_NEON_TETRA[$tStars] ?? 13);
        $this->push($plan, 'molly',       self::ALLOC_STARS_MOLLY[$tStars] ?? 5);
        $this->push($plan, 'cherry_barb', self::ALLOC_STARS_CHERRY_BARB[$tStars] ?? 4);

        // forks
        $tForks = $tier((int) $stats['forks'], self::FORKS_BPS);
        $this->push($plan, 'zebra_danio', self::ALLOC_FORKS_ZEBRA_DANIO[$tForks] ?? 6);

        // issues
        $tIssues = $tier((int) $stats['issues'], self::ISSUES_BPS);
        $this->push($plan, 'otocinclus', self::ALLOC_ISSUES_OTOCINCLUS[$tIssues] ?? 5);

        // watchers
        $tWatchers = $tier((int) $stats['watchers'], self::WATCHERS_BPS);
        $this->push($plan, 'platy', self::ALLOC_WATCHERS_PLATY[$tWatchers] ?? 5);

        // contributors
        $tContrib = $tier((int) $stats['contributors'], self::CONTRIBUTORS_BPS);
        $cap = self::ALLOC_CONTRIBUTORS_ENDLER_CAPS[$tContrib] ?? 20;
        $this->push($plan, 'endler', min((int) $stats['contributors'], $cap));

        // age → cory_catfish
        $age = (int) $stats['age_days'];
        $cory = $age < 180 ? 0 : ($age < 730 ? 1 : ($age < 1825 ? 2 : 3));
        $this->push($plan, 'cory_catfish', $cory);

        if (count($plan) > self::MAX_FISH) {
            $plan = $this->downsample($plan, self::MAX_FISH);
        }

        $out = [];
        foreach ($plan as $index => $breed) {
            $defaults = $this->breeds->get($breed);
            $color = $this->color($rng, (string) $defaults['default_color'], $accent);
            $size = $rng->getInt((int) $defaults['min_size'], (int) $defaults['max_size']);
            $short = strtoupper(substr(dechex($rng->getInt(0, 0xFFFFFF)), 0, 3));
            $short = str_pad($short, 3, '0', STR_PAD_LEFT);

            $out[] = [
                'id'         => sprintf('repo-%s-%s-%d', $owner, $repo, $index),
                'breed'      => $breed,
                'color_hex'  => $color,
                'size'       => $size,
                'nickname'   => sprintf('%s-%s', $defaults['label'], $short),
                'source'     => 'github_repo',
                'source_ref' => "{$owner}/{$repo}",
            ];
        }
        return $out;
    }

    private function tier(int $value, array $bps): int
    {
        foreach ($bps as $i => $bp) if ($value < $bp) return $i;
        return count($bps);
    }

    /** @param-out list<string> $plan */
    private function push(array &$plan, string $breed, int $n): void
    {
        for ($i = 0; $i < $n; $i++) $plan[] = $breed;
    }

    private function downsample(array $plan, int $cap): array
    {
        $step = count($plan) / $cap;
        $out = [];
        for ($i = 0; $i < $cap; $i++) {
            $out[] = $plan[(int) floor($i * $step)];
        }
        return $out;
    }

    private function color(Randomizer $rng, string $base, ?string $accent): string
    {
        // 30% chance accent blend at 50% alpha
        if ($accent !== null && $rng->getInt(0, 99) < 30) {
            return $this->blend($base, $accent, 0.5);
        }
        // small ±6° hue jitter (approximated as ±10 RGB units, deterministic)
        $delta = $rng->getInt(-10, 10);
        return $this->clampHex($base, $delta);
    }

    private function blend(string $a, string $b, float $t): string
    {
        [$ra,$ga,$ba] = sscanf($a, "#%02x%02x%02x");
        [$rb,$gb,$bb] = sscanf($b, "#%02x%02x%02x");
        return sprintf('#%02X%02X%02X',
            (int) ($ra * (1 - $t) + $rb * $t),
            (int) ($ga * (1 - $t) + $gb * $t),
            (int) ($ba * (1 - $t) + $bb * $t),
        );
    }

    private function clampHex(string $hex, int $delta): string
    {
        [$r,$g,$b] = sscanf($hex, "#%02x%02x%02x");
        return sprintf('#%02X%02X%02X',
            max(0, min(255, $r + $delta)),
            max(0, min(255, $g + $delta)),
            max(0, min(255, $b + $delta)),
        );
    }
}
```

- [ ] **Step 3: Run.**

```bash
docker compose exec backend ./vendor/bin/pest tests/Unit/Services/Github/RepoAquariumGeneratorTest.php
```

Expected: 5 passing.

- [ ] **Step 4: Commit.**

```bash
git add backend/app/Services/Github/RepoAquariumGenerator.php backend/tests/Unit/Services/Github/RepoAquariumGeneratorTest.php
git commit -m "$(cat <<'EOF'
feat(backend): add RepoAquariumGenerator (SPEC §5 tiers + allocation table + 100-cap + deterministic seed)
EOF
)"
```

---

## Task 10: Backend — pin the `vercel/next.js` snapshot

**Files:**
- Modify: `backend/tests/fixtures/repo_aquarium/vercel-nextjs-fish.json`
- Create: `backend/tests/Feature/Github/RepoAquariumSnapshotTest.php`

- [ ] **Step 1: Generate the snapshot once and pin it.**

```bash
docker compose exec backend php -r "
require 'vendor/autoload.php';
\$app = require 'bootstrap/app.php';
\$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
\$kernel->bootstrap();
\$gen = app(App\Services\Github\RepoAquariumGenerator::class);
\$stats = json_decode(file_get_contents('tests/fixtures/repo_aquarium/vercel-nextjs-stats.json'), true);
file_put_contents('tests/fixtures/repo_aquarium/vercel-nextjs-fish.json', json_encode(\$gen->generate('vercel','next.js',\$stats), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
"
cat backend/tests/fixtures/repo_aquarium/vercel-nextjs-fish.json | head -20
```

Expected: a non-empty JSON array; visually inspect the first few fish look reasonable (breed, color, size, nickname all populated; ids start with `repo-vercel-next.js-`).

- [ ] **Step 2: Snapshot test.**

```php
<?php
namespace Tests\Feature\Github;

use App\Services\Github\RepoAquariumGenerator;
use Tests\TestCase;

class RepoAquariumSnapshotTest extends TestCase
{
    public function test_vercel_nextjs_snapshot_is_pinned(): void
    {
        $statsPath = base_path('tests/fixtures/repo_aquarium/vercel-nextjs-stats.json');
        $fishPath  = base_path('tests/fixtures/repo_aquarium/vercel-nextjs-fish.json');
        $stats = json_decode((string) file_get_contents($statsPath), true, flags: JSON_THROW_ON_ERROR);
        $expected = json_decode((string) file_get_contents($fishPath), true, flags: JSON_THROW_ON_ERROR);

        $actual = app(RepoAquariumGenerator::class)->generate('vercel', 'next.js', $stats);
        $this->assertSame($expected, $actual);
    }
}
```

- [ ] **Step 3: Run.**

```bash
docker compose exec backend ./vendor/bin/pest tests/Feature/Github/RepoAquariumSnapshotTest.php
```

Expected: 1 passing.

- [ ] **Step 4: Commit.**

```bash
git add backend/tests/fixtures/repo_aquarium/vercel-nextjs-fish.json backend/tests/Feature/Github/RepoAquariumSnapshotTest.php
git commit -m "$(cat <<'EOF'
test(backend): pin RepoAquariumGenerator output for vercel/next.js (SPEC §12 snapshot)
EOF
)"
```

---

## Task 11: Backend — `RepoAquariumService` (cache + lock + materialize)

**Files:**
- Create: `backend/app/Services/Github/RepoAquariumService.php`
- Create: `backend/tests/Unit/Services/Github/RepoAquariumServiceTest.php`

- [ ] **Step 1: Test first.** Cover L1 hit, L2 hit, L3 path, materialize idempotency.

```php
<?php
namespace Tests\Unit\Services\Github;

use App\Models\Fish;
use App\Models\RepoAquariumCache;
use App\Models\User;
use App\Services\Github\GithubStatsClient;
use App\Services\Github\RepoAquariumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RepoAquariumServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_l1_hit_skips_github(): void
    {
        Cache::store('redis')->put('repo_aquarium:o/r:v1', ['stats' => ['stars' => 1], 'fish_set' => []], 600);
        Http::fake();
        app(RepoAquariumService::class)->getOrGenerate('o', 'r');
        Http::assertNothingSent();
    }

    public function test_l2_hit_skips_github_and_promotes_to_l1(): void
    {
        Cache::store('redis')->flush();
        RepoAquariumCache::create([
            'owner' => 'o', 'repo' => 'r',
            'stats_json' => ['stars' => 5], 'fish_set_json' => [],
            'fetched_at' => now(),
        ]);
        Http::fake();
        $out = app(RepoAquariumService::class)->getOrGenerate('o', 'r');
        Http::assertNothingSent();
        $this->assertNotNull(Cache::store('redis')->get('repo_aquarium:o/r:v1'));
    }

    public function test_l3_writes_both_tiers(): void
    {
        Cache::store('redis')->flush();
        Http::fake([
            'api.github.com/repos/o/r' => Http::response([
                'stargazers_count'=>100,'forks_count'=>5,'open_issues_count'=>3,
                'subscribers_count'=>4,'language'=>'Go','created_at'=>now()->subDays(400)->toISOString(),
            ], 200),
            'api.github.com/repos/o/r/contributors*' => Http::response([['login'=>'a']], 200),
        ]);
        $out = app(RepoAquariumService::class)->getOrGenerate('o', 'r');

        $this->assertNotNull(Cache::store('redis')->get('repo_aquarium:o/r:v1'));
        $this->assertDatabaseHas('repo_aquarium_cache', ['owner' => 'o', 'repo' => 'r']);
        $this->assertIsArray($out['fish_set']);
    }

    public function test_materialize_idempotent(): void
    {
        $user = User::factory()->create();
        Http::fake([
            'api.github.com/repos/o/r' => Http::response([
                'stargazers_count'=>50,'forks_count'=>3,'open_issues_count'=>2,
                'subscribers_count'=>3,'language'=>'Go','created_at'=>now()->subDays(200)->toISOString(),
            ], 200),
            'api.github.com/repos/o/r/contributors*' => Http::response([['login'=>'a']], 200),
        ]);
        $svc = app(RepoAquariumService::class);
        $first  = $svc->materializeForUser($user, 'o', 'r');
        $second = $svc->materializeForUser($user, 'o', 'r');
        $this->assertGreaterThan(0, $first['added']);
        $this->assertSame(0, $second['added']);
        $this->assertSame($first['added'], Fish::where('user_id', $user->id)->count());
    }

    public function test_is_reserved_route(): void
    {
        $this->assertTrue(RepoAquariumService::isReservedRoute('login'));
        $this->assertFalse(RepoAquariumService::isReservedRoute('vercel'));
    }
}
```

- [ ] **Step 2: Implement.**

```php
<?php
namespace App\Services\Github;

use App\Models\Fish;
use App\Models\RepoAquariumCache;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RepoAquariumService
{
    private const RESERVED = ['login','register','fish','onboarding','api-docs','api','_next','auth','admin'];

    public function __construct(
        private readonly Cache $cache,
        private readonly GithubStatsClient $client,
        private readonly RepoAquariumGenerator $generator,
        private readonly Config $config,
    ) {}

    public static function isReservedRoute(string $owner): bool
    {
        return in_array(strtolower($owner), self::RESERVED, true);
    }

    /** @return array{stats: array, fish_set: array} */
    public function getOrGenerate(string $owner, string $repo): array
    {
        $key   = $this->cacheKey($owner, $repo);
        $ttl   = (int) $this->config->get('services.github.cache_ttl_seconds', 600);
        $lockK = "repo_aquarium_lock:{$owner}/{$repo}";
        $lockT = (int) $this->config->get('services.github.lock_ttl_seconds', 60);
        $block = (int) $this->config->get('services.github.lock_block_seconds', 5);

        if ($hit = $this->cache->get($key))               return $this->mark($hit, 'redis');
        if ($hit = $this->fromDb($owner, $repo, $ttl, $key)) return $this->mark($hit, 'db');

        try {
            return $this->cache->lock($lockK, $lockT)->block($block, function () use ($owner, $repo, $ttl, $key) {
                if ($hit = $this->cache->get($key))                  return $this->mark($hit, 'redis');
                if ($hit = $this->fromDb($owner, $repo, $ttl, $key)) return $this->mark($hit, 'db');
                return $this->fetchAndStore($owner, $repo, $ttl, $key);
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            Log::warning('repo_aquarium_lock_timeout', ['owner' => $owner, 'repo' => $repo]);
            return $this->fetchAndStore($owner, $repo, $ttl, $key);
        }
    }

    /** @return array{added: int} */
    public function materializeForUser(User $user, string $owner, string $repo): array
    {
        $payload = $this->getOrGenerate($owner, $repo);
        $sourceRef = "{$owner}/{$repo}";

        $exists = Fish::query()
            ->where('user_id', $user->id)
            ->where('source', 'github_repo')
            ->where('source_ref', $sourceRef)
            ->exists();

        if ($exists) return ['added' => 0];

        $now = now();
        $rows = array_map(fn (array $f) => [
            'user_id'    => $user->id,
            'nickname'   => $f['nickname'],
            'breed'      => $f['breed'],
            'color_hex'  => $f['color_hex'],
            'size'       => $f['size'],
            'source'     => 'github_repo',
            'source_ref' => $sourceRef,
            'created_at' => $now,
            'updated_at' => $now,
        ], $payload['fish_set']);

        DB::transaction(fn () => Fish::insert($rows));
        return ['added' => count($rows)];
    }

    private function cacheKey(string $owner, string $repo): string
    {
        $v = (string) $this->config->get('services.github.cache_key_version', 'v1');
        return "repo_aquarium:{$owner}/{$repo}:{$v}";
    }

    private function fromDb(string $owner, string $repo, int $ttl, string $key): ?array
    {
        $row = RepoAquariumCache::query()
            ->where('owner', $owner)->where('repo', $repo)
            ->notStale($ttl)
            ->first();
        if (! $row) return null;
        $payload = ['stats' => $row->stats_json, 'fish_set' => $row->fish_set_json];
        $this->cache->put($key, $payload, $ttl);
        return $payload;
    }

    private function fetchAndStore(string $owner, string $repo, int $ttl, string $key): array
    {
        $stats = $this->client->fetchStats($owner, $repo);
        $statsArr = array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->toIso8601String() : $v, $stats);
        $fish = $this->generator->generate($owner, $repo, $stats);

        RepoAquariumCache::updateOrCreate(
            ['owner' => $owner, 'repo' => $repo],
            ['stats_json' => $statsArr, 'fish_set_json' => $fish, 'fetched_at' => now()],
        );
        $payload = ['stats' => $statsArr, 'fish_set' => $fish];
        $this->cache->put($key, $payload, $ttl);
        return $this->mark($payload, 'fresh');
    }

    private function mark(array $payload, string $via): array
    {
        Log::debug('repo_aquarium_cached_via', ['via' => $via]);
        return $payload; // intentionally NOT exposed in the API response (decision §3.6)
    }
}
```

- [ ] **Step 3: Run.**

```bash
docker compose exec backend ./vendor/bin/pest tests/Unit/Services/Github/RepoAquariumServiceTest.php
```

Expected: 5 passing.

- [ ] **Step 4: Commit.**

```bash
git add backend/app/Services/Github/RepoAquariumService.php backend/tests/Unit/Services/Github/RepoAquariumServiceTest.php
git commit -m "$(cat <<'EOF'
feat(backend): add RepoAquariumService (L1 Redis 10min → L2 DB → L3 GitHub under Redis lock; idempotent fork)
EOF
)"
```

---

## Task 12: Backend — `RepoAquariumResource` + `RepoForkResource`

**Files:**
- Create: `backend/app/Http/Resources/RepoAquariumResource.php`
- Create: `backend/app/Http/Resources/RepoForkResource.php`

- [ ] **Step 1: Resources with OpenAPI annotations.**

```php
<?php
namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'RepoStats',
    type: 'object',
    properties: [
        new OA\Property(property: 'stars', type: 'integer'),
        new OA\Property(property: 'forks', type: 'integer'),
        new OA\Property(property: 'issues', type: 'integer'),
        new OA\Property(property: 'watchers', type: 'integer'),
        new OA\Property(property: 'contributors', type: 'integer'),
        new OA\Property(property: 'language', type: 'string', nullable: true),
        new OA\Property(property: 'age_days', type: 'integer'),
        new OA\Property(property: 'fetched_at', type: 'string', format: 'date-time'),
    ],
)]
#[OA\Schema(
    schema: 'RepoFishItem',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'breed', type: 'string'),
        new OA\Property(property: 'color_hex', type: 'string'),
        new OA\Property(property: 'size', type: 'integer'),
        new OA\Property(property: 'nickname', type: 'string'),
        new OA\Property(property: 'source', type: 'string'),
        new OA\Property(property: 'source_ref', type: 'string'),
    ],
)]
#[OA\Schema(
    schema: 'RepoAquariumResponse',
    type: 'object',
    properties: [
        new OA\Property(property: 'data', type: 'object', properties: [
            new OA\Property(property: 'stats', ref: '#/components/schemas/RepoStats'),
            new OA\Property(property: 'fish_set', type: 'array', items: new OA\Items(ref: '#/components/schemas/RepoFishItem')),
        ]),
    ],
)]
class RepoAquariumResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return ['data' => [
            'stats'    => $this->resource['stats'],
            'fish_set' => $this->resource['fish_set'],
        ]];
    }
}
```

```php
<?php
namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'RepoForkResponse',
    type: 'object',
    properties: [new OA\Property(property: 'added', type: 'integer')],
)]
class RepoForkResource extends JsonResource
{
    public static $wrap = null;
    public function toArray(Request $request): array { return ['added' => (int) $this->resource['added']]; }
}
```

- [ ] **Step 2: Commit.**

```bash
git add backend/app/Http/Resources/RepoAquariumResource.php backend/app/Http/Resources/RepoForkResource.php
git commit -m "$(cat <<'EOF'
feat(backend): add RepoAquariumResource + RepoForkResource with named OpenAPI schemas
EOF
)"
```

---

## Task 13: Backend — `RepoAquariumController` (TDD feature tests)

**Files:**
- Create: `backend/app/Http/Controllers/Api/V1/RepoAquariumController.php`
- Create: `backend/tests/Feature/Github/GetRepoAquariumTest.php`
- Create: `backend/tests/Feature/Github/ForkRepoTest.php`

- [ ] **Step 1: Tests first.**

```php
<?php
namespace Tests\Feature\Github;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GetRepoAquariumTest extends TestCase
{
    use RefreshDatabase;

    private function fakeRepo(): void
    {
        Cache::store('redis')->flush();
        Http::fake([
            'api.github.com/repos/vercel/next.js' => Http::response([
                'stargazers_count'=>116000,'forks_count'=>25000,'open_issues_count'=>2400,
                'subscribers_count'=>750,'language'=>'TypeScript',
                'created_at'=>now()->subDays(2900)->toISOString(),
            ], 200),
            'api.github.com/repos/vercel/next.js/contributors*' => Http::response([['login'=>'a']], 200,
                ['Link' => '<...page=480>; rel="last"']),
        ]);
    }

    public function test_happy_path_returns_stats_and_fish_set(): void
    {
        $this->fakeRepo();
        $this->getJson('/api/v1/repos/vercel/next.js/aquarium')
            ->assertOk()
            ->assertJsonStructure(['data' => ['stats' => ['stars','forks','issues','watchers','contributors','language','age_days','fetched_at'], 'fish_set']]);
    }

    public function test_cache_hit_does_not_call_github(): void
    {
        $this->fakeRepo();
        $this->getJson('/api/v1/repos/vercel/next.js/aquarium')->assertOk();
        Http::clearResolvedInstances();
        Http::fake();
        $this->getJson('/api/v1/repos/vercel/next.js/aquarium')->assertOk();
        Http::assertNothingSent();
    }

    public function test_404_on_repo_not_found(): void
    {
        Http::fake(['api.github.com/repos/*' => Http::response([], 404)]);
        $this->getJson('/api/v1/repos/no/such/aquarium')->assertNotFound();
    }

    public function test_403_on_forbidden(): void
    {
        Http::fake(['api.github.com/repos/*' => Http::response([], 403)]);
        $this->getJson('/api/v1/repos/priv/repo/aquarium')->assertForbidden();
    }

    public function test_404_on_reserved_owner(): void
    {
        $this->getJson('/api/v1/repos/login/whatever/aquarium')->assertNotFound();
    }

    public function test_404_on_bad_regex(): void
    {
        $this->getJson('/api/v1/repos/foo$bar/repo/aquarium')->assertNotFound();
    }

    public function test_cached_via_not_in_response(): void
    {
        $this->fakeRepo();
        $body = $this->getJson('/api/v1/repos/vercel/next.js/aquarium')->json();
        $this->assertArrayNotHasKey('cached_via', $body);
        $this->assertArrayNotHasKey('cached_via', $body['data']);
    }
}
```

```php
<?php
namespace Tests\Feature\Github;

use App\Models\Fish;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ForkRepoTest extends TestCase
{
    use RefreshDatabase;

    private function fakeRepo(): void
    {
        Cache::store('redis')->flush();
        Http::fake([
            'api.github.com/repos/vercel/next.js' => Http::response([
                'stargazers_count'=>1000,'forks_count'=>50,'open_issues_count'=>10,
                'subscribers_count'=>30,'language'=>'TypeScript',
                'created_at'=>now()->subDays(900)->toISOString(),
            ], 200),
            'api.github.com/repos/vercel/next.js/contributors*' => Http::response([['login'=>'a']], 200,
                ['Link' => '<...page=15>; rel="last"']),
        ]);
    }

    public function test_unauthed_401(): void
    {
        $this->fakeRepo();
        $this->postJson('/api/v1/repos/vercel/next.js/fork-to-my-aquarium')->assertUnauthorized();
    }

    public function test_first_fork_adds_n_fish(): void
    {
        $this->fakeRepo();
        $u = User::factory()->create();
        Sanctum::actingAs($u);

        $r = $this->postJson('/api/v1/repos/vercel/next.js/fork-to-my-aquarium')->assertCreated();
        $this->assertGreaterThan(0, $r->json('added'));
        $this->assertSame($r->json('added'), Fish::where('user_id', $u->id)->count());
        $this->assertTrue(Fish::where('user_id', $u->id)
            ->where('source', 'github_repo')
            ->where('source_ref', 'vercel/next.js')
            ->exists());
    }

    public function test_second_fork_is_idempotent(): void
    {
        $this->fakeRepo();
        $u = User::factory()->create();
        Sanctum::actingAs($u);
        $this->postJson('/api/v1/repos/vercel/next.js/fork-to-my-aquarium')->assertCreated();
        $count = Fish::where('user_id', $u->id)->count();
        $this->postJson('/api/v1/repos/vercel/next.js/fork-to-my-aquarium')
            ->assertCreated()
            ->assertJson(['added' => 0]);
        $this->assertSame($count, Fish::where('user_id', $u->id)->count());
    }
}
```

- [ ] **Step 2: Controller.**

```php
<?php
namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Github\GithubUnavailableException;
use App\Exceptions\Github\RepoForbiddenException;
use App\Exceptions\Github\RepoNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Resources\RepoAquariumResource;
use App\Http\Resources\RepoForkResource;
use App\Services\Github\RepoAquariumService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class RepoAquariumController extends Controller
{
    public function __construct(private readonly RepoAquariumService $service) {}

    #[OA\Get(
        path: '/api/v1/repos/{owner}/{repo}/aquarium',
        operationId: 'getRepoAquarium',
        tags: ['Repo Aquarium'],
        parameters: [
            new OA\Parameter(name: 'owner', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'repo',  in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/RepoAquariumResponse')),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function show(string $owner, string $repo): JsonResponse
    {
        if (RepoAquariumService::isReservedRoute($owner)) abort(404);
        try {
            return (new RepoAquariumResource($this->service->getOrGenerate($owner, $repo)))->response();
        } catch (RepoNotFoundException) {
            abort(404);
        } catch (RepoForbiddenException) {
            abort(403, 'Repository is private or rate-limited.');
        } catch (GithubUnavailableException) {
            abort(503, 'GitHub is temporarily unavailable.');
        }
    }

    #[OA\Post(
        path: '/api/v1/repos/{owner}/{repo}/fork-to-my-aquarium',
        operationId: 'forkRepoAquarium',
        tags: ['Repo Aquarium'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'owner', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'repo',  in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/RepoForkResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function fork(string $owner, string $repo, Request $request): JsonResponse
    {
        if (RepoAquariumService::isReservedRoute($owner)) abort(404);
        try {
            $result = $this->service->materializeForUser($request->user(), $owner, $repo);
            return (new RepoForkResource($result))->response()->setStatusCode(201);
        } catch (RepoNotFoundException) {
            abort(404);
        } catch (RepoForbiddenException) {
            abort(403);
        }
    }
}
```

- [ ] **Step 3: Run feature tests (after Task 14 registers the routes, this will turn green).**

- [ ] **Step 4: Commit.**

```bash
git add backend/app/Http/Controllers/Api/V1/RepoAquariumController.php backend/tests/Feature/Github/
git commit -m "$(cat <<'EOF'
feat(backend): add RepoAquariumController (public show + authed fork) with OpenAPI annotations
EOF
)"
```

---

## Task 14: Backend — wire routes + `Route::pattern` constraints

**Files:**
- Modify: `backend/routes/api.php`

- [ ] **Step 1: At the top of `routes/api.php`, register the two pattern constraints.**

```php
Route::pattern('owner', '[A-Za-z0-9._-]{1,100}');
Route::pattern('repo',  '[A-Za-z0-9._-]{1,100}');
```

- [ ] **Step 2: Inside the `v1` group, public route + authed route.**

```php
Route::middleware('throttle:api')->prefix('v1')->group(function () {
    // public:
    Route::get('/repos/{owner}/{repo}/aquarium',
        [\App\Http\Controllers\Api\V1\RepoAquariumController::class, 'show'])
        ->name('repos.aquarium.show');

    // authed:
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/repos/{owner}/{repo}/fork-to-my-aquarium',
            [\App\Http\Controllers\Api\V1\RepoAquariumController::class, 'fork'])
            ->name('repos.aquarium.fork');
    });
});
```

- [ ] **Step 3: Run all the feature tests from Task 13.**

```bash
docker compose exec backend ./vendor/bin/pest tests/Feature/Github/
```

Expected: all green.

- [ ] **Step 4: Commit.**

```bash
git add backend/routes/api.php
git commit -m "$(cat <<'EOF'
feat(backend): register /repos/{owner}/{repo}/aquarium (public) + fork-to-my-aquarium (authed) with Route::pattern regex
EOF
)"
```

---

## Task 15: Backend — race-condition + reserved-route + regex feature tests

**Files:**
- Create: `backend/tests/Feature/Github/RepoAquariumRaceTest.php`

- [ ] **Step 1: Test — assert two parallel requests hit GitHub once.** (Sequential simulation; true parallelism comes from `Http::recorded` count == 1 after two calls under the lock.)

```php
<?php
namespace Tests\Feature\Github;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RepoAquariumRaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_sequential_cold_calls_only_hit_github_once_total(): void
    {
        Cache::store('redis')->flush();
        Http::fake([
            'api.github.com/repos/race/repo' => Http::response([
                'stargazers_count'=>100,'forks_count'=>5,'open_issues_count'=>2,
                'subscribers_count'=>3,'language'=>'Go','created_at'=>now()->subDays(300)->toISOString(),
            ], 200),
            'api.github.com/repos/race/repo/contributors*' => Http::response([['login'=>'a']], 200),
        ]);

        $this->getJson('/api/v1/repos/race/repo/aquarium')->assertOk();
        // second call hits L1 — no GitHub
        $this->getJson('/api/v1/repos/race/repo/aquarium')->assertOk();

        // exactly 2 GitHub calls happened (one for /repos, one for /contributors); both during the first request
        Http::assertSentCount(2);
    }
}
```

(True concurrent stampede testing requires a worker pool; we settle for the cache-effect assertion as a proxy. The lock is exercised in `RepoAquariumServiceTest`.)

- [ ] **Step 2: Run.**

```bash
docker compose exec backend ./vendor/bin/pest tests/Feature/Github/RepoAquariumRaceTest.php
```

Expected: 1 passing.

- [ ] **Step 3: Commit.**

```bash
git add backend/tests/Feature/Github/RepoAquariumRaceTest.php
git commit -m "$(cat <<'EOF'
test(backend): assert L1 cache prevents repeat GitHub calls on the public aquarium endpoint
EOF
)"
```

---

## Task 16: Backend — regenerate OpenAPI spec; bump coverage glob

**Files:**
- Modify: `backend/storage/api-docs/openapi.json`
- Modify: `backend/phpunit.xml`

- [ ] **Step 1: Regenerate.**

```bash
docker compose exec backend php artisan l5-swagger:generate
git diff --stat backend/storage/api-docs/openapi.json
```

Expected: diff shows two new operations (`getRepoAquarium`, `forkRepoAquarium`) and five new schemas.

- [ ] **Step 2: Extend phpunit coverage include to pick up `app/Services/Github`.**

```xml
<include>
    <directory suffix=".php">app/Services</directory>   <!-- already there -->
    <directory suffix=".php">app/Http/Controllers</directory>
    <directory suffix=".php">app/Jobs</directory>
    <directory suffix=".php">app/Console/Commands</directory>
</include>
```

(No change required if `app/Services` glob already covers subdirs; verify.)

- [ ] **Step 3: Run full backend suite + coverage gate.**

```bash
docker compose exec backend ./vendor/bin/pest --coverage --min=80
```

Expected: passing; ≥ 80% coverage on `app/Services/Github`.

- [ ] **Step 4: Commit.**

```bash
git add backend/storage/api-docs/openapi.json backend/phpunit.xml
git commit -m "$(cat <<'EOF'
chore(backend): regenerate OpenAPI spec for repo-aquarium endpoints; verify Github coverage ≥80%
EOF
)"
```

---

## Task 17: Frontend — regenerate API client

**Files:**
- Modify: `frontend/src/lib/api-client/**`

- [ ] **Step 1: Regenerate.**

```bash
cd frontend
npx openapi-generator-cli generate -i ../backend/storage/api-docs/openapi.json -g typescript-fetch -o src/lib/api-client
git diff --stat src/lib/api-client
```

Expected: new `RepoAquariumApi.ts`, schema types `RepoStats`, `RepoFishItem`, `RepoAquariumResponse`, `RepoForkResponse`.

- [ ] **Step 2: Commit.**

```bash
git add frontend/src/lib/api-client
git commit -m "$(cat <<'EOF'
chore(frontend): regenerate API client for /repos/{owner}/{repo}/aquarium and fork endpoints
EOF
)"
```

---

## Task 18: Frontend — `readOnly` prop on `AquariumCanvas` (TDD)

**Files:**
- Modify: `frontend/src/components/aquarium/AquariumCanvas.tsx`
- Create: `frontend/tests/unit/components/aquarium/AquariumCanvas-readOnly.test.tsx`

- [ ] **Step 1: Test first.**

```tsx
import { render, fireEvent } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { AquariumCanvas } from '@/components/aquarium/AquariumCanvas';

const breeds = [{ id: 'guppy' }];
const fishes = [{ id: 'f1', breed: 'guppy', color_hex: '#FF6B9D', size: 12, nickname: 'F1' }];

describe('AquariumCanvas readOnly', () => {
  it('accepts the readOnly prop without crashing', () => {
    const { getByTestId } = render(<AquariumCanvas fishes={fishes} breeds={breeds} readOnly />);
    expect(getByTestId('aquarium-canvas')).toBeInTheDocument();
  });

  it('still drops a food pellet on click when readOnly', () => {
    const { getByTestId } = render(<AquariumCanvas fishes={fishes} breeds={breeds} readOnly />);
    const canvas = getByTestId('aquarium-canvas');
    fireEvent.mouseDown(canvas, { clientX: 100, clientY: 100 });
    // Pellet state is internal; the test asserts the event handler doesn't throw
    // and the canvas remains mounted. Detailed pellet count is covered in slice 3's
    // AquariumCanvas test; this one only asserts the prop is wired.
    expect(canvas).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Add the prop. Behavior change is minimal — the prop is read by surrounding chrome (Task 19's `RepoAquariumPage`); the canvas itself simply accepts it.**

```ts
export function AquariumCanvas({
  fishes,
  breeds,
  readOnly = false,
}: {
  fishes: FishDTO[];
  breeds: Breed[];
  readOnly?: boolean;
}) {
  // Existing implementation unchanged.
  // The `readOnly` flag is currently informational; food-drop + hover remain enabled (decision §3.4).
  // ...
}
```

- [ ] **Step 3: Run.**

```bash
cd frontend && npm test -- --run AquariumCanvas-readOnly
```

Expected: 2 passing.

- [ ] **Step 4: Commit.**

```bash
git add frontend/src/components/aquarium/AquariumCanvas.tsx frontend/tests/unit/components/aquarium/AquariumCanvas-readOnly.test.tsx
git commit -m "$(cat <<'EOF'
feat(frontend): add readOnly prop to AquariumCanvas (food-drop stays enabled per decision §3.4)
EOF
)"
```

---

## Task 19: Frontend — `RepoAquariumPage` client component + Fork CTA (TDD)

**Files:**
- Create: `frontend/src/components/repo-aquarium/RepoAquariumPage.tsx`
- Create: `frontend/src/hooks/use-fork-repo-mutation.ts`
- Create: `frontend/tests/unit/components/repo-aquarium/RepoAquariumPage.test.tsx`

- [ ] **Step 1: Test first.**

```tsx
import { render, screen, fireEvent } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi } from 'vitest';
import { RepoAquariumPage } from '@/components/repo-aquarium/RepoAquariumPage';

const stats = { stars: 100, forks: 10, issues: 5, watchers: 3, contributors: 2, language: 'Go', age_days: 400, fetched_at: '2026-05-16T00:00:00Z' };
const fish_set = [{ id: 'repo-o-r-0', breed: 'guppy', color_hex: '#FF6B9D', size: 12, nickname: 'Guppy-A4F', source: 'github_repo', source_ref: 'o/r' }];

function wrap(ui: React.ReactNode) {
  return <QueryClientProvider client={new QueryClient()}>{ui}</QueryClientProvider>;
}

describe('RepoAquariumPage', () => {
  it('renders stats panel + Fork button when authed', () => {
    render(wrap(<RepoAquariumPage owner="o" repo="r" stats={stats} fish_set={fish_set} isAuthed />));
    expect(screen.getByText(/o\/r/)).toBeInTheDocument();
    expect(screen.getByText(/100/)).toBeInTheDocument(); // stars
    expect(screen.getByRole('button', { name: /fork to my aquarium/i })).toBeInTheDocument();
  });

  it('renders Sign-in link when unauthed', () => {
    render(wrap(<RepoAquariumPage owner="o" repo="r" stats={stats} fish_set={fish_set} isAuthed={false} />));
    expect(screen.getByRole('link', { name: /sign in to fork/i })).toHaveAttribute('href', '/login?redirect=/o/r');
  });

  it('calls the fork mutation on click', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ added: 5 }), { status: 201 }));
    render(wrap(<RepoAquariumPage owner="o" repo="r" stats={stats} fish_set={fish_set} isAuthed />));
    fireEvent.click(screen.getByRole('button', { name: /fork to my aquarium/i }));
    await screen.findByText(/added 5 fish/i);
    expect(fetchMock).toHaveBeenCalledWith(
      expect.stringContaining('/api/proxy/repos/o/r/fork-to-my-aquarium'),
      expect.objectContaining({ method: 'POST' }),
    );
  });
});
```

- [ ] **Step 2: Hook.**

```ts
'use client';
import { useMutation, useQueryClient } from '@tanstack/react-query';

export function useForkRepoMutation(owner: string, repo: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (): Promise<{ added: number }> => {
      const r = await fetch(`/api/proxy/repos/${owner}/${repo}/fork-to-my-aquarium`, { method: 'POST' });
      if (!r.ok) throw new Error(`fork failed: ${r.status}`);
      return r.json();
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['fishes', 'list'] }),
  });
}
```

- [ ] **Step 3: Page.**

```tsx
'use client';
import Link from 'next/link';
import { useState } from 'react';
import { AquariumCanvas } from '@/components/aquarium/AquariumCanvas';
import { useBreedsQuery } from '@/hooks/use-breeds-query';
import { useForkRepoMutation } from '@/hooks/use-fork-repo-mutation';

type Stats = {
  stars: number; forks: number; issues: number; watchers: number; contributors: number;
  language: string | null; age_days: number; fetched_at: string;
};
type FishDTO = { id: string; breed: string; color_hex: string; size: number; nickname: string; source: string; source_ref: string };

export function RepoAquariumPage(props: { owner: string; repo: string; stats: Stats; fish_set: FishDTO[]; isAuthed: boolean }) {
  const { owner, repo, stats, fish_set, isAuthed } = props;
  const { data: breeds = [] } = useBreedsQuery();
  const fork = useForkRepoMutation(owner, repo);
  const [toast, setToast] = useState<string | null>(null);

  const onFork = async () => {
    try {
      const r = await fork.mutateAsync();
      setToast(`Added ${r.added} fish to your aquarium`);
    } catch {
      setToast('Could not fork — try again.');
    }
  };

  return (
    <div className="relative w-screen h-screen">
      <AquariumCanvas fishes={fish_set} breeds={breeds} readOnly />

      <aside className="fixed top-4 right-4 z-30 glass-md rounded-xl px-4 py-3 text-sm">
        <div className="font-medium">{owner}/{repo}</div>
        <div className="mt-1 grid grid-cols-3 gap-x-3 gap-y-1 text-xs">
          <span>⭐ {stats.stars}</span>
          <span>🍴 {stats.forks}</span>
          <span>🐛 {stats.issues}</span>
          <span>👀 {stats.watchers}</span>
          <span>👥 {stats.contributors}</span>
          <span>💬 {stats.language ?? '—'}</span>
        </div>
      </aside>

      <div className="fixed bottom-4 right-4 z-30">
        {isAuthed ? (
          <button
            onClick={onFork}
            disabled={fork.isPending}
            className="glass-md rounded-xl px-4 py-3 hover:bg-white/60 transition-colors"
          >
            {fork.isPending ? 'Forking…' : 'Fork to My Aquarium'}
          </button>
        ) : (
          <Link href={`/login?redirect=/${owner}/${repo}`} className="glass-md rounded-xl px-4 py-3 inline-block">
            Sign in to fork
          </Link>
        )}
      </div>

      {toast && (
        <div role="status" className="fixed bottom-20 right-4 z-30 glass-md rounded-lg px-3 py-2 text-sm">
          {toast}
        </div>
      )}
    </div>
  );
}
```

- [ ] **Step 4: Run.**

```bash
cd frontend && npm test -- --run RepoAquariumPage
```

Expected: 3 passing.

- [ ] **Step 5: Commit.**

```bash
git add frontend/src/components/repo-aquarium/RepoAquariumPage.tsx frontend/src/hooks/use-fork-repo-mutation.ts frontend/tests/unit/components/repo-aquarium/RepoAquariumPage.test.tsx
git commit -m "$(cat <<'EOF'
feat(frontend): add RepoAquariumPage client component (stats panel + Fork CTA + read-only canvas)
EOF
)"
```

---

## Task 20: Frontend — `app/[username]/[repo]/page.tsx` Server Component

**Files:**
- Create: `frontend/src/app/[username]/[repo]/page.tsx`
- Create: `frontend/src/lib/repo-aquarium/validate.ts`
- Create: `frontend/tests/unit/lib/repo-aquarium/validate.test.ts`

- [ ] **Step 1: Validation helper + test.**

```ts
// validate.ts
export const REPO_PATH_RE = /^[A-Za-z0-9._-]{1,100}$/;
export function isValidPathSegment(s: string): boolean { return REPO_PATH_RE.test(s); }
```

```ts
// validate.test.ts
import { describe, expect, it } from 'vitest';
import { isValidPathSegment } from '@/lib/repo-aquarium/validate';

describe('isValidPathSegment', () => {
  it('accepts valid', () => {
    expect(isValidPathSegment('vercel')).toBe(true);
    expect(isValidPathSegment('next.js')).toBe(true);
    expect(isValidPathSegment('foo_bar-baz.1')).toBe(true);
  });
  it('rejects invalid', () => {
    expect(isValidPathSegment('')).toBe(false);
    expect(isValidPathSegment('a'.repeat(101))).toBe(false);
    expect(isValidPathSegment('foo$bar')).toBe(false);
    expect(isValidPathSegment('a/b')).toBe(false);
    expect(isValidPathSegment('..')).toBe(true); // dot-only allowed by SPEC regex; backend defends in depth via 404
  });
});
```

- [ ] **Step 2: The Server Component.** Calls `BACKEND_INTERNAL_URL` directly (decision §3.3).

```tsx
import { notFound } from 'next/navigation';
import { isValidPathSegment } from '@/lib/repo-aquarium/validate';
import { getSession } from '@/lib/auth';
import { RepoAquariumPage } from '@/components/repo-aquarium/RepoAquariumPage';

type Params = { username: string; repo: string };

export default async function Page({ params }: { params: Promise<Params> }) {
  const { username, repo } = await params;
  if (!isValidPathSegment(username) || !isValidPathSegment(repo)) notFound();

  const url = `${process.env.BACKEND_INTERNAL_URL}/repos/${username}/${repo}/aquarium`;
  const res = await fetch(url, { cache: 'no-store' });

  if (res.status === 404) notFound();
  if (res.status === 403) {
    return (
      <main className="min-h-screen grid place-items-center">
        <div className="glass-md rounded-xl p-6 max-w-md text-center">
          This repository is private or temporarily unavailable.
        </div>
      </main>
    );
  }
  if (!res.ok) throw new Error(`backend responded ${res.status}`);

  const body = await res.json();
  const session = await getSession();
  const isAuthed = !!session?.token;

  return (
    <RepoAquariumPage
      owner={username}
      repo={repo}
      stats={body.data.stats}
      fish_set={body.data.fish_set}
      isAuthed={isAuthed}
    />
  );
}
```

- [ ] **Step 3: Run.**

```bash
cd frontend && npm test -- --run validate && npm run typecheck && npm run build
```

Expected: passes; build emits the new route.

- [ ] **Step 4: Commit.**

```bash
git add frontend/src/app/[username]/[repo]/page.tsx frontend/src/lib/repo-aquarium/validate.ts frontend/tests/unit/lib/repo-aquarium/validate.test.ts
git commit -m "$(cat <<'EOF'
feat(frontend): add public Server Component /[username]/[repo] (bypasses iron-session proxy; calls BACKEND_INTERNAL_URL directly)
EOF
)"
```

---

## Task 21: Acceptance smoke — live MinIO-free GitHub-faked walk-through

**Files:**
- (none — verification only)

- [ ] **Step 1: Bring stack up.**

```bash
docker compose up -d db redis backend frontend
sleep 5
```

- [ ] **Step 2: Public endpoint shape.** Use a fake `GITHUB_TOKEN`-less response by hitting a known-real public repo (or `vercel/next.js` for real if local network policy allows).

```bash
curl -s http://localhost:8000/api/v1/repos/vercel/next.js/aquarium | jq '.data | keys'
```

Expected: `["fish_set","stats"]`.

- [ ] **Step 3: Reserved-route 404.**

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8000/api/v1/repos/login/whatever/aquarium
```

Expected: `404`.

- [ ] **Step 4: Bad-regex 404.**

```bash
curl -s -o /dev/null -w '%{http_code}\n' 'http://localhost:8000/api/v1/repos/foo%24bar/repo/aquarium'
```

Expected: `404`.

- [ ] **Step 5: Frontend page renders.** Open `http://localhost:3000/vercel/next.js` in a browser. Expected: stats panel top-right with values; canvas full-viewport with fish swimming; an unauthed visitor sees "Sign in to fork"; after `/login`, the visitor sees "Fork to My Aquarium".

- [ ] **Step 6: Fork happy path.** After logging in:

```bash
TOKEN="…paste from devtools cookie or /api/v1/auth/me…"
curl -s -X POST http://localhost:8000/api/v1/repos/vercel/next.js/fork-to-my-aquarium \
  -H "Authorization: Bearer $TOKEN" | jq
```

Expected: `{"added": N}` first call; `{"added": 0}` second call.

- [ ] **Step 7: No commit.** Verification only.

---

## Task 22: Tag the slice

**Files:**
- (none — git only)

- [ ] **Step 1: Confirm CI-equivalent locally.**

```bash
docker compose exec backend ./vendor/bin/pest --coverage --min=80
docker compose exec backend ./vendor/bin/phpstan analyse --level=6
docker compose exec backend ./vendor/bin/pint --test
cd frontend && npm run lint && npm run typecheck && npm test -- --run && npm run build
```

Expected: all green.

- [ ] **Step 2: Tag.**

```bash
git tag -a slice-5-repo-aquarium -m "Slice 5: GitHub Repo Aquarium (public endpoint + fork-to-my-aquarium)"
git log --oneline -20
```

Expected: tag created; recent commits all conform to Conventional Commits.

- [ ] **Step 3: Done.** Slice 5 complete; SPEC §17 items 8 and 9 satisfied.
