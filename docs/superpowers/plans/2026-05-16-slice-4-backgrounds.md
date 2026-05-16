# Slice 4 — Backgrounds (Upload + Fal AI Generate + Select) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` (or `superpowers:subagent-driven-development` for parallelizable chunks) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking; do **not** mark a checkbox until the step's expected output is observed.

**Goal:** Ship background customization end-to-end. After this slice, a user can upload (≥1280×720, ≤5MB, JPG/PNG/WebP), AI-generate (Fal AI `flux-2/turbo`, up-to-60-s polling with progress UI), select, and delete (soft-delete + 7-day delayed S3 purge) backgrounds via a Radix tabbed panel; the active background renders behind the aquarium canvas via a `BackgroundLayer`. SPEC §17 acceptance items **6** and **7** are satisfied.

**Architecture:** Backend adds the `backgrounds` table (partial unique active-per-user index, soft-delete), a `BackgroundImageProcessor` (Intervention v3 deep-MIME-sniff + EXIF-strip + WebP re-encode), a `FalAiClient` (Laravel `Http` against `https://queue.fal.run/fal-ai/flux-2/turbo` with poll-up-to-60s + retry), a `PromptDenylist`, a `BackgroundService` (atomic active-flip in a DB transaction backed by the partial unique index), a `PurgeBackgroundJob` (7-day delayed S3 deletion), a daily `backgrounds:purge-orphans` artisan command, a real `BackgroundPolicy`, three FormRequests, a `BackgroundController` (apiResource minus store/update + three custom routes), a named `RateLimiter::for('generate')` returning per-user-10/hr + global-200/day. Frontend adds a `BackgroundLayer`, a Radix tabbed `BackgroundPanel` (Upload / Generate / Library), `react-dropzone` upload, react-hook-form + zod generate form with a polling spinner, optimistic select/delete, TanStack Query hooks, and a "Backgrounds" dock button on `/fish`.

**Tech Stack:** Laravel 13 + PHP 8.3 + Pest + Larastan; new backend dep `intervention/image` v3. Next.js 16 + React 19 + TS strict; new frontend deps `react-dropzone` and `@radix-ui/react-tabs`.

**Spec:** [`docs/superpowers/specs/2026-05-16-slice-4-backgrounds-design.md`](../specs/2026-05-16-slice-4-backgrounds-design.md).

---

## Conventions

- Today's date for commit messages is **2026-05-16**.
- All backend commands use `docker compose exec backend …` so Postgres partial indexes and citext work.
- Conventional Commits (`feat:`, `fix:`, `chore:`, `test:`, `docs:`, `refactor:`, `ci:`).
- One task = one commit. Don't squash.
- Commit trailer:
  ```
  Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
  ```
  Use heredoc form (see slice 3 plan §Conventions).
- TDD for every endpoint and form: failing test first, then code, then green.
- Build on `main` after slice 3's `slice-3-fish-canvas` tag.
- Frontend tests run with `cd frontend && npm test -- --run`; backend tests run with `docker compose exec backend ./vendor/bin/pest`.

---

## Task 1: Slice prep — verify slice 3 baseline + MinIO live

**Files:**
- (none — read-only verification)

- [ ] **Step 1: Confirm clean tree on `main` at slice 3 tag.**

```bash
git status
git describe --tags --abbrev=0
```

Expected: working tree clean; tag prints `slice-3-fish-canvas`.

- [ ] **Step 2: Confirm slice-2 stubs and slice-3 surfaces exist.**

```bash
test -f backend/app/Policies/BackgroundPolicy.php && echo OK-policy-stub
test -f frontend/src/app/api/proxy/\[...path\]/route.ts && echo OK-proxy
test -f frontend/src/components/aquarium/AquariumCanvas.tsx && echo OK-canvas
docker compose up -d db redis minio minio-init
sleep 5
docker compose exec backend php artisan migrate --pretend | head -20
```

Expected: all `OK-` lines print; migrate-pretend shows slice 1–3 migrations as already applied; MinIO and minio-init come up.

- [ ] **Step 3: Verify the bucket exists.**

```bash
docker compose exec minio-init /usr/bin/mc ls local/fishbook || true
```

Expected: empty list (the bucket exists, no objects yet).

- [ ] **Step 4: No commit.** Verification only.

---

## Task 2: Backend — install `intervention/image` v3 + config

**Files:**
- Modify: `backend/composer.json`, `backend/composer.lock`
- Modify: `backend/config/services.php`

- [ ] **Step 1: Add the dep.**

```bash
docker compose exec backend composer require intervention/image:^3.7
```

- [ ] **Step 2: Append a `fal` block + a `backgrounds` block to `config/services.php`.**

```php
'fal' => [
    'api_key'            => env('FAL_API_KEY'),
    'base_url'           => env('FAL_BASE_URL', 'https://queue.fal.run'),
    'model'              => env('FAL_MODEL', 'fal-ai/flux-2/turbo'),
    'daily_global_limit' => (int) env('FAL_DAILY_GLOBAL_LIMIT', 200),
    'prompt_denylist'    => [
        'nsfw', 'nude', 'naked', 'explicit', 'porn', 'xxx', 'sexual', 'blood', 'gore',
    ],
    'poll_interval_ms'   => 1000,
    'poll_max_seconds'   => 60,
],
```

- [ ] **Step 3: Sanity check.**

```bash
docker compose exec backend php -r "var_dump(config('services.fal.model'));"
```

Expected: `string(20) "fal-ai/flux-2/turbo"`.

- [ ] **Step 4: Commit.**

```bash
git add backend/composer.json backend/composer.lock backend/config/services.php
git commit -m "$(cat <<'EOF'
chore(backend): add intervention/image v3 + services.fal config (model, denylist, poll budget)

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Backend — `backgrounds` migration

**Files:**
- Create: `backend/database/migrations/2026_05_16_000010_create_backgrounds_table.php`

- [ ] **Step 1: Create the migration.**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backgrounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('storage_key', 255);
            $table->integer('width');
            $table->integer('height');
            $table->text('prompt')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'deleted_at']);
        });

        DB::statement("ALTER TABLE backgrounds ADD CONSTRAINT backgrounds_kind_chk CHECK (kind IN ('upload','generated','preset'))");
        DB::statement('CREATE UNIQUE INDEX one_active_bg_per_user ON backgrounds(user_id) WHERE is_active = true AND deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('backgrounds');
    }
};
```

- [ ] **Step 2: Run migration.**

```bash
docker compose exec backend php artisan migrate
```

- [ ] **Step 3: Verify schema.**

```bash
docker compose exec db psql -U fishbook -d fishbook -c "\d backgrounds"
docker compose exec db psql -U fishbook -d fishbook -c "\di backgrounds*"
```

Expected: columns + `one_active_bg_per_user` partial unique + `backgrounds_kind_chk` CHECK present.

- [ ] **Step 4: Commit.**

```bash
git add backend/database/migrations/2026_05_16_000010_create_backgrounds_table.php
git commit -m "$(cat <<'EOF'
feat(backend): create backgrounds table with soft-delete, partial unique active-per-user, kind CHECK (SPEC §3)

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Backend — `Background` model, factory, exceptions, policy fill-in

**Files:**
- Create: `backend/app/Models/Background.php`
- Create: `backend/database/factories/BackgroundFactory.php`
- Create: `backend/app/Exceptions/Backgrounds/InvalidImageException.php`
- Create: `backend/app/Exceptions/Backgrounds/DimensionsTooSmallException.php`
- Create: `backend/app/Exceptions/Backgrounds/FileTooLargeException.php`
- Create: `backend/app/Exceptions/Backgrounds/DisallowedPromptException.php`
- Create: `backend/app/Exceptions/FalAi/FalAiTimeoutException.php`
- Create: `backend/app/Exceptions/FalAi/FalAiFailedException.php`
- Create: `backend/app/Exceptions/FalAi/FalAiQuotaException.php`
- Modify: `backend/app/Policies/BackgroundPolicy.php`

- [ ] **Step 1: Model.**

```php
<?php

namespace App\Models;

use Database\Factories\BackgroundFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Background extends Model
{
    /** @use HasFactory<BackgroundFactory> */
    use HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'kind', 'storage_key', 'width', 'height', 'prompt', 'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'width' => 'integer', 'height' => 'integer'];
    }

    /** @return BelongsTo<User, Background> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param Builder<Background> $q */
    public function scopeForUser(Builder $q, int $userId): Builder
    {
        return $q->where('user_id', $userId);
    }

    /** @param Builder<Background> $q */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }
}
```

- [ ] **Step 2: Factory.**

```php
<?php

namespace Database\Factories;

use App\Models\Background;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Background> */
class BackgroundFactory extends Factory
{
    protected $model = Background::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id'     => User::factory(),
            'kind'        => 'upload',
            'storage_key' => 'backgrounds/u-test/'.Str::ulid()->toBase32().'.webp',
            'width'       => 1920,
            'height'      => 1080,
            'prompt'      => null,
            'is_active'   => false,
        ];
    }

    public function generated(string $prompt = 'a calm coral reef'): static
    {
        return $this->state(fn () => ['kind' => 'generated', 'prompt' => $prompt]);
    }

    public function active(): static
    {
        return $this->state(fn () => ['is_active' => true]);
    }
}
```

- [ ] **Step 3: Exceptions (one file each).** Each extends `RuntimeException` with no body — they exist only to be `instanceof`-matched in the controller's exception handler. Example:

```php
<?php
namespace App\Exceptions\Backgrounds;
class InvalidImageException extends \RuntimeException {}
```

Repeat for `DimensionsTooSmallException`, `FileTooLargeException`, `DisallowedPromptException`, and under `App\Exceptions\FalAi\` for `FalAiTimeoutException`, `FalAiFailedException`, `FalAiQuotaException`.

- [ ] **Step 4: Fill in `BackgroundPolicy`.**

```php
<?php

namespace App\Policies;

use App\Models\Background;
use App\Models\User;

class BackgroundPolicy
{
    public function viewAny(User $user): bool { return true; }

    public function view(User $user, Background $bg): bool
    {
        return $user->id === $bg->user_id || $user->is_admin;
    }

    public function create(User $user): bool { return true; }

    public function update(User $user, Background $bg): bool
    {
        return $user->id === $bg->user_id;
    }

    public function delete(User $user, Background $bg): bool
    {
        return $user->id === $bg->user_id;
    }
}
```

- [ ] **Step 5: Commit.**

```bash
git add backend/app/Models/Background.php backend/database/factories/BackgroundFactory.php backend/app/Exceptions/ backend/app/Policies/BackgroundPolicy.php
git commit -m "$(cat <<'EOF'
feat(backend): add Background model + factory + domain exceptions; fill in BackgroundPolicy

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Backend — `PromptDenylist` service (TDD)

**Files:**
- Create: `backend/tests/Unit/Services/Backgrounds/Prompts/PromptDenylistTest.php`
- Create: `backend/app/Services/Backgrounds/Prompts/PromptDenylist.php`

- [ ] **Step 1: Failing tests.**

```php
<?php

use App\Exceptions\Backgrounds\DisallowedPromptException;
use App\Services\Backgrounds\Prompts\PromptDenylist;

uses(Tests\TestCase::class);

it('passes an allowed prompt', function () {
    app(PromptDenylist::class)->assertAllowed('a calm coral reef at dusk');
    expect(true)->toBeTrue();
});

it('throws on a denylisted substring', function () {
    expect(fn () => app(PromptDenylist::class)->assertAllowed('something nsfw here'))
        ->toThrow(DisallowedPromptException::class);
});

it('is case-insensitive', function () {
    expect(fn () => app(PromptDenylist::class)->assertAllowed('UPPER NUDE PROMPT'))
        ->toThrow(DisallowedPromptException::class);
});
```

- [ ] **Step 2: Implement.**

```php
<?php

namespace App\Services\Backgrounds\Prompts;

use App\Exceptions\Backgrounds\DisallowedPromptException;
use Illuminate\Contracts\Config\Repository;

class PromptDenylist
{
    public function __construct(private readonly Repository $config) {}

    public function assertAllowed(string $prompt): void
    {
        $needles = (array) $this->config->get('services.fal.prompt_denylist', []);
        $hay = mb_strtolower($prompt);
        foreach ($needles as $n) {
            if ($n !== '' && str_contains($hay, mb_strtolower((string) $n))) {
                throw new DisallowedPromptException('Prompt contains disallowed content.');
            }
        }
    }
}
```

- [ ] **Step 3: Run + commit.**

```bash
docker compose exec backend ./vendor/bin/pest --filter=PromptDenylist
git add backend/app/Services/Backgrounds/Prompts/PromptDenylist.php backend/tests/Unit/Services/Backgrounds/Prompts/PromptDenylistTest.php
git commit -m "$(cat <<'EOF'
feat(backend): add PromptDenylist service (config-driven, case-insensitive substring match)

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: Backend — image fixtures + `BackgroundImageProcessor` (TDD)

**Files:**
- Create: `backend/tests/fixtures/backgrounds/valid-1280x720.jpg`
- Create: `backend/tests/fixtures/backgrounds/too-small-800x600.jpg`
- Create: `backend/tests/fixtures/backgrounds/with-gps-exif.jpg`
- Create: `backend/tests/fixtures/backgrounds/too-big-7mb.jpg`
- Create: `backend/tests/fixtures/backgrounds/not-an-image.jpg` (a text file masquerading)
- Create: `backend/tests/Unit/Services/Backgrounds/BackgroundImageProcessorTest.php`
- Create: `backend/app/Services/Backgrounds/BackgroundImageProcessor.php`

- [ ] **Step 1: Generate the fixtures (use ImageMagick inside the backend container).**

```bash
mkdir -p backend/tests/fixtures/backgrounds
docker compose exec backend bash -lc '
  apt-get update >/dev/null 2>&1 && apt-get install -y imagemagick exiftool >/dev/null 2>&1 || true
  convert -size 1280x720 xc:steelblue /var/www/html/tests/fixtures/backgrounds/valid-1280x720.jpg
  convert -size 800x600  xc:olive     /var/www/html/tests/fixtures/backgrounds/too-small-800x600.jpg
  convert -size 1280x720 xc:plum      /var/www/html/tests/fixtures/backgrounds/with-gps-exif.jpg
  exiftool -overwrite_original -GPSLatitude=37.4220 -GPSLongitude=-122.0841 /var/www/html/tests/fixtures/backgrounds/with-gps-exif.jpg
  dd if=/dev/urandom of=/var/www/html/tests/fixtures/backgrounds/too-big-7mb.jpg bs=1M count=7
  echo "Im not a jpeg, just pretending." > /var/www/html/tests/fixtures/backgrounds/not-an-image.jpg
'
ls -la backend/tests/fixtures/backgrounds/
```

Expected: 5 files; `with-gps-exif.jpg` has the GPS tags (verify with `exiftool backend/tests/fixtures/backgrounds/with-gps-exif.jpg | grep GPS`).

- [ ] **Step 2: Failing tests.**

```php
<?php

use App\Exceptions\Backgrounds\DimensionsTooSmallException;
use App\Exceptions\Backgrounds\FileTooLargeException;
use App\Exceptions\Backgrounds\InvalidImageException;
use App\Services\Backgrounds\BackgroundImageProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(Tests\TestCase::class);

beforeEach(fn () => Storage::fake('s3'));

function upload(string $name): UploadedFile {
    return new UploadedFile(base_path("tests/fixtures/backgrounds/$name"), $name, null, null, true);
}

it('accepts a 1280x720 jpeg and stores a webp', function () {
    $result = app(BackgroundImageProcessor::class)->process(upload('valid-1280x720.jpg'), 7);
    expect($result['width'])->toBe(1280);
    expect($result['height'])->toBe(720);
    expect($result['storage_key'])->toStartWith('backgrounds/u7/')->toEndWith('.webp');
    Storage::disk('s3')->assertExists($result['storage_key']);
});

it('rejects an 800x600 image', function () {
    expect(fn () => app(BackgroundImageProcessor::class)->process(upload('too-small-800x600.jpg'), 7))
        ->toThrow(DimensionsTooSmallException::class);
});

it('rejects a 7MB file', function () {
    expect(fn () => app(BackgroundImageProcessor::class)->process(upload('too-big-7mb.jpg'), 7))
        ->toThrow(FileTooLargeException::class);
});

it('rejects a text file masquerading as a jpeg', function () {
    expect(fn () => app(BackgroundImageProcessor::class)->process(upload('not-an-image.jpg'), 7))
        ->toThrow(InvalidImageException::class);
});

it('strips EXIF GPS data on re-encode', function () {
    $result = app(BackgroundImageProcessor::class)->process(upload('with-gps-exif.jpg'), 7);
    $bytes = Storage::disk('s3')->get($result['storage_key']);
    // WebP doesn't carry JPEG EXIF chunks; the marker should not appear.
    expect(str_contains($bytes, "Exif\x00\x00"))->toBeFalse();
});
```

- [ ] **Step 3: Implement.**

```php
<?php

namespace App\Services\Backgrounds;

use App\Exceptions\Backgrounds\DimensionsTooSmallException;
use App\Exceptions\Backgrounds\FileTooLargeException;
use App\Exceptions\Backgrounds\InvalidImageException;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;

class BackgroundImageProcessor
{
    private const MAX_BYTES   = 5 * 1024 * 1024;
    private const MIN_WIDTH   = 1280;
    private const MIN_HEIGHT  = 720;
    private const MAX_LONG_EDGE = 2560;
    private const QUALITY     = 85;
    private const ALLOWED     = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly ImageManager $manager,
        private readonly Filesystems $filesystems,
    ) {}

    /** @return array{storage_key:string,width:int,height:int} */
    public function process(UploadedFile $file, int $userId): array
    {
        if ($file->getSize() > self::MAX_BYTES) {
            throw new FileTooLargeException('Image exceeds 5 MB.');
        }
        try {
            $img = $this->manager->read($file->getRealPath());
        } catch (\Throwable) {
            throw new InvalidImageException('File is not a decodable image.');
        }
        $mime = $img->origin()->mediaType();
        if (! in_array($mime, self::ALLOWED, true)) {
            throw new InvalidImageException("Unsupported MIME: {$mime}.");
        }
        $w = $img->width(); $h = $img->height();
        if ($w < self::MIN_WIDTH || $h < self::MIN_HEIGHT) {
            throw new DimensionsTooSmallException("Need at least 1280×720; got {$w}×{$h}.");
        }
        $long = max($w, $h);
        if ($long > self::MAX_LONG_EDGE) {
            $img->scaleDown(self::MAX_LONG_EDGE, self::MAX_LONG_EDGE);
            $w = $img->width(); $h = $img->height();
        }
        $key = "backgrounds/u{$userId}/" . Str::ulid()->toBase32() . '.webp';
        $bytes = (string) $img->toWebp(self::QUALITY)->toString();
        $this->filesystems->disk('s3')->put($key, $bytes);

        return ['storage_key' => $key, 'width' => $w, 'height' => $h];
    }
}
```

- [ ] **Step 4: Run.**

```bash
docker compose exec backend ./vendor/bin/pest --filter=BackgroundImageProcessor
```

Expected: 5 green.

- [ ] **Step 5: Commit.**

```bash
git add backend/tests/fixtures/backgrounds/ backend/tests/Unit/Services/Backgrounds/BackgroundImageProcessorTest.php backend/app/Services/Backgrounds/BackgroundImageProcessor.php
git commit -m "$(cat <<'EOF'
feat(backend): add BackgroundImageProcessor (deep MIME sniff, dim check, EXIF strip, WebP re-encode)

Layered MIME defense: Laravel's mimes:* (finfo) + Intervention v3's read() deep decode.
Output is a per-user ULID path: backgrounds/u{userId}/{ulid}.webp at quality 85.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: Backend — `FalAiClient` (TDD; mocked Http)

**Files:**
- Create: `backend/tests/Unit/Services/FalAi/FalAiClientTest.php`
- Create: `backend/app/Services/FalAi/FalAiClient.php`

- [ ] **Step 1: Failing tests.**

```php
<?php

use App\Exceptions\FalAi\FalAiQuotaException;
use App\Exceptions\FalAi\FalAiTimeoutException;
use App\Services\FalAi\FalAiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

uses(Tests\TestCase::class);

beforeEach(function () {
    config()->set('services.fal.api_key', 'secret-XXXX');
    config()->set('services.fal.base_url', 'https://queue.fal.run');
    config()->set('services.fal.model', 'fal-ai/flux-2/turbo');
    config()->set('services.fal.poll_interval_ms', 1);   // speed up tests
    config()->set('services.fal.poll_max_seconds', 1);
    Storage::fake('s3');
});

it('submits, polls until COMPLETED, fetches and stores the webp', function () {
    Http::fake([
        'queue.fal.run/fal-ai/flux-2/turbo' => Http::response([
            'request_id' => 'req-1', 'status_url' => 'https://queue.fal.run/req-1', 'response_url' => 'https://queue.fal.run/req-1/result',
        ], 200),
        'queue.fal.run/req-1' => Http::sequence()
            ->push(['status' => 'IN_PROGRESS'], 200)
            ->push(['status' => 'COMPLETED'], 200),
        'queue.fal.run/req-1/result' => Http::response([
            'images' => [['url' => 'https://cdn.fal.test/image-1.png', 'width' => 1920, 'height' => 1080]],
        ], 200),
        'cdn.fal.test/image-1.png' => Http::response(file_get_contents(base_path('tests/fixtures/backgrounds/valid-1280x720.jpg')), 200, ['content-type' => 'image/jpeg']),
    ]);

    $r = app(FalAiClient::class)->generateBackground('a calm coral reef', '16:9', 42);

    expect($r['storage_key'])->toStartWith('backgrounds/u42/')->toEndWith('.webp');
    Storage::disk('s3')->assertExists($r['storage_key']);
});

it('retries on 5xx on submit and then succeeds', function () {
    Http::fake([
        'queue.fal.run/fal-ai/flux-2/turbo' => Http::sequence()
            ->push(['error' => 'boom'], 500)
            ->push(['request_id' => 'req-2', 'status_url' => 'https://queue.fal.run/req-2', 'response_url' => 'https://queue.fal.run/req-2/result'], 200),
        'queue.fal.run/req-2' => Http::response(['status' => 'COMPLETED'], 200),
        'queue.fal.run/req-2/result' => Http::response(['images' => [['url' => 'https://cdn.fal.test/x.png']]], 200),
        'cdn.fal.test/x.png' => Http::response(file_get_contents(base_path('tests/fixtures/backgrounds/valid-1280x720.jpg')), 200, ['content-type' => 'image/jpeg']),
    ]);

    $r = app(FalAiClient::class)->generateBackground('p', '16:9', 1);
    expect($r['storage_key'])->toContain('backgrounds/u1/');
});

it('throws on timeout when polls never complete', function () {
    Http::fake([
        'queue.fal.run/fal-ai/flux-2/turbo' => Http::response(['request_id' => 'req-3', 'status_url' => 'https://queue.fal.run/req-3', 'response_url' => 'https://queue.fal.run/req-3/result'], 200),
        'queue.fal.run/req-3' => Http::response(['status' => 'IN_PROGRESS'], 200),
    ]);
    expect(fn () => app(FalAiClient::class)->generateBackground('p', '16:9', 1))
        ->toThrow(FalAiTimeoutException::class);
});

it('throws on 429', function () {
    Http::fake(['queue.fal.run/fal-ai/flux-2/turbo' => Http::response(['error' => 'rate'], 429)]);
    expect(fn () => app(FalAiClient::class)->generateBackground('p', '16:9', 1))
        ->toThrow(FalAiQuotaException::class);
});

it('never logs FAL_API_KEY', function () {
    $records = [];
    Log::listen(function ($level, $message, $context = []) use (&$records) {
        $records[] = json_encode($context) . ' ' . $message;
    });
    Http::fake([
        'queue.fal.run/fal-ai/flux-2/turbo' => Http::response(['request_id' => 'req-4', 'status_url' => 'https://queue.fal.run/req-4', 'response_url' => 'https://queue.fal.run/req-4/result'], 200),
        'queue.fal.run/req-4' => Http::response(['status' => 'COMPLETED'], 200),
        'queue.fal.run/req-4/result' => Http::response(['images' => [['url' => 'https://cdn.fal.test/y.png']]], 200),
        'cdn.fal.test/y.png' => Http::response(file_get_contents(base_path('tests/fixtures/backgrounds/valid-1280x720.jpg')), 200, ['content-type' => 'image/jpeg']),
    ]);
    app(FalAiClient::class)->generateBackground('a calm reef', '16:9', 1);
    expect(collect($records)->some(fn ($r) => str_contains($r, 'secret-XXXX')))->toBeFalse();
});
```

- [ ] **Step 2: Implement.**

```php
<?php

namespace App\Services\FalAi;

use App\Exceptions\FalAi\FalAiFailedException;
use App\Exceptions\FalAi\FalAiQuotaException;
use App\Exceptions\FalAi\FalAiTimeoutException;
use App\Services\Backgrounds\BackgroundImageProcessor;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class FalAiClient
{
    public function __construct(
        private readonly Http $http,
        private readonly Repository $config,
        private readonly BackgroundImageProcessor $processor,
    ) {}

    /** @return array{storage_key:string,width:int,height:int} */
    public function generateBackground(string $prompt, string $aspectRatio, int $userId): array
    {
        $base   = rtrim((string) $this->config->get('services.fal.base_url'), '/');
        $model  = (string) $this->config->get('services.fal.model');
        $apiKey = (string) $this->config->get('services.fal.api_key');
        $maxS   = (int) $this->config->get('services.fal.poll_max_seconds', 60);
        $intMs  = (int) $this->config->get('services.fal.poll_interval_ms', 1000);

        Log::info('falai.submit', ['user_id' => $userId, 'prompt' => $prompt, 'aspect_ratio' => $aspectRatio]);

        $client = $this->http
            ->withHeaders(['Authorization' => 'Key '.$apiKey, 'accept' => 'application/json'])
            ->connectTimeout(5)
            ->timeout(60)
            ->retry(2, 250, throw: false);

        $submit = $client->post("$base/$model", [
            'prompt'     => $prompt,
            'image_size' => $this->imageSizeFor($aspectRatio),
            'num_images' => 1,
        ]);
        if ($submit->status() === 429) throw new FalAiQuotaException('Fal AI quota exhausted.');
        if (! $submit->successful())   throw new FalAiFailedException('Fal AI submit failed: '.$submit->status());

        $statusUrl   = (string) $submit->json('status_url');
        $responseUrl = (string) $submit->json('response_url');

        $deadline = microtime(true) + $maxS;
        while (microtime(true) < $deadline) {
            $poll = $client->get($statusUrl);
            if (! $poll->successful()) throw new FalAiFailedException('Fal AI poll failed: '.$poll->status());
            if ($poll->json('status') === 'COMPLETED') break;
            if ($poll->json('status') === 'FAILED')    throw new FalAiFailedException('Fal AI reported FAILED.');
            usleep($intMs * 1000);
        }
        if (microtime(true) >= $deadline) throw new FalAiTimeoutException('Fal AI did not complete within 60s.');

        $result = $client->get($responseUrl);
        $url    = (string) $result->json('images.0.url');
        if ($url === '') throw new FalAiFailedException('Fal AI returned no image URL.');

        $imageBytes = $client->get($url)->body();
        $tmp = tempnam(sys_get_temp_dir(), 'fal-');
        file_put_contents($tmp, $imageBytes);
        $upload = new UploadedFile($tmp, 'generated.png', 'image/png', null, true);

        return $this->processor->process($upload, $userId);
    }

    private function imageSizeFor(string $aspect): string
    {
        return match ($aspect) {
            '3:2' => 'landscape_3_2',
            '1:1' => 'square_hd',
            default => 'landscape_16_9',
        };
    }
}
```

- [ ] **Step 3: Run + commit.**

```bash
docker compose exec backend ./vendor/bin/pest --filter=FalAiClient
git add backend/app/Services/FalAi/FalAiClient.php backend/tests/Unit/Services/FalAi/FalAiClientTest.php
git commit -m "$(cat <<'EOF'
feat(backend): add FalAiClient (Http facade, submit→poll≤60s→fetch→reuse processor); retry+timeout+quota

API key never appears in logs (asserted). All Pest tests use Http::fake; CI never calls Fal.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: Backend — `BackgroundService` (TDD)

**Files:**
- Create: `backend/tests/Unit/Services/Backgrounds/BackgroundServiceTest.php`
- Create: `backend/app/Services/Backgrounds/BackgroundService.php`

- [ ] **Step 1: Failing tests.** Cover: `upload` creates a row and selects-as-active when none active; `upload` does NOT auto-flip when one already active; `generate` runs the denylist; `select` atomically flips inside a transaction; `delete` soft-deletes and dispatches `PurgeBackgroundJob` with 7-day delay (using `Queue::fake()` + `assertPushedWithDelay`).

```php
<?php

use App\Jobs\PurgeBackgroundJob;
use App\Models\Background;
use App\Models\User;
use App\Services\Backgrounds\BackgroundService;
use Illuminate\Support\Facades\Queue;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

it('select flips prior active to false and target to true atomically', function () {
    $u = User::factory()->create();
    $a = Background::factory()->for($u)->create(['is_active' => true]);
    $b = Background::factory()->for($u)->create();

    app(BackgroundService::class)->select($u, $b);

    expect($a->fresh()->is_active)->toBeFalse();
    expect($b->fresh()->is_active)->toBeTrue();
});

it('delete soft-deletes and dispatches purge with 7-day delay', function () {
    Queue::fake();
    $u = User::factory()->create();
    $bg = Background::factory()->for($u)->create();
    app(BackgroundService::class)->delete($u, $bg);

    expect($bg->fresh()->trashed())->toBeTrue();
    Queue::assertPushed(PurgeBackgroundJob::class, function ($job) {
        return $job->delay !== null && $job->delay->diffInDays(now()) >= 7;
    });
});
```

- [ ] **Step 2: Implement.**

```php
<?php

namespace App\Services\Backgrounds;

use App\Jobs\PurgeBackgroundJob;
use App\Models\Background;
use App\Models\User;
use App\Services\Backgrounds\Prompts\PromptDenylist;
use App\Services\FalAi\FalAiClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class BackgroundService
{
    public function __construct(
        private readonly BackgroundImageProcessor $processor,
        private readonly FalAiClient $fal,
        private readonly PromptDenylist $denylist,
    ) {}

    public function upload(User $user, UploadedFile $file): Background
    {
        $r = $this->processor->process($file, $user->id);
        return $this->createAndMaybeActivate($user, [
            'kind' => 'upload', 'storage_key' => $r['storage_key'],
            'width' => $r['width'], 'height' => $r['height'], 'prompt' => null,
        ]);
    }

    public function generate(User $user, string $prompt, string $aspectRatio): Background
    {
        $this->denylist->assertAllowed($prompt);
        $r = $this->fal->generateBackground($prompt, $aspectRatio, $user->id);
        return $this->createAndMaybeActivate($user, [
            'kind' => 'generated', 'storage_key' => $r['storage_key'],
            'width' => $r['width'], 'height' => $r['height'], 'prompt' => $prompt,
        ]);
    }

    public function select(User $user, Background $bg): Background
    {
        return DB::transaction(function () use ($user, $bg) {
            Background::forUser($user->id)->active()->update(['is_active' => false]);
            $bg->forceFill(['is_active' => true])->save();
            return $bg->fresh();
        });
    }

    public function delete(User $user, Background $bg): void
    {
        $bg->delete();
        PurgeBackgroundJob::dispatch($bg->id)->delay(now()->addDays(7));
    }

    /** @param array<string,mixed> $attrs */
    private function createAndMaybeActivate(User $user, array $attrs): Background
    {
        return DB::transaction(function () use ($user, $attrs) {
            $bg = Background::create([...$attrs, 'user_id' => $user->id, 'is_active' => false]);
            $hasActive = Background::forUser($user->id)->active()->exists();
            if (! $hasActive) {
                $bg->forceFill(['is_active' => true])->save();
            }
            return $bg->fresh();
        });
    }
}
```

- [ ] **Step 3: Run + commit.**

```bash
docker compose exec backend ./vendor/bin/pest --filter=BackgroundService
git add backend/app/Services/Backgrounds/BackgroundService.php backend/tests/Unit/Services/Backgrounds/BackgroundServiceTest.php
git commit -m "$(cat <<'EOF'
feat(backend): add BackgroundService (upload/generate/select/delete) with transactional active-flip

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: Backend — `PurgeBackgroundJob` + orphans command (TDD)

**Files:**
- Create: `backend/tests/Unit/Jobs/PurgeBackgroundJobTest.php`
- Create: `backend/app/Jobs/PurgeBackgroundJob.php`
- Create: `backend/app/Console/Commands/BackgroundsPurgeOrphansCommand.php`
- Modify: `backend/routes/console.php`

- [ ] **Step 1: Failing test.**

```php
<?php

use App\Jobs\PurgeBackgroundJob;
use App\Models\Background;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

it('deletes the S3 object for a soft-deleted background', function () {
    Storage::fake('s3');
    $u = User::factory()->create();
    $bg = Background::factory()->for($u)->create(['storage_key' => 'backgrounds/u1/x.webp']);
    Storage::disk('s3')->put($bg->storage_key, 'fake');
    $bg->delete();
    (new PurgeBackgroundJob($bg->id))->handle();
    Storage::disk('s3')->assertMissing('backgrounds/u1/x.webp');
});

it('aborts if deleted_at was cleared (restore race)', function () {
    Storage::fake('s3');
    $bg = Background::factory()->create(['storage_key' => 'backgrounds/u2/y.webp']);
    Storage::disk('s3')->put($bg->storage_key, 'fake');
    (new PurgeBackgroundJob($bg->id))->handle();
    Storage::disk('s3')->assertExists('backgrounds/u2/y.webp');
});
```

- [ ] **Step 2: Implement.**

```php
<?php

namespace App\Jobs;

use App\Models\Background;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PurgeBackgroundJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $backgroundId) {}

    public function handle(): void
    {
        $bg = Background::withTrashed()->find($this->backgroundId);
        if (! $bg)                 return;
        if ($bg->deleted_at === null) return; // restore race

        Storage::disk('s3')->delete($bg->storage_key);
        Log::info('background.purged', ['background_id' => $bg->id]);
    }
}
```

- [ ] **Step 3: Orphans command.**

```php
<?php

namespace App\Console\Commands;

use App\Jobs\PurgeBackgroundJob;
use App\Models\Background;
use Illuminate\Console\Command;

class BackgroundsPurgeOrphansCommand extends Command
{
    protected $signature = 'backgrounds:purge-orphans';
    protected $description = 'Reconcile soft-deleted backgrounds older than 7 days whose purge job did not fire.';

    public function handle(): int
    {
        $count = 0;
        Background::onlyTrashed()
            ->where('deleted_at', '<', now()->subDays(7))
            ->chunkById(100, function ($rows) use (&$count) {
                foreach ($rows as $bg) {
                    PurgeBackgroundJob::dispatch($bg->id);
                    $count++;
                }
            });
        $this->info("Dispatched purge for $count orphans.");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Schedule daily.** Append to `backend/routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('backgrounds:purge-orphans')->dailyAt('03:00');
```

- [ ] **Step 5: Run + commit.**

```bash
docker compose exec backend ./vendor/bin/pest --filter=PurgeBackgroundJob
git add backend/app/Jobs/PurgeBackgroundJob.php backend/app/Console/Commands/BackgroundsPurgeOrphansCommand.php backend/routes/console.php backend/tests/Unit/Jobs/PurgeBackgroundJobTest.php
git commit -m "$(cat <<'EOF'
feat(backend): add PurgeBackgroundJob (7-day delayed S3 purge) + daily orphans reconciliation command

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 10: Backend — `RateLimiter::for('generate')`

**Files:**
- Modify: `backend/app/Providers/AppServiceProvider.php`

- [ ] **Step 1: Add the named limiter inside `boot()`.**

```php
RateLimiter::for('generate', function (Request $request) {
    $userId = optional($request->user())->id;
    $dailyGlobal = (int) config('services.fal.daily_global_limit', 200);

    return [
        Limit::perHour(10)->by("generate-u:{$userId}")
            ->response(fn () => response()->json(['message' => 'Generation rate limit reached. Try again later.'], 429)),
        Limit::perDay($dailyGlobal)->by('generate-global')
            ->response(fn () => response()->json(['message' => 'Daily generation ceiling reached.'], 503, ['Retry-After' => '3600'])),
    ];
});
```

- [ ] **Step 2: Commit.**

```bash
git add backend/app/Providers/AppServiceProvider.php
git commit -m "$(cat <<'EOF'
feat(backend): register RateLimiter::for('generate') — 10/hr per user AND 200/day global

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 11: Backend — Form requests + Resources

**Files:**
- Create: `backend/app/Http/Requests/Backgrounds/UploadBackgroundRequest.php`
- Create: `backend/app/Http/Requests/Backgrounds/GenerateBackgroundRequest.php`
- Create: `backend/app/Http/Requests/Backgrounds/SelectBackgroundRequest.php`
- Create: `backend/app/Http/Resources/BackgroundResource.php`
- Modify: `backend/app/OpenApi/Schemas.php`

- [ ] **Step 1: `UploadBackgroundRequest`.**

```php
<?php

namespace App\Http\Requests\Backgrounds;

use App\Models\Background;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * Layered MIME defense: this FormRequest's `mimes:` rule is the cheap
 * first gate (finfo). The deep gate lives in BackgroundImageProcessor
 * (Intervention v3 actually decodes the file). Both must pass.
 */
#[OA\Schema(
    schema: 'UploadBackgroundRequest',
    type: 'object',
    required: ['image'],
    properties: [new OA\Property(property: 'image', type: 'string', format: 'binary')],
)]
class UploadBackgroundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Background::class) ?? false;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['image' => ['required', 'file', 'max:5120', 'mimes:jpeg,png,webp']];
    }
}
```

- [ ] **Step 2: `GenerateBackgroundRequest`.**

```php
<?php

namespace App\Http\Requests\Backgrounds;

use App\Models\Background;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'GenerateBackgroundRequest',
    type: 'object',
    required: ['prompt'],
    properties: [
        new OA\Property(property: 'prompt', type: 'string', minLength: 3, maxLength: 500),
        new OA\Property(property: 'aspect_ratio', type: 'string', enum: ['16:9','3:2','1:1'], default: '16:9'),
    ],
)]
class GenerateBackgroundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Background::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('aspect_ratio') === null) $this->merge(['aspect_ratio' => '16:9']);
        if (is_string($this->input('prompt'))) $this->merge(['prompt' => trim($this->input('prompt'))]);
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'prompt'       => ['required', 'string', 'min:3', 'max:500'],
            'aspect_ratio' => ['nullable', 'in:16:9,3:2,1:1'],
        ];
    }
}
```

- [ ] **Step 3: `SelectBackgroundRequest`.**

```php
<?php

namespace App\Http\Requests\Backgrounds;

use Illuminate\Foundation\Http\FormRequest;

class SelectBackgroundRequest extends FormRequest
{
    public function authorize(): bool
    {
        $bg = $this->route('background');
        return $bg && $this->user()?->can('update', $bg);
    }

    /** @return array<string,mixed> */
    public function rules(): array { return []; }
}
```

- [ ] **Step 4: `BackgroundResource`.**

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'BackgroundResource',
    type: 'object',
    required: ['id', 'kind', 'storage_key', 'signed_url', 'width', 'height', 'is_active', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'kind', type: 'string', enum: ['upload','generated','preset']),
        new OA\Property(property: 'storage_key', type: 'string'),
        new OA\Property(property: 'signed_url', type: 'string', format: 'uri'),
        new OA\Property(property: 'width', type: 'integer'),
        new OA\Property(property: 'height', type: 'integer'),
        new OA\Property(property: 'prompt', type: 'string', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
)]
class BackgroundResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'          => (string) $this->id,
            'kind'        => $this->kind,
            'storage_key' => $this->storage_key,
            'signed_url'  => Storage::disk('s3')->temporaryUrl($this->storage_key, now()->addHour()),
            'width'       => (int) $this->width,
            'height'      => (int) $this->height,
            'prompt'      => $this->prompt,
            'is_active'   => (bool) $this->is_active,
            'created_at'  => $this->created_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 5: Add `BackgroundCollection` + `BackgroundResourceEnvelope` to `backend/app/OpenApi/Schemas.php`** (alongside slice 3's `PaginatedFishCollection`):

```php
#[OA\Schema(
    schema: 'BackgroundCollection',
    type: 'object',
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/BackgroundResource')),
        new OA\Property(property: 'links', type: 'object'),
        new OA\Property(property: 'meta', type: 'object'),
    ],
)]
#[OA\Schema(
    schema: 'BackgroundResourceEnvelope',
    type: 'object',
    properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BackgroundResource')],
)]
```

- [ ] **Step 6: Commit.**

```bash
git add backend/app/Http/Requests/Backgrounds/ backend/app/Http/Resources/BackgroundResource.php backend/app/OpenApi/Schemas.php
git commit -m "$(cat <<'EOF'
feat(backend): add Upload/Generate/SelectBackgroundRequest + BackgroundResource (signed URL) + OpenAPI schemas

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 12: Backend — `BackgroundController` + routes

**Files:**
- Create: `backend/app/Http/Controllers/Api/V1/BackgroundController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/app/Exceptions/Handler.php` (or `bootstrap/app.php` for Laravel 13 streamlined handler) to map domain exceptions to HTTP codes.

- [ ] **Step 1: Controller.**

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backgrounds\GenerateBackgroundRequest;
use App\Http\Requests\Backgrounds\SelectBackgroundRequest;
use App\Http\Requests\Backgrounds\UploadBackgroundRequest;
use App\Http\Resources\BackgroundResource;
use App\Models\Background;
use App\Services\Backgrounds\BackgroundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BackgroundController extends Controller
{
    public function __construct(private readonly BackgroundService $service)
    {
        $this->authorizeResource(Background::class, 'background');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = (int) min($request->integer('per_page', 25), 50);
        $page = Background::forUser($request->user()->id)
            ->orderByDesc('is_active')->orderByDesc('created_at')
            ->paginate($perPage);
        return BackgroundResource::collection($page);
    }

    public function upload(UploadBackgroundRequest $request): JsonResponse
    {
        $bg = $this->service->upload($request->user(), $request->file('image'));
        return (new BackgroundResource($bg))->response()->setStatusCode(201);
    }

    public function generate(GenerateBackgroundRequest $request): JsonResponse
    {
        $bg = $this->service->generate(
            $request->user(),
            (string) $request->input('prompt'),
            (string) $request->input('aspect_ratio', '16:9'),
        );
        return (new BackgroundResource($bg))->response()->setStatusCode(201);
    }

    public function select(SelectBackgroundRequest $request, Background $background): BackgroundResource
    {
        return new BackgroundResource($this->service->select($request->user(), $background));
    }

    public function destroy(Background $background): JsonResponse
    {
        $this->service->delete(request()->user(), $background);
        return response()->json(null, 204);
    }
}
```

Note: `authorizeResource` registers policy checks for `viewAny`/`view`/`update`/`delete`. `upload` and `generate` use `$this->user()->can('create', Background::class)` inside their FormRequests.

- [ ] **Step 2: Routes.** Append to `backend/routes/api.php` inside the `auth:sanctum` group:

```php
Route::middleware('auth:sanctum')->group(function () {
    // ... existing fish routes ...
    Route::get('/backgrounds', [\App\Http\Controllers\Api\V1\BackgroundController::class, 'index'])->middleware('throttle:api')->name('backgrounds.index');
    Route::post('/backgrounds/upload', [\App\Http\Controllers\Api\V1\BackgroundController::class, 'upload'])->middleware('throttle:api')->name('backgrounds.upload');
    Route::post('/backgrounds/generate', [\App\Http\Controllers\Api\V1\BackgroundController::class, 'generate'])->middleware('throttle:generate')->name('backgrounds.generate');
    Route::patch('/backgrounds/{background}/select', [\App\Http\Controllers\Api\V1\BackgroundController::class, 'select'])->middleware('throttle:api')->name('backgrounds.select')->where(['background' => '[0-9]+']);
    Route::delete('/backgrounds/{background}', [\App\Http\Controllers\Api\V1\BackgroundController::class, 'destroy'])->middleware('throttle:api')->name('backgrounds.destroy')->where(['background' => '[0-9]+']);
});
```

- [ ] **Step 3: Exception mapping.** In Laravel 13's streamlined `bootstrap/app.php`, inside `->withExceptions(function (Exceptions $exceptions) { ... })`, register:

```php
$exceptions->render(function (\App\Exceptions\Backgrounds\DimensionsTooSmallException $e) {
    return response()->json(['message' => $e->getMessage(), 'errors' => ['image' => [$e->getMessage()]]], 422);
});
$exceptions->render(function (\App\Exceptions\Backgrounds\FileTooLargeException $e) {
    return response()->json(['message' => $e->getMessage(), 'errors' => ['image' => [$e->getMessage()]]], 422);
});
$exceptions->render(function (\App\Exceptions\Backgrounds\InvalidImageException $e) {
    return response()->json(['message' => $e->getMessage(), 'errors' => ['image' => [$e->getMessage()]]], 422);
});
$exceptions->render(function (\App\Exceptions\Backgrounds\DisallowedPromptException $e) {
    return response()->json(['message' => $e->getMessage(), 'errors' => ['prompt' => [$e->getMessage()]]], 422);
});
$exceptions->render(function (\App\Exceptions\FalAi\FalAiTimeoutException $e) {
    return response()->json(['message' => $e->getMessage()], 504);
});
$exceptions->render(function (\App\Exceptions\FalAi\FalAiFailedException $e) {
    return response()->json(['message' => $e->getMessage()], 502);
});
$exceptions->render(function (\App\Exceptions\FalAi\FalAiQuotaException $e) {
    return response()->json(['message' => $e->getMessage()], 429, ['Retry-After' => '3600']);
});
$exceptions->render(function (\Illuminate\Database\QueryException $e) {
    if (str_contains($e->getMessage(), 'one_active_bg_per_user')) {
        return response()->json(['message' => 'Another select is in progress; retry.'], 409);
    }
});
```

- [ ] **Step 4: Commit.**

```bash
git add backend/app/Http/Controllers/Api/V1/BackgroundController.php backend/routes/api.php backend/bootstrap/app.php
git commit -m "$(cat <<'EOF'
feat(backend): wire BackgroundController + routes + exception→HTTP mapping

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 13: Backend — feature tests (index, upload, generate, select, delete)

**Files:**
- Create: `backend/tests/Feature/Backgrounds/IndexBackgroundsTest.php`
- Create: `backend/tests/Feature/Backgrounds/UploadBackgroundTest.php`
- Create: `backend/tests/Feature/Backgrounds/GenerateBackgroundTest.php`
- Create: `backend/tests/Feature/Backgrounds/SelectBackgroundTest.php`
- Create: `backend/tests/Feature/Backgrounds/DeleteBackgroundTest.php`

- [ ] **Step 1: `IndexBackgroundsTest`.** Cover: 401 unauthed; lists only own rows; pagination meta; ordering `is_active DESC, created_at DESC`; signed URL contains `X-Amz-Signature`; N+1 ≤ 4 queries.

```php
<?php
use App\Models\Background;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('s3');
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

it('rejects unauthed', function () {
    auth()->forgetGuards();
    $this->getJson('/api/v1/backgrounds')->assertStatus(401);
});

it('lists only own rows ordered by active+created', function () {
    Background::factory()->for($this->user)->create(['is_active' => true, 'created_at' => now()->subDay()]);
    Background::factory()->for($this->user)->create(['created_at' => now()]);
    Background::factory()->create(); // other user
    $r = $this->getJson('/api/v1/backgrounds')->assertOk();
    expect(count($r->json('data')))->toBe(2);
    expect($r->json('data.0.is_active'))->toBeTrue();
});

it('includes a signed URL with X-Amz-Signature', function () {
    $bg = Background::factory()->for($this->user)->create();
    $r = $this->getJson('/api/v1/backgrounds')->assertOk();
    expect($r->json('data.0.signed_url'))->toContain('X-Amz-Signature');
});

it('runs under the query budget', function () {
    Background::factory()->count(20)->for($this->user)->create();
    DB::enableQueryLog();
    $this->getJson('/api/v1/backgrounds')->assertOk();
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(4);
});
```

- [ ] **Step 2: `UploadBackgroundTest`.** Cover: happy 201 (fixture jpeg); 422 for small/big/text-spoof/.bmp; first upload sets is_active=true; mass-assignment ignored.

```php
<?php
use App\Models\Background;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('s3');
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

it('uploads and sets the first as active', function () {
    $file = new UploadedFile(base_path('tests/fixtures/backgrounds/valid-1280x720.jpg'), 'a.jpg', null, null, true);
    $r = $this->postJson('/api/v1/backgrounds/upload', ['image' => $file])->assertCreated();
    expect($r->json('data.is_active'))->toBeTrue();
    expect(Background::count())->toBe(1);
});

it('rejects under-size images', function () {
    $file = new UploadedFile(base_path('tests/fixtures/backgrounds/too-small-800x600.jpg'), 'a.jpg', null, null, true);
    $this->postJson('/api/v1/backgrounds/upload', ['image' => $file])->assertStatus(422)->assertJsonValidationErrors('image');
});

it('rejects text spoofed as jpeg via deep MIME sniff', function () {
    $file = new UploadedFile(base_path('tests/fixtures/backgrounds/not-an-image.jpg'), 'a.jpg', null, null, true);
    $this->postJson('/api/v1/backgrounds/upload', ['image' => $file])->assertStatus(422);
});
```

- [ ] **Step 3: `GenerateBackgroundTest`.** Cover: happy 201 with `Http::fake` returning COMPLETED on second poll; 422 prompt-too-short, prompt-too-long, denylist, invalid aspect_ratio; 429 after 11 generates in a window; 503 after the global ceiling.

```php
<?php
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.fal.api_key', 'secret');
    config()->set('services.fal.poll_interval_ms', 1);
    config()->set('services.fal.poll_max_seconds', 1);
    Storage::fake('s3');
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    Http::fake([
        'queue.fal.run/fal-ai/flux-2/turbo' => Http::response(['request_id' => 'r', 'status_url' => 'https://queue.fal.run/r', 'response_url' => 'https://queue.fal.run/r/result'], 200),
        'queue.fal.run/r' => Http::response(['status' => 'COMPLETED'], 200),
        'queue.fal.run/r/result' => Http::response(['images' => [['url' => 'https://cdn.fal.test/i.png']]], 200),
        'cdn.fal.test/i.png' => Http::response(file_get_contents(base_path('tests/fixtures/backgrounds/valid-1280x720.jpg')), 200, ['content-type' => 'image/jpeg']),
    ]);
});

it('generates a background', function () {
    $r = $this->postJson('/api/v1/backgrounds/generate', ['prompt' => 'a calm reef', 'aspect_ratio' => '16:9']);
    $r->assertCreated()->assertJsonPath('data.kind', 'generated')->assertJsonPath('data.prompt', 'a calm reef');
});

it('rejects a denylisted prompt', function () {
    $this->postJson('/api/v1/backgrounds/generate', ['prompt' => 'nsfw scene', 'aspect_ratio' => '16:9'])->assertStatus(422);
});

it('rejects a too-short prompt', function () {
    $this->postJson('/api/v1/backgrounds/generate', ['prompt' => 'no', 'aspect_ratio' => '16:9'])->assertStatus(422);
});

it('throttles after 10/hr per user', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/v1/backgrounds/generate', ['prompt' => "calm $i scene", 'aspect_ratio' => '16:9']);
    }
    $this->postJson('/api/v1/backgrounds/generate', ['prompt' => 'one too many', 'aspect_ratio' => '16:9'])->assertStatus(429);
});
```

- [ ] **Step 4: `SelectBackgroundTest`.** Cover: flips active atomically; 403 on other-user's row; simulated race surfaces 409 (manually insert a second `is_active=true` row with `DB::statement` bypassing the model and expect the constraint to throw — or assert the unique index is present and the controller maps `QueryException` → 409).

- [ ] **Step 5: `DeleteBackgroundTest`.** Cover: 204; `Queue::fake` + `assertPushed(PurgeBackgroundJob::class)` with `delay->diffInDays(now()) >= 7`; row absent from index; row in DB with `deleted_at`; 403 on other-user's row.

- [ ] **Step 6: Run all.**

```bash
docker compose exec backend ./vendor/bin/pest --filter='Backgrounds'
```

Expected: all green.

- [ ] **Step 7: Commit.**

```bash
git add backend/tests/Feature/Backgrounds/
git commit -m "$(cat <<'EOF'
test(backend): feature-cover Backgrounds index/upload/generate/select/delete (auth, validation, rate-limit, race, queue)

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 14: Backend — OpenAPI annotations + regen

**Files:**
- Modify: `backend/app/Http/Controllers/Api/V1/BackgroundController.php` (add `#[OA\…]` attributes)
- Modify: `backend/storage/api-docs/openapi.json` (regenerated)

- [ ] **Step 1: Annotate each action** with an explicit `operationId` and named-schema responses (`listBackgrounds`, `uploadBackground`, `generateBackground`, `selectBackground`, `deleteBackground`). For `upload`, use multipart annotation:

```php
#[OA\Post(
    path: '/api/v1/backgrounds/upload',
    operationId: 'uploadBackground',
    tags: ['Backgrounds'],
    security: [['sanctum' => []]],
    requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(
        mediaType: 'multipart/form-data',
        schema: new OA\Schema(ref: '#/components/schemas/UploadBackgroundRequest'),
    )),
    responses: [new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(ref: '#/components/schemas/BackgroundResourceEnvelope'))],
)]
```

Repeat for the four other operations using `BackgroundResourceEnvelope` (single) or `BackgroundCollection` (list).

- [ ] **Step 2: Regenerate.**

```bash
docker compose exec backend php artisan l5-swagger:generate
git diff --stat backend/storage/api-docs/openapi.json
```

- [ ] **Step 3: Commit.**

```bash
git add backend/app/Http/Controllers/Api/V1/BackgroundController.php backend/storage/api-docs/openapi.json
git commit -m "$(cat <<'EOF'
feat(backend): annotate Background endpoints with named OpenAPI schemas + operationIds; regen spec

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 15: Backend — Monolog scrubs (verify + extend)

**Files:**
- Modify (likely): `backend/app/Logging/ScrubProcessor.php` (slice 2 introduced this; verify it strips `FAL_API_KEY` + `api_key` + `Authorization` + `password*`).

- [ ] **Step 1: Read and confirm.**

```bash
grep -RIn 'FAL_API_KEY\|scrub\|Authorization' backend/app/Logging backend/config/logging.php
```

- [ ] **Step 2: If missing, append the keys to the processor's deny list.** Add a unit test asserting the processor drops `FAL_API_KEY=secret-XXXX` from a Monolog record's context array.

- [ ] **Step 3: Commit (if any changes).**

```bash
git add backend/app/Logging/ backend/config/logging.php backend/tests/Unit/Logging/
git commit -m "$(cat <<'EOF'
chore(backend): ensure Monolog ScrubProcessor strips FAL_API_KEY + api_key + Bearer/Key headers

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 16: Frontend — install deps + regen API client

**Files:**
- Modify: `frontend/package.json`, `frontend/package-lock.json`
- Modify: `frontend/src/lib/api-client/` (regenerated)

- [ ] **Step 1: Install.**

```bash
cd frontend && npm install react-dropzone @radix-ui/react-tabs
```

- [ ] **Step 2: Regenerate.**

```bash
cd frontend && npm run generate:api
```

Expected: `src/lib/api-client/apis/BackgroundsApi.ts` (or similar) with `listBackgrounds`, `generateBackground`, `selectBackground`, `deleteBackground` (upload is multipart and may or may not be generated cleanly; we'll bypass it for the hand-rolled upload mutation regardless).

- [ ] **Step 3: Commit.**

```bash
git add frontend/package.json frontend/package-lock.json frontend/src/lib/api-client/
git commit -m "$(cat <<'EOF'
chore(frontend): add react-dropzone + @radix-ui/react-tabs; regen api client with BackgroundsApi

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 17: Frontend — zod schemas + API + hooks

**Files:**
- Create: `frontend/src/lib/backgrounds/schemas.ts`
- Create: `frontend/src/lib/backgrounds/api.ts`
- Create: `frontend/src/hooks/use-background-queries.ts`

- [ ] **Step 1: Schemas.**

```ts
import { z } from 'zod';

export const aspectRatios = ['16:9', '3:2', '1:1'] as const;

export const generateBackgroundSchema = z.object({
  prompt: z.string().trim().min(3, 'At least 3 characters').max(500, 'At most 500 characters'),
  aspect_ratio: z.enum(aspectRatios).default('16:9'),
});

export type GenerateBackgroundInput = z.infer<typeof generateBackgroundSchema>;

export const UPLOAD_MIN_WIDTH = 1280;
export const UPLOAD_MIN_HEIGHT = 720;
export const UPLOAD_MAX_BYTES = 5 * 1024 * 1024;
export const UPLOAD_ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'] as const;
```

- [ ] **Step 2: API wrapper.**

```ts
import { Configuration, BackgroundsApi } from '@/lib/api-client';

const config = new Configuration({ basePath: '/api/proxy' });
export const backgroundsApi = new BackgroundsApi(config);

export async function uploadBackground(file: File): Promise<any> {
  const fd = new FormData();
  fd.append('image', file);
  const r = await fetch('/api/proxy/backgrounds/upload', { method: 'POST', body: fd });
  if (!r.ok) throw new Error((await r.json()).message ?? `Upload failed (${r.status})`);
  return r.json();
}
```

- [ ] **Step 3: Hooks.**

```ts
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { backgroundsApi, uploadBackground } from '@/lib/backgrounds/api';
import type { GenerateBackgroundInput } from '@/lib/backgrounds/schemas';

export function useBackgroundsQuery() {
  return useQuery({
    queryKey: ['backgrounds', 'list'],
    queryFn: () => backgroundsApi.listBackgrounds(),
    staleTime: 5 * 60_000,
    refetchOnWindowFocus: false,
  });
}

export function useActiveBackgroundQuery() {
  const { data } = useBackgroundsQuery();
  return data?.data?.find((b: any) => b.is_active) ?? null;
}

export function useUploadBackgroundMutation() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (file: File) => uploadBackground(file),
    onSettled: () => qc.invalidateQueries({ queryKey: ['backgrounds', 'list'] }),
  });
}

export function useGenerateBackgroundMutation() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: GenerateBackgroundInput) =>
      backgroundsApi.generateBackground({ generateBackgroundRequest: input }),
    onSettled: () => qc.invalidateQueries({ queryKey: ['backgrounds', 'list'] }),
  });
}

export function useSelectBackgroundMutation() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => backgroundsApi.selectBackground({ background: id }),
    onMutate: async (id) => {
      await qc.cancelQueries({ queryKey: ['backgrounds', 'list'] });
      const snap = qc.getQueriesData({ queryKey: ['backgrounds', 'list'] });
      for (const [k, v] of snap) {
        if (v && typeof v === 'object' && 'data' in (v as any)) {
          const next = { ...(v as any), data: (v as any).data.map((b: any) => ({ ...b, is_active: b.id === id })) };
          qc.setQueryData(k, next);
        }
      }
      return { snap };
    },
    onError: (_e, _id, ctx) => ctx?.snap.forEach(([k, v]) => qc.setQueryData(k, v)),
    onSettled: () => qc.invalidateQueries({ queryKey: ['backgrounds', 'list'] }),
  });
}

export function useDeleteBackgroundMutation() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => backgroundsApi.deleteBackground({ background: id }),
    onMutate: async (id) => {
      await qc.cancelQueries({ queryKey: ['backgrounds', 'list'] });
      const snap = qc.getQueriesData({ queryKey: ['backgrounds', 'list'] });
      for (const [k, v] of snap) {
        if (v && typeof v === 'object' && 'data' in (v as any)) {
          qc.setQueryData(k, { ...(v as any), data: (v as any).data.filter((b: any) => b.id !== id) });
        }
      }
      return { snap };
    },
    onError: (_e, _id, ctx) => ctx?.snap.forEach(([k, v]) => qc.setQueryData(k, v)),
    onSettled: () => qc.invalidateQueries({ queryKey: ['backgrounds', 'list'] }),
  });
}
```

- [ ] **Step 4: Commit.**

```bash
git add frontend/src/lib/backgrounds/ frontend/src/hooks/use-background-queries.ts
git commit -m "$(cat <<'EOF'
feat(frontend): add background zod schemas + API wrapper + TanStack Query hooks (incl. multipart upload)

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 18: Frontend — `BackgroundLayer` (TDD)

**Files:**
- Create: `frontend/tests/unit/components/aquarium/BackgroundLayer.test.tsx`
- Create: `frontend/src/components/aquarium/BackgroundLayer.tsx`

- [ ] **Step 1: Failing test.**

```tsx
import { describe, it, expect, vi } from 'vitest';
import { render } from '@testing-library/react';
import { BackgroundLayer } from '@/components/aquarium/BackgroundLayer';

vi.mock('@/hooks/use-background-queries', () => ({
  useActiveBackgroundQuery: vi.fn(),
}));
import { useActiveBackgroundQuery } from '@/hooks/use-background-queries';

describe('BackgroundLayer', () => {
  it('renders the signed URL when an active background exists', () => {
    (useActiveBackgroundQuery as any).mockReturnValue({ id: '1', signed_url: 'https://s3/abc?X-Amz-Signature=zzz', width: 1920, height: 1080 });
    const { container } = render(<BackgroundLayer />);
    expect(container.querySelector('img')?.getAttribute('src')).toContain('X-Amz-Signature');
  });
  it('renders fallback gradient when none', () => {
    (useActiveBackgroundQuery as any).mockReturnValue(null);
    const { container } = render(<BackgroundLayer />);
    expect(container.querySelector('img')).toBeNull();
    expect(container.querySelector('[data-testid="bg-fallback"]')).not.toBeNull();
  });
});
```

- [ ] **Step 2: Implement.**

```tsx
'use client';

import { useActiveBackgroundQuery } from '@/hooks/use-background-queries';
import { useEffect, useState } from 'react';

export function BackgroundLayer() {
  const active = useActiveBackgroundQuery();
  const [loaded, setLoaded] = useState(false);
  const [reduced, setReduced] = useState(false);

  useEffect(() => {
    const mq = window.matchMedia('(prefers-reduced-motion: reduce)');
    setReduced(mq.matches);
    const onChange = () => setReduced(mq.matches);
    mq.addEventListener('change', onChange);
    return () => mq.removeEventListener('change', onChange);
  }, []);

  if (!active) {
    return (
      <div
        data-testid="bg-fallback"
        className="fixed inset-0 -z-10 pointer-events-none bg-[radial-gradient(circle_at_30%_20%,_var(--primary-container)_0%,_var(--surface)_60%)]"
        aria-hidden="true"
      />
    );
  }

  return (
    <>
      <img
        src={active.signed_url}
        alt=""
        role="presentation"
        onLoad={() => setLoaded(true)}
        className={`fixed inset-0 -z-10 pointer-events-none w-screen h-screen object-cover ${reduced ? '' : 'transition-opacity duration-700'} ${loaded || reduced ? 'opacity-100' : 'opacity-0'}`}
      />
      <div className="fixed inset-0 -z-10 pointer-events-none bg-white/20 backdrop-blur-[4px]" aria-hidden="true" />
    </>
  );
}
```

- [ ] **Step 3: Run + commit.**

```bash
cd frontend && npm test -- --run BackgroundLayer
git add frontend/src/components/aquarium/BackgroundLayer.tsx frontend/tests/unit/components/aquarium/BackgroundLayer.test.tsx
git commit -m "$(cat <<'EOF'
feat(frontend): add BackgroundLayer — signed-URL img with object-cover, gradient fallback, reduced-motion aware

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 19: Frontend — `BackgroundUploadTab` (TDD)

**Files:**
- Create: `frontend/tests/unit/components/manage/BackgroundUploadTab.test.tsx`
- Create: `frontend/src/components/manage/BackgroundUploadTab.tsx`

- [ ] **Step 1: Failing test** (mock `react-dropzone` `useDropzone` to immediately call `onDrop`; provide an in-memory `File` of `image/jpeg` with known dimensions via a `createImageBitmap` stub).

- [ ] **Step 2: Implement.**

```tsx
'use client';

import { useState } from 'react';
import { useDropzone } from 'react-dropzone';
import {
  UPLOAD_ALLOWED_MIME, UPLOAD_MAX_BYTES, UPLOAD_MIN_HEIGHT, UPLOAD_MIN_WIDTH,
} from '@/lib/backgrounds/schemas';
import { useUploadBackgroundMutation } from '@/hooks/use-background-queries';

export function BackgroundUploadTab() {
  const [error, setError] = useState<string | null>(null);
  const upload = useUploadBackgroundMutation();

  const onDrop = async (files: File[]) => {
    setError(null);
    const file = files[0];
    if (!file) return;
    if (!UPLOAD_ALLOWED_MIME.includes(file.type as any)) return setError('JPG, PNG, or WebP only.');
    if (file.size > UPLOAD_MAX_BYTES) return setError('File exceeds 5 MB.');
    try {
      const bmp = await createImageBitmap(file);
      if (bmp.width < UPLOAD_MIN_WIDTH || bmp.height < UPLOAD_MIN_HEIGHT) {
        return setError(`Need at least ${UPLOAD_MIN_WIDTH}×${UPLOAD_MIN_HEIGHT}; got ${bmp.width}×${bmp.height}.`);
      }
    } catch {
      return setError('Could not read image.');
    }
    upload.mutate(file);
  };

  const { getRootProps, getInputProps, isDragActive } = useDropzone({
    onDrop, maxFiles: 1, multiple: false,
    accept: { 'image/jpeg': [], 'image/png': [], 'image/webp': [] },
  });

  return (
    <div className="space-y-3">
      <div {...getRootProps()} className={`rounded-xl border-2 border-dashed p-10 text-center cursor-pointer transition-colors ${isDragActive ? 'border-primary bg-primary/10' : 'border-outline-variant bg-white/10'}`}>
        <input {...getInputProps()} />
        <p className="font-headline-md text-headline-md">Drop an image here</p>
        <p className="text-on-surface-variant mt-2">JPG, PNG, or WebP · at least 1280×720 · max 5 MB</p>
      </div>
      {error && <p role="alert" className="text-error">{error}</p>}
      {upload.isPending && <p className="text-on-surface-variant">Uploading…</p>}
      {upload.isError && <p role="alert" className="text-error">{(upload.error as Error)?.message}</p>}
    </div>
  );
}
```

- [ ] **Step 3: Run + commit.**

```bash
cd frontend && npm test -- --run BackgroundUploadTab
git add frontend/src/components/manage/BackgroundUploadTab.tsx frontend/tests/unit/components/manage/BackgroundUploadTab.test.tsx
git commit -m "$(cat <<'EOF'
feat(frontend): add BackgroundUploadTab (react-dropzone + client-side MIME/dim/size validation)

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 20: Frontend — `BackgroundGenerateTab` (TDD)

**Files:**
- Create: `frontend/tests/unit/components/manage/BackgroundGenerateTab.test.tsx`
- Create: `frontend/src/components/manage/BackgroundGenerateTab.tsx`

- [ ] **Step 1: Failing test.** Cover: zod errors for short/long prompts; submitting valid input calls `useGenerateBackgroundMutation` once; while pending, the spinner with "Painting your aquarium…" copy is visible.

- [ ] **Step 2: Implement.**

```tsx
'use client';

import { useForm, Controller } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { generateBackgroundSchema, type GenerateBackgroundInput, aspectRatios } from '@/lib/backgrounds/schemas';
import { useGenerateBackgroundMutation } from '@/hooks/use-background-queries';

const QUICK_PICKS = [
  'a calm coral reef at dusk, soft caustic light',
  'a kelp forest with sunbeams, photographic',
  'a deep-sea trench, bioluminescent particles',
];

export function BackgroundGenerateTab() {
  const generate = useGenerateBackgroundMutation();
  const { register, handleSubmit, setValue, control, formState: { errors } } = useForm<GenerateBackgroundInput>({
    resolver: zodResolver(generateBackgroundSchema),
    defaultValues: { prompt: '', aspect_ratio: '16:9' },
  });

  const onSubmit = (input: GenerateBackgroundInput) => generate.mutate(input);

  return (
    <div className="space-y-4">
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
        <label className="block">
          <span className="font-label-caps text-[12px] tracking-[0.1em] uppercase text-on-surface-variant">Prompt</span>
          <textarea {...register('prompt')} rows={3}
            className="block w-full mt-1 bg-white/20 border-0 border-b border-outline-variant py-2 px-3 rounded-t-lg outline-none focus:bg-white/40 focus:border-primary" />
          {errors.prompt && <p role="alert" className="text-error text-sm mt-1">{errors.prompt.message}</p>}
        </label>
        <div className="flex flex-wrap gap-2">
          {QUICK_PICKS.map((q) => (
            <button key={q} type="button" onClick={() => setValue('prompt', q)}
              className="px-3 py-1 rounded-full bg-white/20 text-xs">{q.slice(0, 30)}…</button>
          ))}
        </div>
        <Controller control={control} name="aspect_ratio" render={({ field }) => (
          <div className="flex gap-2">
            {aspectRatios.map((r) => (
              <button key={r} type="button" onClick={() => field.onChange(r)}
                className={`px-3 py-1 rounded-full border ${field.value === r ? 'bg-primary/30 border-white/40' : 'bg-white/20 border-white/20'}`}>{r}</button>
            ))}
          </div>
        )} />
        <button type="submit" disabled={generate.isPending}
          className="px-6 py-2 rounded-full bg-primary/30 backdrop-blur-md border border-white/40 text-on-primary-container font-label-caps text-[12px] tracking-[0.1em] uppercase">
          Generate
        </button>
      </form>
      {generate.isPending && (
        <div role="status" aria-live="polite" className="p-6 rounded-xl bg-white/30 backdrop-blur-md border border-white/20 text-center">
          <p className="font-headline-md text-headline-md">Painting your aquarium…</p>
          <p className="text-on-surface-variant mt-2">This can take up to a minute.</p>
        </div>
      )}
      {generate.isError && <p role="alert" className="text-error">{(generate.error as Error)?.message}</p>}
    </div>
  );
}
```

- [ ] **Step 3: Run + commit.**

```bash
cd frontend && npm test -- --run BackgroundGenerateTab
git add frontend/src/components/manage/BackgroundGenerateTab.tsx frontend/tests/unit/components/manage/BackgroundGenerateTab.test.tsx
git commit -m "$(cat <<'EOF'
feat(frontend): add BackgroundGenerateTab (rhf+zod, quick-picks, aspect ratio, polling spinner)

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 21: Frontend — `BackgroundLibraryTab` (TDD)

**Files:**
- Create: `frontend/tests/unit/components/manage/BackgroundLibraryTab.test.tsx`
- Create: `frontend/src/components/manage/BackgroundLibraryTab.tsx`

- [ ] **Step 1: Failing test** — render with two backgrounds in the cache (one active); click a card → optimistic select mutation called; click delete → optimistic delete mutation called.

- [ ] **Step 2: Implement.**

```tsx
'use client';

import { useBackgroundsQuery, useDeleteBackgroundMutation, useSelectBackgroundMutation } from '@/hooks/use-background-queries';

export function BackgroundLibraryTab() {
  const { data, isLoading } = useBackgroundsQuery();
  const select = useSelectBackgroundMutation();
  const del = useDeleteBackgroundMutation();

  if (isLoading) return <p className="text-on-surface-variant">Loading…</p>;
  const rows = data?.data ?? [];
  if (rows.length === 0) return <p className="text-on-surface-variant">No backgrounds yet — upload or generate one.</p>;

  return (
    <ul className="grid grid-cols-2 md:grid-cols-3 gap-3">
      {rows.map((b: any) => (
        <li key={b.id} className="group relative rounded-xl overflow-hidden border border-white/20">
          <button type="button" onClick={() => select.mutate(b.id)} className="block w-full aspect-video">
            <img src={b.signed_url} alt="" loading="lazy" className="w-full h-full object-cover" />
          </button>
          {b.is_active && <span className="absolute top-2 left-2 px-2 py-0.5 rounded-full bg-primary/40 backdrop-blur-md text-[11px] uppercase tracking-[0.1em]">Active</span>}
          <button type="button" onClick={() => del.mutate(b.id)}
            className="absolute bottom-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity px-2 py-1 rounded-full bg-error/30 text-[11px] uppercase">
            Delete
          </button>
        </li>
      ))}
    </ul>
  );
}
```

- [ ] **Step 3: Run + commit.**

```bash
cd frontend && npm test -- --run BackgroundLibraryTab
git add frontend/src/components/manage/BackgroundLibraryTab.tsx frontend/tests/unit/components/manage/BackgroundLibraryTab.test.tsx
git commit -m "$(cat <<'EOF'
feat(frontend): add BackgroundLibraryTab (grid, active chip, optimistic select+delete)

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 22: Frontend — `BackgroundPanel` + `/fish` dock button

**Files:**
- Create: `frontend/src/components/manage/BackgroundPanel.tsx`
- Modify: `frontend/src/app/fish/_client.tsx`

- [ ] **Step 1: Implement panel.**

```tsx
'use client';

import * as Dialog from '@radix-ui/react-dialog';
import * as Tabs from '@radix-ui/react-tabs';
import { BackgroundUploadTab } from './BackgroundUploadTab';
import { BackgroundGenerateTab } from './BackgroundGenerateTab';
import { BackgroundLibraryTab } from './BackgroundLibraryTab';

export function BackgroundPanel({ open, onOpenChange }: { open: boolean; onOpenChange: (b: boolean) => void }) {
  return (
    <Dialog.Root open={open} onOpenChange={onOpenChange}>
      <Dialog.Portal>
        <Dialog.Overlay className="fixed inset-0 bg-black/30 backdrop-blur-sm z-40" />
        <Dialog.Content className="fixed left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 z-40 w-[min(95vw,768px)] max-h-[80vh] overflow-y-auto p-8 rounded-xl bg-white/50 backdrop-blur-xl border border-white/20">
          <Dialog.Title className="text-headline-md font-headline-md mb-4">Background</Dialog.Title>
          <Tabs.Root defaultValue="library">
            <Tabs.List className="flex gap-2 mb-4 font-label-caps text-[12px] tracking-[0.1em] uppercase">
              <Tabs.Trigger value="upload"   className="px-3 py-1 rounded-full border border-white/20 data-[state=active]:bg-primary/30 data-[state=active]:border-white/40">Upload</Tabs.Trigger>
              <Tabs.Trigger value="generate" className="px-3 py-1 rounded-full border border-white/20 data-[state=active]:bg-primary/30 data-[state=active]:border-white/40">Generate</Tabs.Trigger>
              <Tabs.Trigger value="library"  className="px-3 py-1 rounded-full border border-white/20 data-[state=active]:bg-primary/30 data-[state=active]:border-white/40">Library</Tabs.Trigger>
            </Tabs.List>
            <Tabs.Content value="upload"><BackgroundUploadTab /></Tabs.Content>
            <Tabs.Content value="generate"><BackgroundGenerateTab /></Tabs.Content>
            <Tabs.Content value="library"><BackgroundLibraryTab /></Tabs.Content>
          </Tabs.Root>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
```

- [ ] **Step 2: Wire `/fish/_client.tsx`** — add a `BackgroundLayer`, a `[backgroundOpen, setBackgroundOpen]` state, a Backgrounds dock button next to "Manage", and `<BackgroundPanel open={backgroundOpen} onOpenChange={setBackgroundOpen} />`.

```tsx
'use client';

import { useState } from 'react';
import { AquariumCanvas } from '@/components/aquarium/AquariumCanvas';
import { BackgroundLayer } from '@/components/aquarium/BackgroundLayer';
import { HoverTooltip } from '@/components/aquarium/HoverTooltip';
import { FishManagerModal } from '@/components/manage/FishManagerModal';
import { AddFishDialog } from '@/components/manage/AddFishDialog';
import { BackgroundPanel } from '@/components/manage/BackgroundPanel';
import { useBreedsQuery, useFishesQuery } from '@/hooks/use-fish-queries';

export function FishPageClient({ initialEmpty }: { initialEmpty: boolean }) {
  const [manageOpen, setManageOpen] = useState(false);
  const [addOpen, setAddOpen] = useState(initialEmpty);
  const [backgroundOpen, setBackgroundOpen] = useState(false);
  const { data: fishes } = useFishesQuery();
  const { data: breeds } = useBreedsQuery();

  return (
    <>
      <BackgroundLayer />
      <AquariumCanvas fishes={fishes?.data ?? []} breeds={breeds?.data ?? []} />
      <HoverTooltip />
      <div className="fixed bottom-6 right-6 flex gap-2 z-10">
        <button onClick={() => setAddOpen(true)}        className="px-6 py-3 rounded-full bg-white/20 border border-white/20 font-label-caps text-[12px] tracking-[0.1em] uppercase">Add fish</button>
        <button onClick={() => setManageOpen(true)}      className="px-6 py-3 rounded-full bg-white/20 border border-white/20 font-label-caps text-[12px] tracking-[0.1em] uppercase">Manage</button>
        <button onClick={() => setBackgroundOpen(true)}  className="px-6 py-3 rounded-full bg-primary/30 border border-white/40 text-on-primary-container font-label-caps text-[12px] tracking-[0.1em] uppercase">Background</button>
      </div>
      <FishManagerModal open={manageOpen} onOpenChange={setManageOpen} />
      <AddFishDialog open={addOpen} onOpenChange={setAddOpen} />
      <BackgroundPanel open={backgroundOpen} onOpenChange={setBackgroundOpen} />
    </>
  );
}
```

- [ ] **Step 3: Commit.**

```bash
git add frontend/src/components/manage/BackgroundPanel.tsx frontend/src/app/fish/_client.tsx
git commit -m "$(cat <<'EOF'
feat(frontend): wire BackgroundPanel (Radix Tabs) + Background dock button on /fish + BackgroundLayer

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 23: Frontend — proxy multipart pass-through test

**Files:**
- Create: `frontend/tests/unit/app/api/proxy-multipart.test.ts`

- [ ] **Step 1: Test.** Mount the slice 2 proxy module, build a `Request` with a `FormData` body and a `Content-Type: multipart/form-data; boundary=…` header, stub `fetch` upstream, assert the upstream call received the same `Content-Type` and a body of the same byte length.

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest';

describe('proxy multipart pass-through', () => {
  beforeEach(() => vi.resetModules());
  it('preserves Content-Type and body bytes', async () => {
    vi.mock('next/headers', () => ({ cookies: async () => ({ get: () => undefined, set: () => {}, delete: () => {} }) }));
    vi.mock('iron-session', () => ({ getIronSession: async () => ({ token: 'tok' }) }));
    const fd = new FormData(); fd.append('image', new File(['hello'], 'a.jpg', { type: 'image/jpeg' }));
    const req = new Request('http://localhost:3000/api/proxy/backgrounds/upload', {
      method: 'POST', body: fd as any,
    });
    const ct = req.headers.get('content-type') ?? '';
    expect(ct).toContain('multipart/form-data');

    const upstream = vi.fn().mockResolvedValue(new Response('{}', { status: 201, headers: { 'content-type': 'application/json' } }));
    vi.stubGlobal('fetch', upstream);

    const mod: any = await import('@/app/api/proxy/[...path]/route');
    await mod.POST(req, { params: Promise.resolve({ path: ['backgrounds', 'upload'] }) });

    const [, init] = upstream.mock.calls[0];
    expect(init.headers['content-type']).toContain('multipart/form-data');
    expect((init.body as ArrayBuffer).byteLength).toBeGreaterThan(0);
  });
});
```

- [ ] **Step 2: If the test fails** (proxy is dropping headers or body), fix the proxy: prefer `req.body` (stream) over `req.arrayBuffer()` when the method is non-idempotent. Commit the fix as a separate `fix(frontend):` commit.

- [ ] **Step 3: Commit.**

```bash
cd frontend && npm test -- --run proxy-multipart
git add frontend/tests/unit/app/api/proxy-multipart.test.ts
git commit -m "$(cat <<'EOF'
test(frontend): verify /api/proxy/[...path] forwards multipart Content-Type + body unchanged

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 24: Frontend — regen client + lint/type/build

**Files:** none new.

- [ ] **Step 1: Final regen against the now-complete backend spec.**

```bash
docker compose exec backend php artisan l5-swagger:generate
cd frontend && npm run generate:api
git status --short
```

Expected: only whitespace / no diffs (if regen produces churn, commit it as `chore(frontend): regen api client`).

- [ ] **Step 2: Lint, type, build.**

```bash
cd frontend && npm run lint && npm run typecheck && npm run build
```

- [ ] **Step 3: Commit (if regen produced diffs).**

```bash
git add backend/storage/api-docs/openapi.json frontend/src/lib/api-client/
git commit -m "$(cat <<'EOF'
chore: final OpenAPI spec + client regen for Slice 4

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 25: Acceptance verification

- [ ] **Step 1: Stack up + migrate.**

```bash
docker compose up -d db redis minio minio-init backend frontend
sleep 12
docker compose exec backend php artisan migrate --force
```

- [ ] **Step 2: Backend test suite + coverage + lint.**

```bash
docker compose exec backend ./vendor/bin/pest --coverage --min=80
docker compose exec backend ./vendor/bin/phpstan analyse --memory-limit=512M
docker compose exec backend ./vendor/bin/pint --test
```

- [ ] **Step 3: Live MinIO smoke (curl).**

```bash
TOKEN=$(curl -s -X POST http://localhost:8000/api/v1/auth/register \
  -H 'content-type: application/json' \
  -d '{"username":"slice4","email":"s4@a.co","password":"a-strong-pass-123!","password_confirmation":"a-strong-pass-123!"}' \
  | python3 -c 'import json,sys;print(json.load(sys.stdin)["token"])')

# Upload (live: hits MinIO, not faked).
curl -s -X POST http://localhost:8000/api/v1/backgrounds/upload \
  -H "Authorization: Bearer $TOKEN" \
  -F "image=@backend/tests/fixtures/backgrounds/valid-1280x720.jpg" \
  | python3 -c 'import json,sys; d=json.load(sys.stdin)["data"]; print(d["kind"], d["is_active"], "X-Amz-Signature" in d["signed_url"])'
# Expect: upload True True

# List.
curl -s http://localhost:8000/api/v1/backgrounds -H "Authorization: Bearer $TOKEN" \
  | python3 -c 'import json,sys; d=json.load(sys.stdin)["data"]; print(len(d))'
# Expect: 1

# Soft-delete.
BG=$(curl -s http://localhost:8000/api/v1/backgrounds -H "Authorization: Bearer $TOKEN" | python3 -c 'import json,sys;print(json.load(sys.stdin)["data"][0]["id"])')
curl -s -o /dev/null -w "%{http_code}\n" -X DELETE "http://localhost:8000/api/v1/backgrounds/$BG" -H "Authorization: Bearer $TOKEN"
# Expect: 204
docker compose exec db psql -U fishbook -d fishbook -c "select count(*) from backgrounds where deleted_at is not null;"
# Expect: 1
```

- [ ] **Step 4: Frontend tests + build.**

```bash
cd frontend && npm test -- --run --coverage
cd frontend && npm run lint && npm run typecheck && npm run build
```

Expected: ≥ 70% statements; new files ≥ 80%.

- [ ] **Step 5: Regen-no-diff.**

```bash
docker compose exec backend php artisan l5-swagger:generate
git diff --exit-code backend/storage/api-docs/openapi.json
cd frontend && npm run generate:api
git -C .. diff --exit-code frontend/src/lib/api-client
```

- [ ] **Step 6: Manual smoke (browser).** Open `http://localhost:3000/login`, register/login, go to `/fish`, click "Background" dock button → Upload tab → drop a 1280×720 image → see the canvas re-paint over the new background. Open the panel again → Generate tab → enter a calm prompt → wait up to 60 s → see the new background swap in (requires `FAL_API_KEY` set in `backend/.env` if you want a real generation; otherwise the spinner will time out at 60 s with a clear error — that's the expected path without a key).

- [ ] **Step 7: Tear down + tag.**

```bash
docker compose down
git tag -a slice-4-backgrounds -m "Slice 4 — Backgrounds (upload + Fal AI generate + select) complete"
git log --oneline -30
```

- [ ] **Step 8: No commit needed** (acceptance only). Any small fixes during verification land as their own `fix(...)` / `test(...)` commits **before** the tag.

---

## What's intentionally NOT here

These appear in later slices, per the SPEC and the slice 4 design's §2 "Out" list:

- **Curated preset backgrounds** (admin-shipped library with `kind=preset`). The column accepts the value; no admin UI ships. → **Slice 5+** or polish.
- **Real-time generation progress events** (SSE/websockets). The spinner UI is good enough at the 60-s budget. → **Slice 7** polish.
- **Richer LLM moderation** (OpenAI moderation, AWS Comprehend, an LLM-as-judge). The local denylist is the baseline. → **Slice 7**.
- **Crossfade between background swaps.** The `<img>` simply swaps. → **Slice 7** polish.
- **Mobile-specific upload UX** (camera capture, HEIC support, share-sheet). → polish.
- **CDN image transforms** (Cloudflare Images, Bunny). Signed S3 URLs are sufficient. → defer.
- **`/fish/settings` page.** Modal covers the functionality; standalone page is SPEC §1 "deep-link convenience." → polish.
- **CSP allowlist update for the S3 host.** → **Slice 7** when all asset sources are known.
- **Auto-promote on delete-active.** Deleting the active background leaves the user with the gradient fallback; auto-promote → polish.
- **Backend cancel endpoint for in-flight Fal AI generations.** Current cancel button dismisses the spinner client-side only. → polish.
- **GitHub-repo aquarium** (`/[username]/[repo]`, `RepoAquariumGenerator`, "Fork to My Aquarium") → **Slice 5**.

If a later task feels like it belongs in slice 4, push back — slice 4 is the background surface (upload + generate + select + delete + signed URLs + retention job), not the full Background experience polish.
