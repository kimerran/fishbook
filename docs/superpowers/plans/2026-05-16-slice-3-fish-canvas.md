# Slice 3 — Fish CRUD + Aquarium Canvas Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` (or `superpowers:subagent-driven-development` for parallelizable chunks) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking; do **not** mark a checkbox until the step's expected output is observed.

**Goal:** Ship the full authed `/fish` experience. After this slice, a user can create/edit/delete fish via a Radix dialog, see them swim on a full-viewport 60 fps canvas, hover for nickname tooltips, and click to drop food. SPEC §17 acceptance items **2, 3, 4, 5** are satisfied.

**Architecture:** Backend adds the `fishes` table, the immutable breed catalog (`config/fish_breeds.php`), an owner-scoped `FishController` with `apiResource` + `authorizeResource`, a public `fishes/breeds` endpoint, and a `BreedCatalog` service. Frontend adds a canvas-internal `Fish` class with seeded-PRNG steering, a `FoodPellet` class, a zustand `useAquariumStore` (client-only state), a single-RAF `AquariumCanvas` (refs only, no React state in the loop), a `HoverTooltip` React island, three Radix dialogs (`FishManagerModal`, `AddFishDialog`, `EditFishDialog`), and TanStack Query hooks routed through the slice-2 proxy.

**Tech Stack:** Laravel 13 + PHP 8.3 + Pest + Larastan; no new backend deps. Next.js 16 + React 19 + TS strict; new deps `@radix-ui/react-dialog` and `clsx`.

**Spec:** [`docs/superpowers/specs/2026-05-16-slice-3-fish-canvas-design.md`](../specs/2026-05-16-slice-3-fish-canvas-design.md).

---

## Conventions

- Today's date for commit messages is **2026-05-16**.
- All commands run from repo root unless stated otherwise; backend commands use `docker compose exec backend …` so citext + jsonb work.
- Conventional Commits (`feat:`, `fix:`, `chore:`, `test:`, `docs:`, `refactor:`, `ci:`).
- One task = one commit. Don't squash.
- Commit trailer:
  ```
  Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
  ```
  Use heredoc form (see slice 2 plan §Conventions).
- TDD for every endpoint and form: failing test first, then code, then green.
- Build on `main` after slice 2's `slice-2-auth` tag.
- Frontend tests run with `cd frontend && npm test -- --run`; backend tests run with `docker compose exec backend ./vendor/bin/pest`.

---

## Task 1: Slice prep — verify slice 2 baseline

**Files:**
- (none — read-only verification)

- [ ] **Step 1: Confirm clean tree on `main` at slice 2 tag.**

```bash
git status
git describe --tags --abbrev=0
```

Expected: working tree clean; tag prints `slice-2-auth`.

- [ ] **Step 2: Confirm slice 2 fixtures exist.**

```bash
test -f backend/app/Policies/FishPolicy.php && echo OK-policy
test -f frontend/src/app/api/proxy/\[...path\]/route.ts && echo OK-proxy
docker compose up -d db redis
sleep 5
docker compose exec backend php artisan migrate --pretend | head -20
```

Expected: both `OK-` lines print; migrate-pretend shows the slice 2 users + sessions migrations as already applied. The `FishPolicy` stub from slice 2 will be filled in during Task 6.

- [ ] **Step 3: No commit.** Verification only.

---

## Task 2: Backend — `config/fish_breeds.php` (the catalog)

**Files:**
- Create: `backend/config/fish_breeds.php`

- [ ] **Step 1: Create the file.**

```php
<?php

/**
 * Static catalog of fish breeds. See SPEC §4.
 * Served (public) via GET /api/v1/fishes/breeds.
 * Used (server-side) by BreedCatalog for size validation.
 *
 * vertical_band_preference: 'bottom' constrains target.y > viewport.h * 0.6 client-side.
 */
return [
    ['id' => 'guppy',              'label' => 'Guppy',                'min_size' => 8,  'max_size' => 18, 'default_color' => '#FF6B9D', 'sprite_key' => 'guppy'],
    ['id' => 'molly',              'label' => 'Molly',                'min_size' => 12, 'max_size' => 22, 'default_color' => '#1F2937', 'sprite_key' => 'molly'],
    ['id' => 'neon_tetra',         'label' => 'Neon Tetra',           'min_size' => 6,  'max_size' => 12, 'default_color' => '#3B82F6', 'sprite_key' => 'neon_tetra'],
    ['id' => 'zebra_danio',        'label' => 'Zebra Danio',          'min_size' => 8,  'max_size' => 14, 'default_color' => '#9CA3AF', 'sprite_key' => 'zebra_danio'],
    ['id' => 'platy',              'label' => 'Platy',                'min_size' => 10, 'max_size' => 18, 'default_color' => '#F59E0B', 'sprite_key' => 'platy'],
    ['id' => 'endler',             'label' => "Endler's Livebearer",  'min_size' => 5,  'max_size' => 10, 'default_color' => '#10B981', 'sprite_key' => 'endler'],
    ['id' => 'cherry_barb',        'label' => 'Cherry Barb',          'min_size' => 10, 'max_size' => 16, 'default_color' => '#DC2626', 'sprite_key' => 'cherry_barb'],
    ['id' => 'white_cloud_minnow', 'label' => 'White Cloud Minnow',   'min_size' => 7,  'max_size' => 13, 'default_color' => '#E5E7EB', 'sprite_key' => 'white_cloud_minnow'],
    ['id' => 'otocinclus',         'label' => 'Otocinclus',           'min_size' => 6,  'max_size' => 10, 'default_color' => '#6B7280', 'sprite_key' => 'otocinclus',   'vertical_band_preference' => 'bottom'],
    ['id' => 'cory_catfish',       'label' => 'Cory Catfish',         'min_size' => 12, 'max_size' => 20, 'default_color' => '#78716C', 'sprite_key' => 'cory_catfish', 'vertical_band_preference' => 'bottom'],
];
```

- [ ] **Step 2: Sanity check.**

```bash
docker compose exec backend php -r "var_dump(count(require 'config/fish_breeds.php'));"
```

Expected: `int(10)`.

- [ ] **Step 3: Commit.**

```bash
git add backend/config/fish_breeds.php
git commit -m "$(cat <<'EOF'
feat(backend): add fish_breeds catalog (SPEC §4) — 10 breeds with size ranges + bottom-dweller flag

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Backend — `fishes` migration

**Files:**
- Create: `backend/database/migrations/2026_05_16_000000_create_fishes_table.php`

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
        Schema::create('fishes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('nickname', 40);
            $table->string('breed', 40);
            $table->char('color_hex', 7);
            $table->smallInteger('size');
            $table->string('source', 20)->default('manual');
            $table->string('source_ref', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'deleted_at']);
            $table->index(['user_id', 'breed']);
            $table->index(['user_id', 'created_at']);
        });

        // DB-level safety nets (FormRequest is the primary validator).
        DB::statement("ALTER TABLE fishes ADD CONSTRAINT fishes_color_hex_chk CHECK (color_hex ~ '^#[0-9A-Fa-f]{6}$')");
        DB::statement('ALTER TABLE fishes ADD CONSTRAINT fishes_size_chk CHECK (size BETWEEN 1 AND 100)');
        DB::statement("ALTER TABLE fishes ADD CONSTRAINT fishes_source_chk CHECK (source IN ('manual','github_repo'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('fishes');
    }
};
```

- [ ] **Step 2: Run migration.**

```bash
docker compose exec backend php artisan migrate
```

Expected: 1 migration runs. No errors.

- [ ] **Step 3: Verify schema.**

```bash
docker compose exec db psql -U fishbook -d fishbook -c "\d fishes"
```

Expected: columns + 3 indexes + 3 CHECK constraints present.

- [ ] **Step 4: Commit.**

```bash
git add backend/database/migrations/2026_05_16_000000_create_fishes_table.php
git commit -m "$(cat <<'EOF'
feat(backend): create fishes table with soft-delete, indexes, and CHECK constraints (SPEC §3)

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Backend — `Fish` model + factory

**Files:**
- Create: `backend/app/Models/Fish.php`
- Create: `backend/database/factories/FishFactory.php`

- [ ] **Step 1: Create the model.**

```php
<?php

namespace App\Models;

use Database\Factories\FishFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Fish extends Model
{
    /** @use HasFactory<FishFactory> */
    use HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'nickname',
        'breed',
        'color_hex',
        'size',
        'source',
        'source_ref',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    /** @return BelongsTo<User, Fish> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param Builder<Fish> $query */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
```

- [ ] **Step 2: Create the factory.**

```php
<?php

namespace Database\Factories;

use App\Models\Fish;
use App\Models\User;
use App\Services\Fish\BreedCatalog;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Fish> */
class FishFactory extends Factory
{
    protected $model = Fish::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $catalog = app(BreedCatalog::class)->all();
        $breed = $this->faker->randomElement($catalog);

        return [
            'user_id'    => User::factory(),
            'nickname'   => $this->faker->firstName(),
            'breed'      => $breed['id'],
            'color_hex'  => $breed['default_color'],
            'size'       => $this->faker->numberBetween($breed['min_size'], $breed['max_size']),
            'source'     => 'manual',
            'source_ref' => null,
        ];
    }
}
```

- [ ] **Step 3: Commit.**

```bash
git add backend/app/Models/Fish.php backend/database/factories/FishFactory.php
git commit -m "$(cat <<'EOF'
feat(backend): add Fish model + factory with forUser scope and soft-delete

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Backend — `BreedCatalog` service (TDD)

**Files:**
- Create: `backend/tests/Unit/Services/Fish/BreedCatalogTest.php`
- Create: `backend/app/Services/Fish/BreedCatalog.php`

- [ ] **Step 1: Failing tests.**

```php
<?php

use App\Services\Fish\BreedCatalog;

uses(Tests\TestCase::class);

it('finds a known breed', function () {
    $cat = app(BreedCatalog::class);
    expect($cat->find('guppy'))->not->toBeNull();
    expect($cat->find('guppy')['min_size'])->toBe(8);
});

it('returns null for an unknown breed', function () {
    expect(app(BreedCatalog::class)->find('shark'))->toBeNull();
});

it('clamps undersized values to min', function () {
    expect(app(BreedCatalog::class)->clampSize('guppy', 1))->toBe(8);
});

it('clamps oversized values to max', function () {
    expect(app(BreedCatalog::class)->clampSize('guppy', 99))->toBe(18);
});

it('validates a good triple', function () {
    expect(app(BreedCatalog::class)->validate('guppy', 12, '#FF6B9D'))->toBe([]);
});

it('reports a bad breed', function () {
    expect(app(BreedCatalog::class)->validate('shark', 12, '#FF6B9D'))
        ->toHaveKey('breed');
});

it('reports a bad size for a known breed', function () {
    expect(app(BreedCatalog::class)->validate('guppy', 99, '#FF6B9D'))
        ->toHaveKey('size');
});

it('reports a bad color', function () {
    expect(app(BreedCatalog::class)->validate('guppy', 12, 'red'))
        ->toHaveKey('color_hex');
});
```

```bash
docker compose exec backend ./vendor/bin/pest --filter=BreedCatalogTest
```

Expected: all 8 fail (class missing).

- [ ] **Step 2: Implement.**

```php
<?php

namespace App\Services\Fish;

use Illuminate\Contracts\Config\Repository;

class BreedCatalog
{
    public function __construct(private readonly Repository $config) {}

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        /** @var array<int, array<string, mixed>> $breeds */
        $breeds = $this->config->get('fish_breeds', []);
        return $breeds;
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        foreach ($this->all() as $breed) {
            if ($breed['id'] === $id) {
                return $breed;
            }
        }
        return null;
    }

    public function clampSize(string $breed, int $size): int
    {
        $b = $this->find($breed);
        if ($b === null) return $size;
        return max($b['min_size'], min($b['max_size'], $size));
    }

    /** @return array<string, array<int, string>> */
    public function validate(string $breed, int $size, string $colorHex): array
    {
        $errors = [];
        $b = $this->find($breed);
        if ($b === null) {
            $errors['breed'] = ['Unknown breed.'];
        } elseif ($size < $b['min_size'] || $size > $b['max_size']) {
            $errors['size'] = ["Size must be between {$b['min_size']} and {$b['max_size']} for {$breed}."];
        }
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $colorHex) !== 1) {
            $errors['color_hex'] = ['Color must be #RRGGBB.'];
        }
        return $errors;
    }
}
```

- [ ] **Step 3: Verify green.**

```bash
docker compose exec backend ./vendor/bin/pest --filter=BreedCatalogTest
```

Expected: 8 passing.

- [ ] **Step 4: Commit.**

```bash
git add backend/app/Services/Fish/BreedCatalog.php backend/tests/Unit/Services/Fish/BreedCatalogTest.php
git commit -m "$(cat <<'EOF'
feat(backend): add BreedCatalog service with size clamping and validation

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: Backend — fill in `FishPolicy`

**Files:**
- Modify: `backend/app/Policies/FishPolicy.php`

- [ ] **Step 1: Replace the stub.**

```php
<?php

namespace App\Policies;

use App\Models\Fish;
use App\Models\User;

class FishPolicy
{
    public function viewAny(User $user): bool { return true; }

    public function view(User $user, Fish $fish): bool
    {
        return $user->id === $fish->user_id || $user->is_admin;
    }

    public function create(User $user): bool { return true; }

    public function update(User $user, Fish $fish): bool
    {
        return $user->id === $fish->user_id;
    }

    public function delete(User $user, Fish $fish): bool
    {
        return $user->id === $fish->user_id;
    }
}
```

- [ ] **Step 2: Commit.**

```bash
git add backend/app/Policies/FishPolicy.php
git commit -m "$(cat <<'EOF'
feat(backend): implement FishPolicy (owner-only writes; admin can view all)

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: Backend — Form requests + Resources

**Files:**
- Create: `backend/app/Http/Requests/Fish/StoreFishRequest.php`
- Create: `backend/app/Http/Requests/Fish/UpdateFishRequest.php`
- Create: `backend/app/Http/Resources/FishResource.php`
- Create: `backend/app/Http/Resources/FishBreedResource.php`

- [ ] **Step 1: `StoreFishRequest`.**

```php
<?php

namespace App\Http\Requests\Fish;

use App\Services\Fish\BreedCatalog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StoreFishRequest',
    type: 'object',
    required: ['nickname', 'breed', 'color_hex', 'size'],
    properties: [
        new OA\Property(property: 'nickname', type: 'string', minLength: 1, maxLength: 40),
        new OA\Property(property: 'breed', type: 'string', example: 'guppy'),
        new OA\Property(property: 'color_hex', type: 'string', pattern: '^#[0-9A-Fa-f]{6}$', example: '#FF6B9D'),
        new OA\Property(property: 'size', type: 'integer', minimum: 1, maximum: 100),
    ],
)]
class StoreFishRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('nickname'))) {
            $this->merge(['nickname' => trim($this->input('nickname'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nickname'  => ['required', 'string', 'min:1', 'max:40'],
            'breed'     => ['required', 'string', $this->breedRule()],
            'color_hex' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'size'      => ['required', 'integer', $this->sizeRule()],
        ];
    }

    private function breedRule(): ValidationRule
    {
        return new class implements ValidationRule {
            public function validate(string $attribute, mixed $value, \Closure $fail): void
            {
                if (! is_string($value) || app(BreedCatalog::class)->find($value) === null) {
                    $fail('Unknown breed.');
                }
            }
        };
    }

    private function sizeRule(): ValidationRule
    {
        $breed = (string) $this->input('breed');
        return new class($breed) implements ValidationRule {
            public function __construct(private readonly string $breed) {}
            public function validate(string $attribute, mixed $value, \Closure $fail): void
            {
                $b = app(BreedCatalog::class)->find($this->breed);
                if ($b === null) return; // breed rule handles it
                if (! is_int($value) || $value < $b['min_size'] || $value > $b['max_size']) {
                    $fail("Size must be between {$b['min_size']} and {$b['max_size']} for {$this->breed}.");
                }
            }
        };
    }
}
```

- [ ] **Step 2: `UpdateFishRequest`.**

```php
<?php

namespace App\Http\Requests\Fish;

use App\Models\Fish;
use App\Services\Fish\BreedCatalog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateFishRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'nickname', type: 'string', minLength: 1, maxLength: 40),
        new OA\Property(property: 'color_hex', type: 'string', pattern: '^#[0-9A-Fa-f]{6}$'),
        new OA\Property(property: 'size', type: 'integer', minimum: 1, maximum: 100),
    ],
)]
class UpdateFishRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('nickname'))) {
            $this->merge(['nickname' => trim($this->input('nickname'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nickname'  => ['sometimes', 'string', 'min:1', 'max:40'],
            'color_hex' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'size'      => ['sometimes', 'integer', $this->sizeRule()],
            'breed'     => ['prohibited'],
        ];
    }

    private function sizeRule(): ValidationRule
    {
        /** @var Fish $fish */
        $fish = $this->route('fish');
        $breed = $fish?->breed ?? '';
        return new class($breed) implements ValidationRule {
            public function __construct(private readonly string $breed) {}
            public function validate(string $attribute, mixed $value, \Closure $fail): void
            {
                $b = app(BreedCatalog::class)->find($this->breed);
                if ($b === null) return;
                if (! is_int($value) || $value < $b['min_size'] || $value > $b['max_size']) {
                    $fail("Size must be between {$b['min_size']} and {$b['max_size']} for {$this->breed}.");
                }
            }
        };
    }
}
```

- [ ] **Step 3: `FishResource`.**

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'FishResource',
    type: 'object',
    required: ['id', 'nickname', 'breed', 'color_hex', 'size', 'source', 'created_at', 'updated_at'],
    properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'nickname', type: 'string'),
        new OA\Property(property: 'breed', type: 'string'),
        new OA\Property(property: 'color_hex', type: 'string'),
        new OA\Property(property: 'size', type: 'integer'),
        new OA\Property(property: 'source', type: 'string', enum: ['manual', 'github_repo']),
        new OA\Property(property: 'source_ref', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
)]
class FishResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'         => (string) $this->id,
            'nickname'   => $this->nickname,
            'breed'      => $this->breed,
            'color_hex'  => $this->color_hex,
            'size'       => (int) $this->size,
            'source'     => $this->source,
            'source_ref' => $this->source_ref,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 4: `FishBreedResource`.**

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'FishBreedResource',
    type: 'object',
    required: ['id', 'label', 'min_size', 'max_size', 'default_color', 'sprite_key'],
    properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'label', type: 'string'),
        new OA\Property(property: 'min_size', type: 'integer'),
        new OA\Property(property: 'max_size', type: 'integer'),
        new OA\Property(property: 'default_color', type: 'string'),
        new OA\Property(property: 'sprite_key', type: 'string'),
        new OA\Property(property: 'vertical_band_preference', type: 'string', enum: ['bottom'], nullable: true),
    ],
)]
class FishBreedResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $b */
        $b = (array) $this->resource;
        return [
            'id'                       => $b['id'],
            'label'                    => $b['label'],
            'min_size'                 => $b['min_size'],
            'max_size'                 => $b['max_size'],
            'default_color'            => $b['default_color'],
            'sprite_key'               => $b['sprite_key'],
            'vertical_band_preference' => $b['vertical_band_preference'] ?? null,
        ];
    }
}
```

- [ ] **Step 5: Commit.**

```bash
git add backend/app/Http/Requests/Fish/ backend/app/Http/Resources/FishResource.php backend/app/Http/Resources/FishBreedResource.php
git commit -m "$(cat <<'EOF'
feat(backend): add Store/UpdateFishRequest + FishResource + FishBreedResource with OpenAPI schemas

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: Backend — `FishController` + routes (TDD, breeds first)

**Files:**
- Create: `backend/tests/Feature/Fishes/BreedsTest.php`
- Create: `backend/app/Http/Controllers/Api/V1/FishController.php`
- Modify: `backend/routes/api.php`

- [ ] **Step 1: Failing breeds test.**

```php
<?php

uses(Tests\TestCase::class);

it('returns the breeds catalog publicly', function () {
    $r = $this->getJson('/api/v1/fishes/breeds');
    $r->assertOk()
      ->assertJsonStructure(['data' => [['id', 'label', 'min_size', 'max_size', 'default_color', 'sprite_key']]]);
    expect(count($r->json('data')))->toBe(10);
});

it('marks otocinclus and cory_catfish as bottom-dwellers', function () {
    $r = $this->getJson('/api/v1/fishes/breeds');
    $rows = collect($r->json('data'))->keyBy('id');
    expect($rows['otocinclus']['vertical_band_preference'])->toBe('bottom');
    expect($rows['cory_catfish']['vertical_band_preference'])->toBe('bottom');
});
```

```bash
docker compose exec backend ./vendor/bin/pest --filter=BreedsTest
```

Expected: 404 — route not registered.

- [ ] **Step 2: Create the controller (full surface, will be exercised by later tasks too).**

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Fish\StoreFishRequest;
use App\Http\Requests\Fish\UpdateFishRequest;
use App\Http\Resources\FishBreedResource;
use App\Http\Resources\FishResource;
use App\Models\Fish;
use App\Services\Fish\BreedCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FishController extends Controller
{
    public function __construct(private readonly BreedCatalog $breeds)
    {
        $this->authorizeResource(Fish::class, 'fish');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Fish::forUser($request->user()->id);

        if ($s = $request->string('search')->toString()) {
            $q->where('nickname', 'ilike', '%'.$s.'%');
        }
        if ($b = $request->string('breed')->toString()) {
            $q->where('breed', $b);
        }
        if ($c = $request->string('color')->toString()) {
            $q->where('color_hex', $c);
        }

        $sort = $request->string('sort', 'created_at')->toString();
        $sortMap = ['name' => 'nickname', 'breed' => 'breed', 'created_at' => 'created_at', 'size' => 'size'];
        $sortCol = $sortMap[$sort] ?? 'created_at';
        $direction = $request->string('direction', 'desc')->toString() === 'asc' ? 'asc' : 'desc';
        $q->orderBy($sortCol, $direction);

        $perPage = (int) min($request->integer('per_page', 25), 100);
        return FishResource::collection($q->paginate($perPage));
    }

    public function store(StoreFishRequest $request): JsonResponse
    {
        $fish = Fish::create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
            'source'  => 'manual',
        ]);
        return (new FishResource($fish))->response()->setStatusCode(201);
    }

    public function show(Fish $fish): FishResource
    {
        return new FishResource($fish);
    }

    public function update(UpdateFishRequest $request, Fish $fish): FishResource
    {
        $fish->update($request->validated());
        return new FishResource($fish->fresh());
    }

    public function destroy(Fish $fish): JsonResponse
    {
        $fish->delete();
        return response()->json(null, 204);
    }

    /** Public endpoint — see routes/api.php. */
    public function breeds(): AnonymousResourceCollection
    {
        return FishBreedResource::collection($this->breeds->all());
    }
}
```

Note: `authorizeResource` doesn't cover `breeds`; that's why it's registered outside the resource (next step) and bypasses the policy.

- [ ] **Step 3: Wire routes in `backend/routes/api.php`.**

Add **outside** the `auth:sanctum` group (public, throttled):

```php
Route::get('v1/fishes/breeds', [\App\Http\Controllers\Api\V1\FishController::class, 'breeds'])
    ->middleware('throttle:api')
    ->name('fishes.breeds');
```

Add **inside** the `auth:sanctum` group:

```php
Route::apiResource('v1/fishes', \App\Http\Controllers\Api\V1\FishController::class)
    ->parameters(['fishes' => 'fish'])
    ->where(['fish' => '[0-9]+']);
```

The numeric `where` constraint prevents `fishes/breeds` from accidentally route-binding `breeds` as a fish id.

- [ ] **Step 4: Run breeds test green.**

```bash
docker compose exec backend ./vendor/bin/pest --filter=BreedsTest
```

Expected: 2 passing.

- [ ] **Step 5: Commit.**

```bash
git add backend/app/Http/Controllers/Api/V1/FishController.php backend/routes/api.php backend/tests/Feature/Fishes/BreedsTest.php
git commit -m "$(cat <<'EOF'
feat(backend): add FishController + public fishes/breeds endpoint

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: Backend — feature tests: index (+N+1 guard) and store

**Files:**
- Create: `backend/tests/Feature/Fishes/IndexFishesTest.php`
- Create: `backend/tests/Feature/Fishes/StoreFishTest.php`

- [ ] **Step 1: `IndexFishesTest`.**

```php
<?php

use App\Models\Fish;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

it('rejects unauthed', function () {
    auth()->forgetGuards();
    $this->getJson('/api/v1/fishes')->assertStatus(401);
});

it('lists only the authed user’s fish', function () {
    Fish::factory()->count(3)->for($this->user)->create();
    Fish::factory()->count(2)->create();

    $r = $this->getJson('/api/v1/fishes');
    $r->assertOk()->assertJsonCount(3, 'data');
});

it('paginates with capped per_page', function () {
    Fish::factory()->count(120)->for($this->user)->create();
    $r = $this->getJson('/api/v1/fishes?per_page=200');
    expect(count($r->json('data')))->toBe(100);
});

it('filters by breed and color, sorts by name asc', function () {
    Fish::factory()->for($this->user)->create(['nickname' => 'Aaron', 'breed' => 'guppy',  'color_hex' => '#FF6B9D']);
    Fish::factory()->for($this->user)->create(['nickname' => 'Zed',   'breed' => 'guppy',  'color_hex' => '#FF6B9D']);
    Fish::factory()->for($this->user)->create(['nickname' => 'Mel',   'breed' => 'molly',  'color_hex' => '#1F2937']);

    $r = $this->getJson('/api/v1/fishes?breed=guppy&color=%23FF6B9D&sort=name&direction=asc');
    $r->assertOk();
    expect(collect($r->json('data'))->pluck('nickname')->all())->toBe(['Aaron', 'Zed']);
});

it('runs under the query budget (N+1 guardrail)', function () {
    Fish::factory()->count(50)->for($this->user)->create();
    DB::enableQueryLog();
    $this->getJson('/api/v1/fishes')->assertOk();
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(4);
});
```

- [ ] **Step 2: `StoreFishTest`.**

```php
<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

it('creates a fish with source=manual and the authed user_id', function () {
    $r = $this->postJson('/api/v1/fishes', [
        'nickname' => 'Blubsworth',
        'breed' => 'guppy',
        'color_hex' => '#FF6B9D',
        'size' => 12,
        // attempted mass-assignment — must be ignored:
        'user_id' => 99999,
        'source' => 'github_repo',
        'source_ref' => 'evil/repo',
    ]);
    $r->assertCreated()
      ->assertJsonPath('data.nickname', 'Blubsworth')
      ->assertJsonPath('data.source', 'manual')
      ->assertJsonPath('data.source_ref', null);

    expect(\App\Models\Fish::first()->user_id)->toBe($this->user->id);
});

it('rejects unknown breed', function () {
    $this->postJson('/api/v1/fishes', [
        'nickname' => 'Sharky', 'breed' => 'great_white', 'color_hex' => '#000000', 'size' => 12,
    ])->assertStatus(422)->assertJsonValidationErrors('breed');
});

it('rejects size out of breed range', function () {
    $this->postJson('/api/v1/fishes', [
        'nickname' => 'Tiny', 'breed' => 'guppy', 'color_hex' => '#FF6B9D', 'size' => 1,
    ])->assertStatus(422)->assertJsonValidationErrors('size');
});

it('rejects invalid color hex', function () {
    $this->postJson('/api/v1/fishes', [
        'nickname' => 'Bad', 'breed' => 'guppy', 'color_hex' => 'red', 'size' => 12,
    ])->assertStatus(422)->assertJsonValidationErrors('color_hex');
});

it('rejects empty nickname after trim', function () {
    $this->postJson('/api/v1/fishes', [
        'nickname' => '   ', 'breed' => 'guppy', 'color_hex' => '#FF6B9D', 'size' => 12,
    ])->assertStatus(422)->assertJsonValidationErrors('nickname');
});
```

- [ ] **Step 3: Run.**

```bash
docker compose exec backend ./vendor/bin/pest --filter='Fishes'
```

Expected: all pass.

- [ ] **Step 4: Commit.**

```bash
git add backend/tests/Feature/Fishes/IndexFishesTest.php backend/tests/Feature/Fishes/StoreFishTest.php
git commit -m "$(cat <<'EOF'
test(backend): cover Fishes index (auth, scoping, filter/sort, N+1) + store (defaults, validation)

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 10: Backend — feature tests: show, update, delete

**Files:**
- Create: `backend/tests/Feature/Fishes/ShowFishTest.php`
- Create: `backend/tests/Feature/Fishes/UpdateFishTest.php`
- Create: `backend/tests/Feature/Fishes/DeleteFishTest.php`

- [ ] **Step 1: `ShowFishTest`.**

```php
<?php

use App\Models\Fish;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

it('returns own fish', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $fish = Fish::factory()->for($user)->create();
    $this->getJson("/api/v1/fishes/{$fish->id}")->assertOk()->assertJsonPath('data.id', (string) $fish->id);
});

it('forbids viewing another user’s fish', function () {
    $me = User::factory()->create();
    Sanctum::actingAs($me);
    $other = Fish::factory()->create();
    $this->getJson("/api/v1/fishes/{$other->id}")->assertStatus(403);
});

it('returns 404 for soft-deleted fish', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $fish = Fish::factory()->for($user)->create();
    $fish->delete();
    $this->getJson("/api/v1/fishes/{$fish->id}")->assertStatus(404);
});
```

- [ ] **Step 2: `UpdateFishTest`.**

```php
<?php

use App\Models\Fish;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    $this->fish = Fish::factory()->for($this->user)->create(['breed' => 'guppy', 'size' => 12]);
});

it('updates nickname / color / size', function () {
    $this->patchJson("/api/v1/fishes/{$this->fish->id}", [
        'nickname' => 'Newname', 'color_hex' => '#123456', 'size' => 15,
    ])->assertOk()->assertJsonPath('data.nickname', 'Newname');
});

it('rejects breed change (immutable)', function () {
    $this->patchJson("/api/v1/fishes/{$this->fish->id}", ['breed' => 'molly'])
        ->assertStatus(422)->assertJsonValidationErrors('breed');
});

it('rejects size outside the breed range', function () {
    $this->patchJson("/api/v1/fishes/{$this->fish->id}", ['size' => 99])
        ->assertStatus(422)->assertJsonValidationErrors('size');
});

it('forbids updating another user’s fish', function () {
    $other = Fish::factory()->create();
    $this->patchJson("/api/v1/fishes/{$other->id}", ['nickname' => 'pwn'])->assertStatus(403);
});
```

- [ ] **Step 3: `DeleteFishTest`.**

```php
<?php

use App\Models\Fish;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

it('soft-deletes and disappears from index but stays in DB', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $fish = Fish::factory()->for($user)->create();

    $this->deleteJson("/api/v1/fishes/{$fish->id}")->assertNoContent();

    $this->getJson('/api/v1/fishes')->assertOk()->assertJsonCount(0, 'data');

    expect(\DB::table('fishes')->where('id', $fish->id)->whereNotNull('deleted_at')->exists())->toBeTrue();
});

it('forbids deleting another user’s fish', function () {
    $me = User::factory()->create();
    Sanctum::actingAs($me);
    $other = Fish::factory()->create();
    $this->deleteJson("/api/v1/fishes/{$other->id}")->assertStatus(403);
});

it('returns 404 on second delete (idempotency boundary)', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $fish = Fish::factory()->for($user)->create();
    $this->deleteJson("/api/v1/fishes/{$fish->id}")->assertNoContent();
    $this->deleteJson("/api/v1/fishes/{$fish->id}")->assertStatus(404);
});
```

- [ ] **Step 4: Run.**

```bash
docker compose exec backend ./vendor/bin/pest --filter='Fishes'
```

Expected: all green.

- [ ] **Step 5: Commit.**

```bash
git add backend/tests/Feature/Fishes/Show*.php backend/tests/Feature/Fishes/Update*.php backend/tests/Feature/Fishes/Delete*.php
git commit -m "$(cat <<'EOF'
test(backend): cover Fishes show/update/delete + soft-delete + policy

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 11: Backend — OpenAPI annotations on `FishController` + regen

**Files:**
- Modify: `backend/app/Http/Controllers/Api/V1/FishController.php` (add `#[OA\...]` attributes)
- Modify: `backend/storage/api-docs/openapi.json` (regenerated)

- [ ] **Step 1: Add operation annotations.**

Above each method, e.g. for `index`:

```php
#[OA\Get(
    path: '/api/v1/fishes',
    operationId: 'listFishes',
    tags: ['Fishes'],
    security: [['sanctum' => []]],
    parameters: [
        new OA\Parameter(name: 'search',    in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'breed',     in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'color',     in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'sort',      in: 'query', schema: new OA\Schema(type: 'string', enum: ['name','breed','created_at','size'])),
        new OA\Parameter(name: 'direction', in: 'query', schema: new OA\Schema(type: 'string', enum: ['asc','desc'])),
        new OA\Parameter(name: 'page',      in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'per_page',  in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
    ],
    responses: [new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/PaginatedFishCollection'))],
)]
```

Define `PaginatedFishCollection` as a named schema in a new `backend/app/OpenApi/Schemas.php` file (a holder for `#[OA\Schema]` declarations that aren't tied to a single class):

```php
<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PaginatedFishCollection',
    type: 'object',
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/FishResource')),
        new OA\Property(property: 'links', type: 'object'),
        new OA\Property(property: 'meta', type: 'object'),
    ],
)]
class Schemas {}
```

Repeat operation annotations for `store` (`operationId: 'createFish'`), `show` (`getFish`), `update` (`updateFish`), `destroy` (`deleteFish`), `breeds` (`listBreeds`). Use the named schemas everywhere — no inline JsonContent.

- [ ] **Step 2: Regenerate.**

```bash
docker compose exec backend php artisan l5-swagger:generate
git diff --stat backend/storage/api-docs/openapi.json
```

Expected: spec diff includes new schemas + 6 operations with explicit `operationId`s.

- [ ] **Step 3: Commit.**

```bash
git add backend/app/Http/Controllers/Api/V1/FishController.php backend/app/OpenApi/Schemas.php backend/storage/api-docs/openapi.json
git commit -m "$(cat <<'EOF'
feat(backend): annotate Fishes endpoints with named OpenAPI schemas and operationIds; regen spec

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 12: Frontend — install deps and regenerate API client

**Files:**
- Modify: `frontend/package.json`, `frontend/package-lock.json`
- Modify: `frontend/src/lib/api-client/` (regenerated)

- [ ] **Step 1: Add deps.**

```bash
cd frontend && npm install @radix-ui/react-dialog clsx
```

- [ ] **Step 2: Regenerate API client against the new spec.**

```bash
cd frontend && npm run generate:api
```

Expected: `src/lib/api-client/apis/FishesApi.ts` (or similar) appears with `listFishes`, `createFish`, `getFish`, `updateFish`, `deleteFish`, `listBreeds`.

- [ ] **Step 3: Commit.**

```bash
git add frontend/package.json frontend/package-lock.json frontend/src/lib/api-client/
git commit -m "$(cat <<'EOF'
chore(frontend): add @radix-ui/react-dialog + clsx; regen api client with FishesApi

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 13: Frontend — sprites (all 10 SVGs)

**Files:**
- Create: `frontend/public/sprites/fish/{breed}.svg` × 10

> All sprites share an 80×40 viewBox. The body uses `currentColor` so the canvas's recolor pass can tint via `globalCompositeOperation = 'source-in'`. The fish point **right**; the canvas flips horizontally on negative vx. Bottom-dwellers (`otocinclus`, `cory_catfish`) have a flat-belly profile.

- [ ] **Step 1: Write each file.**

`frontend/public/sprites/fish/guppy.svg`:
```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 40">
  <ellipse cx="34" cy="20" rx="22" ry="10" fill="currentColor"/>
  <path d="M12 20 L0 8 L0 32 Z" fill="currentColor"/>
  <path d="M50 14 Q56 20 50 26 L46 20 Z" fill="currentColor" opacity="0.7"/>
  <circle cx="48" cy="17" r="1.6" fill="#0c1209"/>
</svg>
```

`frontend/public/sprites/fish/molly.svg`:
```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 40">
  <ellipse cx="36" cy="20" rx="26" ry="12" fill="currentColor"/>
  <path d="M10 20 L0 6 L0 34 Z" fill="currentColor"/>
  <path d="M52 12 Q60 20 52 28 L46 20 Z" fill="currentColor" opacity="0.7"/>
  <circle cx="52" cy="17" r="1.8" fill="#0c1209"/>
</svg>
```

`frontend/public/sprites/fish/neon_tetra.svg`:
```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 40">
  <ellipse cx="34" cy="20" rx="20" ry="7" fill="currentColor"/>
  <path d="M14 20 L4 12 L4 28 Z" fill="currentColor"/>
  <rect x="22" y="18" width="22" height="2" fill="#ffffff" opacity="0.55"/>
  <path d="M48 15 Q54 20 48 25 L44 20 Z" fill="currentColor" opacity="0.7"/>
  <circle cx="46" cy="18" r="1.2" fill="#0c1209"/>
</svg>
```

`frontend/public/sprites/fish/zebra_danio.svg`:
```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 40">
  <ellipse cx="34" cy="20" rx="22" ry="8" fill="currentColor"/>
  <path d="M12 20 L2 10 L2 30 Z" fill="currentColor"/>
  <g fill="#1f2937" opacity="0.45">
    <rect x="22" y="13" width="2" height="14"/>
    <rect x="28" y="13" width="2" height="14"/>
    <rect x="34" y="13" width="2" height="14"/>
    <rect x="40" y="13" width="2" height="14"/>
  </g>
  <path d="M50 14 Q56 20 50 26 L46 20 Z" fill="currentColor" opacity="0.7"/>
  <circle cx="48" cy="18" r="1.4" fill="#0c1209"/>
</svg>
```

`frontend/public/sprites/fish/platy.svg`:
```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 40">
  <ellipse cx="36" cy="20" rx="22" ry="13" fill="currentColor"/>
  <path d="M14 20 L4 8 L4 32 Z" fill="currentColor"/>
  <path d="M50 12 Q58 20 50 28 L46 20 Z" fill="currentColor" opacity="0.7"/>
  <circle cx="50" cy="17" r="1.6" fill="#0c1209"/>
</svg>
```

`frontend/public/sprites/fish/endler.svg`:
```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 40">
  <ellipse cx="32" cy="20" rx="16" ry="6" fill="currentColor"/>
  <path d="M16 20 L8 14 L8 26 Z" fill="currentColor"/>
  <path d="M42 16 Q48 20 42 24 L38 20 Z" fill="currentColor" opacity="0.7"/>
  <circle cx="42" cy="18" r="1.0" fill="#0c1209"/>
</svg>
```

`frontend/public/sprites/fish/cherry_barb.svg`:
```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 40">
  <ellipse cx="34" cy="20" rx="20" ry="9" fill="currentColor"/>
  <path d="M14 20 L4 10 L4 30 Z" fill="currentColor"/>
  <rect x="20" y="19" width="20" height="2" fill="#ffffff" opacity="0.35"/>
  <path d="M48 14 Q54 20 48 26 L44 20 Z" fill="currentColor" opacity="0.7"/>
  <circle cx="46" cy="17" r="1.4" fill="#0c1209"/>
</svg>
```

`frontend/public/sprites/fish/white_cloud_minnow.svg`:
```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 40">
  <ellipse cx="34" cy="20" rx="20" ry="7" fill="currentColor"/>
  <path d="M14 20 L4 12 L4 28 Z" fill="currentColor"/>
  <rect x="22" y="18" width="22" height="1.5" fill="#f59e0b" opacity="0.5"/>
  <path d="M48 14 Q54 20 48 26 L44 20 Z" fill="currentColor" opacity="0.7"/>
  <circle cx="46" cy="18" r="1.3" fill="#0c1209"/>
</svg>
```

`frontend/public/sprites/fish/otocinclus.svg` (flat-belly, bottom-dweller):
```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 40">
  <path d="M8 28 L8 22 Q18 12 38 12 Q54 12 60 22 L60 28 Z" fill="currentColor"/>
  <path d="M8 28 L0 24 L0 32 Z" fill="currentColor"/>
  <path d="M60 20 Q66 24 60 28 Z" fill="currentColor" opacity="0.6"/>
  <circle cx="52" cy="19" r="1.3" fill="#0c1209"/>
</svg>
```

`frontend/public/sprites/fish/cory_catfish.svg` (broader flat-belly):
```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 40">
  <path d="M6 30 L6 22 Q18 8 40 8 Q58 8 64 22 L64 30 Z" fill="currentColor"/>
  <path d="M6 30 L0 24 L0 34 Z" fill="currentColor"/>
  <path d="M24 8 L28 4 L32 8 Z" fill="currentColor" opacity="0.8"/>
  <path d="M64 18 Q70 24 64 30 Z" fill="currentColor" opacity="0.6"/>
  <circle cx="54" cy="17" r="1.5" fill="#0c1209"/>
  <line x1="50" y1="20" x2="42" y2="22" stroke="#0c1209" stroke-width="0.6" opacity="0.6"/>
</svg>
```

- [ ] **Step 2: Sanity check.**

```bash
ls frontend/public/sprites/fish/ | wc -l
```

Expected: `10`.

- [ ] **Step 3: Commit.**

```bash
git add frontend/public/sprites/fish/
git commit -m "$(cat <<'EOF'
feat(frontend): add 10 fish sprite SVGs (currentColor-tintable, 80x40 viewBox)

Otocinclus and Cory Catfish have flat-belly profiles to read as bottom-dwellers.
Body uses currentColor so the canvas's source-in recolor pass works without sprite duplication.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 14: Frontend — `seeded-random` (TDD)

**Files:**
- Create: `frontend/tests/unit/lib/aquarium/seeded-random.test.ts`
- Create: `frontend/src/lib/aquarium/seeded-random.ts`

- [ ] **Step 1: Failing test.**

```ts
import { describe, it, expect } from 'vitest';
import { mulberry32, hashStringToSeed } from '@/lib/aquarium/seeded-random';

describe('seeded-random', () => {
  it('is deterministic for the same seed', () => {
    const a = mulberry32(1234);
    const b = mulberry32(1234);
    for (let i = 0; i < 5; i++) expect(a()).toBe(b());
  });
  it('diverges for different seeds', () => {
    expect(mulberry32(1)()).not.toBe(mulberry32(2)());
  });
  it('hashes a stable seed from a string', () => {
    expect(hashStringToSeed('fish-7')).toBe(hashStringToSeed('fish-7'));
    expect(hashStringToSeed('fish-7')).not.toBe(hashStringToSeed('fish-8'));
  });
});
```

- [ ] **Step 2: Implement.**

```ts
export function mulberry32(seed: number): () => number {
  let t = seed >>> 0;
  return function () {
    t = (t + 0x6D2B79F5) >>> 0;
    let x = t;
    x = Math.imul(x ^ (x >>> 15), x | 1);
    x ^= x + Math.imul(x ^ (x >>> 7), x | 61);
    return ((x ^ (x >>> 14)) >>> 0) / 4294967296;
  };
}

export function hashStringToSeed(s: string): number {
  let h = 2166136261 >>> 0;
  for (let i = 0; i < s.length; i++) {
    h ^= s.charCodeAt(i);
    h = Math.imul(h, 16777619);
  }
  return h >>> 0;
}
```

- [ ] **Step 3: Run + commit.**

```bash
cd frontend && npm test -- --run seeded-random
git add frontend/src/lib/aquarium/seeded-random.ts frontend/tests/unit/lib/aquarium/seeded-random.test.ts
git commit -m "$(cat <<'EOF'
feat(frontend): add mulberry32 + hashStringToSeed for deterministic aquarium visuals

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 15: Frontend — `Fish` class (TDD; the core)

**Files:**
- Create: `frontend/tests/unit/lib/aquarium/Fish.test.ts`
- Create: `frontend/src/lib/aquarium/Fish.ts`

- [ ] **Step 1: Failing tests.**

```ts
import { describe, it, expect } from 'vitest';
import { Fish } from '@/lib/aquarium/Fish';
import { mulberry32 } from '@/lib/aquarium/seeded-random';

const vp = { w: 1000, h: 600 };

function mk(opts: Partial<ConstructorParameters<typeof Fish>[0]> = {}) {
  return new Fish({
    id: 'f1', breed: 'guppy', color_hex: '#FF6B9D', size: 12, nickname: 'Blub',
    prng: mulberry32(1234), viewport: vp, verticalBandPreference: null,
    ...opts,
  });
}

describe('Fish', () => {
  it('initializes inside the viewport', () => {
    const f = mk();
    expect(f.position.x).toBeGreaterThanOrEqual(0);
    expect(f.position.x).toBeLessThanOrEqual(vp.w);
  });

  it('is deterministic given the same seed', () => {
    const a = mk(); const b = mk();
    expect(a.position).toEqual(b.position);
  });

  it('clamps bottom-dweller target.y below 60% of viewport.h', () => {
    const f = mk({ verticalBandPreference: 'bottom' });
    for (let i = 0; i < 50; i++) f.pickNewTarget(vp);
    expect(f.target.y).toBeGreaterThan(vp.h * 0.6);
  });

  it('reports an AABB that contains its position', () => {
    const f = mk();
    const aabb = f.aabb();
    expect(f.position.x).toBeGreaterThanOrEqual(aabb.x);
    expect(f.position.x).toBeLessThanOrEqual(aabb.x + aabb.w);
  });

  it('reduces max speed as size grows', () => {
    const small = mk({ size: 6 });
    const big = mk({ size: 20 });
    expect(small.maxSpeed).toBeGreaterThan(big.maxSpeed);
  });

  it('eats the nearest pellet within feedingRadius', () => {
    const f = mk();
    f.position = { x: 100, y: 100 };
    const pellet = { id: 'p1', position: { x: 102, y: 100 }, eaten: false, createdAt: 0 } as any;
    f.update(16, [pellet], vp);
    expect(pellet.eaten).toBe(true);
    expect(f.eatingUntil).toBeGreaterThan(0);
  });
});
```

- [ ] **Step 2: Implement.**

```ts
import type { FoodPellet } from './FoodPellet';

export type Vec = { x: number; y: number };
export type FishInit = {
  id: string;
  breed: string;
  color_hex: string;
  size: number;
  nickname: string;
  prng: () => number;
  viewport: { w: number; h: number };
  verticalBandPreference: 'bottom' | null;
};

const FEEDING_RADIUS = 200;
const EATING_DISTANCE = 12;
const MAX_ACCEL = 200;          // px / s^2
const BASE_MAX_SPEED = 120;     // px / s for size=1; scales inversely with size
const TARGET_MIN_MS = 1500;
const TARGET_MAX_MS = 4500;

export class Fish {
  id: string;
  breed: string;
  color_hex: string;
  size: number;
  nickname: string;
  position: Vec;
  velocity: Vec = { x: 0, y: 0 };
  target: Vec;
  nextTargetAt: number;
  bobPhase: number;
  eatingUntil = 0;
  hovered = false;
  readonly maxSpeed: number;
  readonly verticalBandPreference: 'bottom' | null;
  private readonly prng: () => number;

  constructor(init: FishInit) {
    this.id = init.id;
    this.breed = init.breed;
    this.color_hex = init.color_hex;
    this.size = init.size;
    this.nickname = init.nickname;
    this.prng = init.prng;
    this.verticalBandPreference = init.verticalBandPreference;
    this.maxSpeed = BASE_MAX_SPEED * (12 / Math.max(6, init.size));
    this.position = this.randomPoint(init.viewport);
    this.target = this.randomPoint(init.viewport);
    this.nextTargetAt = TARGET_MIN_MS + this.prng() * (TARGET_MAX_MS - TARGET_MIN_MS);
    this.bobPhase = this.prng() * Math.PI * 2;
  }

  private randomPoint(vp: { w: number; h: number }): Vec {
    const x = vp.w * 0.05 + this.prng() * vp.w * 0.9;
    let y = vp.h * 0.05 + this.prng() * vp.h * 0.9;
    if (this.verticalBandPreference === 'bottom') {
      y = vp.h * 0.6 + this.prng() * vp.h * 0.35;
    }
    return { x, y };
  }

  pickNewTarget(vp: { w: number; h: number }) {
    this.target = this.randomPoint(vp);
    this.nextTargetAt = TARGET_MIN_MS + this.prng() * (TARGET_MAX_MS - TARGET_MIN_MS);
  }

  aabb(): { x: number; y: number; w: number; h: number } {
    const w = 2 * this.size + 20;
    const h = this.size + 14;
    return { x: this.position.x - w / 2, y: this.position.y - h / 2, w, h };
  }

  update(dtMs: number, food: FoodPellet[], vp: { w: number; h: number }) {
    const dt = dtMs / 1000;

    // Pick a pellet target if one is close enough.
    let chase: Vec | null = null;
    let bestD2 = FEEDING_RADIUS * FEEDING_RADIUS;
    for (const p of food) {
      if (p.eaten) continue;
      const dx = p.position.x - this.position.x;
      const dy = p.position.y - this.position.y;
      const d2 = dx * dx + dy * dy;
      if (d2 < bestD2) { bestD2 = d2; chase = p.position; }
      if (d2 < EATING_DISTANCE * EATING_DISTANCE) {
        p.eaten = true;
        this.eatingUntil = (typeof performance !== 'undefined' ? performance.now() : Date.now()) + 400;
      }
    }
    const aim = chase ?? this.target;

    if (!this.hovered) {
      this.nextTargetAt -= dtMs;
      if (this.nextTargetAt <= 0) this.pickNewTarget(vp);
    }

    // Seek with capped accel.
    const dx = aim.x - this.position.x;
    const dy = aim.y - this.position.y;
    const dist = Math.hypot(dx, dy) || 1;
    const ax = (dx / dist) * MAX_ACCEL;
    const ay = (dy / dist) * MAX_ACCEL;
    this.velocity.x += ax * dt;
    this.velocity.y += ay * dt;

    const speed = Math.hypot(this.velocity.x, this.velocity.y);
    if (speed > this.maxSpeed) {
      this.velocity.x = (this.velocity.x / speed) * this.maxSpeed;
      this.velocity.y = (this.velocity.y / speed) * this.maxSpeed;
    }

    this.position.x += this.velocity.x * dt;
    this.position.y += this.velocity.y * dt + Math.sin(this.bobPhase) * 0.4;
    this.bobPhase += dt * 4;
  }

  render(ctx: CanvasRenderingContext2D, sprite: CanvasImageSource) {
    const flipped = this.velocity.x < 0;
    ctx.save();
    ctx.translate(this.position.x, this.position.y);
    if (flipped) ctx.scale(-1, 1);
    const pulse = this.eatingUntil > performance.now() ? 1.1 : 1;
    const w = (2 * this.size + 20) * pulse;
    const h = (this.size + 14) * pulse;
    ctx.drawImage(sprite, -w / 2, -h / 2, w, h);
    ctx.restore();
  }
}
```

- [ ] **Step 3: Run + commit.**

```bash
cd frontend && npm test -- --run Fish
git add frontend/src/lib/aquarium/Fish.ts frontend/tests/unit/lib/aquarium/Fish.test.ts
git commit -m "$(cat <<'EOF'
feat(frontend): add Fish class with seeded steering, bottom-dweller band, AABB, eating, render

Per AGENT.md §3: pure TS, deterministic given prng, no React state in the simulation.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 16: Frontend — `FoodPellet` class (TDD)

**Files:**
- Create: `frontend/tests/unit/lib/aquarium/FoodPellet.test.ts`
- Create: `frontend/src/lib/aquarium/FoodPellet.ts`

- [ ] **Step 1: Failing tests.**

```ts
import { describe, it, expect } from 'vitest';
import { FoodPellet, MAX_LIFETIME_MS } from '@/lib/aquarium/FoodPellet';

describe('FoodPellet', () => {
  it('sinks over time', () => {
    const p = new FoodPellet({ x: 100, y: 100 });
    p.update(100); // 100 ms
    expect(p.position.y).toBeGreaterThan(100);
  });
  it('expires after MAX_LIFETIME_MS', () => {
    const p = new FoodPellet({ x: 0, y: 0 });
    p.createdAt = (typeof performance !== 'undefined' ? performance.now() : Date.now()) - MAX_LIFETIME_MS - 1;
    expect(p.isExpired()).toBe(true);
  });
});
```

- [ ] **Step 2: Implement.**

```ts
export const MAX_LIFETIME_MS = 10_000;
const SINK_VY = 30; // px / s

export class FoodPellet {
  id: string;
  position: { x: number; y: number };
  eaten = false;
  createdAt: number;

  constructor(at: { x: number; y: number }) {
    this.id = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
    this.position = { x: at.x, y: at.y };
    this.createdAt = (typeof performance !== 'undefined' ? performance.now() : Date.now());
  }

  update(dtMs: number) {
    this.position.y += SINK_VY * (dtMs / 1000);
  }

  isExpired(now = performance.now()) {
    return now - this.createdAt > MAX_LIFETIME_MS;
  }
}
```

(Note: `id` generation uses `Math.random` for *transient* visual ids only — not seeded-determinism-relevant. SPEC determinism applies to fish behavior, not single-session pellet ids.)

- [ ] **Step 3: Run + commit.**

```bash
cd frontend && npm test -- --run FoodPellet
git add frontend/src/lib/aquarium/FoodPellet.ts frontend/tests/unit/lib/aquarium/FoodPellet.test.ts
git commit -m "$(cat <<'EOF'
feat(frontend): add FoodPellet with sink physics + lifetime cap

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 17: Frontend — sprite cache helper

**Files:**
- Create: `frontend/src/lib/aquarium/sprite-cache.ts`

- [ ] **Step 1: Implement.**

```ts
const cache = new Map<string, HTMLCanvasElement>();
const imageCache = new Map<string, HTMLImageElement>();

function loadImage(src: string): Promise<HTMLImageElement> {
  const hit = imageCache.get(src);
  if (hit) return Promise.resolve(hit);
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => { imageCache.set(src, img); resolve(img); };
    img.onerror = reject;
    img.src = src;
  });
}

export async function getTintedSprite(breed: string, colorHex: string): Promise<HTMLCanvasElement> {
  const key = `${breed}:${colorHex}`;
  const hit = cache.get(key);
  if (hit) return hit;
  const base = await loadImage(`/sprites/fish/${breed}.svg`);
  const off = document.createElement('canvas');
  off.width = base.naturalWidth || 80;
  off.height = base.naturalHeight || 40;
  const c = off.getContext('2d')!;
  c.drawImage(base, 0, 0, off.width, off.height);
  c.globalCompositeOperation = 'source-in';
  c.fillStyle = colorHex;
  c.fillRect(0, 0, off.width, off.height);
  cache.set(key, off);
  return off;
}

export function clearSpriteCacheForTests() {
  cache.clear();
  imageCache.clear();
}
```

- [ ] **Step 2: Commit.**

```bash
git add frontend/src/lib/aquarium/sprite-cache.ts
git commit -m "$(cat <<'EOF'
feat(frontend): add sprite cache with source-in recolor pass

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 18: Frontend — `useAquariumStore` (TDD)

**Files:**
- Create: `frontend/tests/unit/stores/aquarium-store.test.ts`
- Create: `frontend/src/stores/aquarium-store.ts`

- [ ] **Step 1: Failing tests.**

```ts
import { describe, it, expect, beforeEach } from 'vitest';
import { useAquariumStore } from '@/stores/aquarium-store';

describe('useAquariumStore', () => {
  beforeEach(() => useAquariumStore.setState({ food: [], hoveredFishId: null, paused: false, cameraOffset: { x: 0, y: 0 } }));

  it('adds and consumes food', () => {
    const s = useAquariumStore.getState();
    s.addFood(10, 20);
    expect(useAquariumStore.getState().food).toHaveLength(1);
    const id = useAquariumStore.getState().food[0].id;
    s.consumeFood(id);
    expect(useAquariumStore.getState().food).toHaveLength(0);
  });

  it('sets and clears hovered fish', () => {
    useAquariumStore.getState().setHovered('f1');
    expect(useAquariumStore.getState().hoveredFishId).toBe('f1');
    useAquariumStore.getState().setHovered(null);
    expect(useAquariumStore.getState().hoveredFishId).toBeNull();
  });

  it('toggles pause', () => {
    useAquariumStore.getState().togglePause();
    expect(useAquariumStore.getState().paused).toBe(true);
  });
});
```

- [ ] **Step 2: Implement.**

```ts
import { create } from 'zustand';
import { persist, createJSONStorage } from 'zustand/middleware';

type Food = { id: string; x: number; y: number; createdAt: number };

type AquariumStore = {
  food: Food[];
  hoveredFishId: string | null;
  paused: boolean;
  cameraOffset: { x: number; y: number };
  addFood: (x: number, y: number) => void;
  consumeFood: (id: string) => void;
  setHovered: (id: string | null) => void;
  togglePause: () => void;
};

export const useAquariumStore = create<AquariumStore>()(
  persist(
    (set) => ({
      food: [],
      hoveredFishId: null,
      paused: false,
      cameraOffset: { x: 0, y: 0 },
      addFood: (x, y) => set((s) => ({
        food: [...s.food, { id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`, x, y, createdAt: Date.now() }],
      })),
      consumeFood: (id) => set((s) => ({ food: s.food.filter((p) => p.id !== id) })),
      setHovered: (id) => set({ hoveredFishId: id }),
      togglePause: () => set((s) => ({ paused: !s.paused })),
    }),
    {
      name: 'fishbook:aquarium',
      storage: createJSONStorage(() => localStorage),
      partialize: (s) => ({ paused: s.paused }) as any,
    },
  ),
);
```

- [ ] **Step 3: Run + commit.**

```bash
cd frontend && npm test -- --run aquarium-store
git add frontend/src/stores/aquarium-store.ts frontend/tests/unit/stores/aquarium-store.test.ts
git commit -m "$(cat <<'EOF'
feat(frontend): add useAquariumStore (zustand) with localStorage-persisted paused flag

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 19: Frontend — API hooks (`useFishesQuery`, mutations, etc.)

**Files:**
- Create: `frontend/src/lib/fish/schemas.ts`
- Create: `frontend/src/lib/fish/api.ts`
- Create: `frontend/src/hooks/use-fish-queries.ts`

- [ ] **Step 1: Zod schemas.**

```ts
import { z } from 'zod';

export const colorHexSchema = z.string().regex(/^#[0-9A-Fa-f]{6}$/, 'Must be #RRGGBB');

export const createFishSchema = z.object({
  nickname: z.string().trim().min(1).max(40),
  breed: z.string(),
  color_hex: colorHexSchema,
  size: z.number().int().min(1).max(100),
});

export const updateFishSchema = z.object({
  nickname: z.string().trim().min(1).max(40).optional(),
  color_hex: colorHexSchema.optional(),
  size: z.number().int().min(1).max(100).optional(),
});

export type CreateFishInput = z.infer<typeof createFishSchema>;
export type UpdateFishInput = z.infer<typeof updateFishSchema>;
```

- [ ] **Step 2: API wrappers (thin around generated client, proxied).**

```ts
import { Configuration, FishesApi } from '@/lib/api-client';

const config = new Configuration({ basePath: '/api/proxy' }); // proxied through Next.js
export const fishesApi = new FishesApi(config);
```

- [ ] **Step 3: Hooks.**

```ts
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { fishesApi } from '@/lib/fish/api';
import type { CreateFishInput, UpdateFishInput } from '@/lib/fish/schemas';

type ListParams = Partial<{ search: string; breed: string; color: string; sort: 'name'|'breed'|'created_at'|'size'; direction: 'asc'|'desc'; page: number; per_page: number }>;

export function useFishesQuery(params: ListParams = {}) {
  return useQuery({
    queryKey: ['fishes', 'list', params],
    queryFn: () => fishesApi.listFishes(params),
    staleTime: 5 * 60_000,
    refetchOnWindowFocus: false,
  });
}

export function useFishQuery(id: string | null) {
  return useQuery({
    queryKey: ['fishes', 'one', id],
    queryFn: () => fishesApi.getFish({ fish: id! }),
    enabled: id !== null,
  });
}

export function useBreedsQuery() {
  return useQuery({ queryKey: ['fishes','breeds'], queryFn: () => fishesApi.listBreeds(), staleTime: Infinity });
}

export function useCreateFishMutation() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: CreateFishInput) => fishesApi.createFish({ storeFishRequest: input }),
    onSettled: () => qc.invalidateQueries({ queryKey: ['fishes','list'] }),
  });
}

export function useUpdateFishMutation() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, patch }: { id: string; patch: UpdateFishInput }) =>
      fishesApi.updateFish({ fish: id, updateFishRequest: patch }),
    onSettled: () => qc.invalidateQueries({ queryKey: ['fishes','list'] }),
  });
}

export function useDeleteFishMutation() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => fishesApi.deleteFish({ fish: id }),
    onMutate: async (id) => {
      await qc.cancelQueries({ queryKey: ['fishes','list'] });
      const snapshots = qc.getQueriesData({ queryKey: ['fishes','list'] });
      for (const [key, val] of snapshots) {
        if (val && typeof val === 'object' && 'data' in (val as any)) {
          qc.setQueryData(key, { ...(val as any), data: (val as any).data.filter((f: any) => f.id !== id) });
        }
      }
      return { snapshots };
    },
    onError: (_e, _id, ctx) => {
      ctx?.snapshots.forEach(([key, val]) => qc.setQueryData(key, val));
    },
    onSettled: () => qc.invalidateQueries({ queryKey: ['fishes','list'] }),
  });
}
```

- [ ] **Step 4: Commit.**

```bash
git add frontend/src/lib/fish/ frontend/src/hooks/use-fish-queries.ts
git commit -m "$(cat <<'EOF'
feat(frontend): add fish zod schemas + TanStack Query hooks (list/one/breeds + CRUD mutations)

Optimistic delete with rollback; invalidate-on-settle for CRUD.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 20: Frontend — `AquariumCanvas` (the RAF surface)

**Files:**
- Create: `frontend/src/components/aquarium/AquariumCanvas.tsx`
- Create: `frontend/tests/unit/components/aquarium/AquariumCanvas.test.tsx`

- [ ] **Step 1: Implement.**

```tsx
'use client';

import { useEffect, useRef } from 'react';
import { Fish } from '@/lib/aquarium/Fish';
import { FoodPellet } from '@/lib/aquarium/FoodPellet';
import { getTintedSprite } from '@/lib/aquarium/sprite-cache';
import { hashStringToSeed, mulberry32 } from '@/lib/aquarium/seeded-random';
import { useAquariumStore } from '@/stores/aquarium-store';

type FishDTO = { id: string; breed: string; color_hex: string; size: number; nickname: string };
type Breed = { id: string; vertical_band_preference?: 'bottom' | null };

export function AquariumCanvas({ fishes, breeds }: { fishes: FishDTO[]; breeds: Breed[] }) {
  const canvasRef = useRef<HTMLCanvasElement>(null);
  const fishMapRef = useRef(new Map<string, Fish>());
  const pelletsRef = useRef<FoodPellet[]>([]);
  const rafIdRef = useRef<number>(0);
  const lastTimeRef = useRef<number>(0);
  const viewportRef = useRef({ w: 0, h: 0 });

  // Sync server list ↔ internal Fish[]
  useEffect(() => {
    const breedById = new Map(breeds.map((b) => [b.id, b]));
    const seen = new Set<string>();
    for (const dto of fishes) {
      seen.add(dto.id);
      const existing = fishMapRef.current.get(dto.id);
      if (!existing) {
        const b = breedById.get(dto.breed);
        fishMapRef.current.set(dto.id, new Fish({
          id: dto.id, breed: dto.breed, color_hex: dto.color_hex, size: dto.size, nickname: dto.nickname,
          prng: mulberry32(hashStringToSeed(dto.id)),
          viewport: viewportRef.current.w ? viewportRef.current : { w: window.innerWidth, h: window.innerHeight },
          verticalBandPreference: b?.vertical_band_preference ?? null,
        }));
      } else {
        existing.color_hex = dto.color_hex;
        existing.size = dto.size;
        existing.nickname = dto.nickname;
      }
    }
    for (const id of Array.from(fishMapRef.current.keys())) {
      if (!seen.has(id)) fishMapRef.current.delete(id);
    }
  }, [fishes, breeds]);

  // RAF loop — refs only.
  useEffect(() => {
    const canvas = canvasRef.current!;
    const ctx = canvas.getContext('2d')!;
    const ro = new ResizeObserver(() => {
      canvas.width = canvas.clientWidth;
      canvas.height = canvas.clientHeight;
      viewportRef.current = { w: canvas.width, h: canvas.height };
    });
    ro.observe(canvas);

    const mq = window.matchMedia('(prefers-reduced-motion: reduce)');
    const isHidden = () => document.visibilityState === 'hidden';

    const tick = async (now: number) => {
      const dt = lastTimeRef.current ? Math.min(50, now - lastTimeRef.current) : 16;
      lastTimeRef.current = now;
      const { paused } = useAquariumStore.getState();

      if (!paused && !mq.matches && !isHidden()) {
        for (const f of fishMapRef.current.values()) f.update(dt, pelletsRef.current, viewportRef.current);
        for (const p of pelletsRef.current) p.update(dt);
        pelletsRef.current = pelletsRef.current.filter((p) => !p.eaten && !p.isExpired(now));
      }

      ctx.clearRect(0, 0, canvas.width, canvas.height);
      for (const f of fishMapRef.current.values()) {
        const sprite = await getTintedSprite(f.breed, f.color_hex);
        f.render(ctx, sprite);
      }
      ctx.fillStyle = 'rgba(245, 158, 11, 0.9)';
      for (const p of pelletsRef.current) {
        ctx.beginPath(); ctx.arc(p.position.x, p.position.y, 3, 0, Math.PI * 2); ctx.fill();
      }
      rafIdRef.current = requestAnimationFrame(tick);
    };
    rafIdRef.current = requestAnimationFrame(tick);

    const onMouseDown = (e: MouseEvent) => {
      const r = canvas.getBoundingClientRect();
      const x = e.clientX - r.left, y = e.clientY - r.top;
      pelletsRef.current.push(new FoodPellet({ x, y }));
      useAquariumStore.getState().addFood(x, y);
    };
    const onMouseMove = (e: MouseEvent) => {
      const r = canvas.getBoundingClientRect();
      const x = e.clientX - r.left, y = e.clientY - r.top;
      let hovered: string | null = null;
      for (const f of fishMapRef.current.values()) {
        const a = f.aabb();
        if (x >= a.x && x <= a.x + a.w && y >= a.y && y <= a.y + a.h) { hovered = f.id; break; }
      }
      const prev = useAquariumStore.getState().hoveredFishId;
      if (prev !== hovered) useAquariumStore.getState().setHovered(hovered);
      for (const f of fishMapRef.current.values()) f.hovered = f.id === hovered;
    };
    canvas.addEventListener('mousedown', onMouseDown);
    canvas.addEventListener('mousemove', onMouseMove);

    return () => {
      cancelAnimationFrame(rafIdRef.current);
      ro.disconnect();
      canvas.removeEventListener('mousedown', onMouseDown);
      canvas.removeEventListener('mousemove', onMouseMove);
    };
  }, []);

  return <canvas ref={canvasRef} className="block w-screen h-screen" aria-hidden="true" />;
}
```

- [ ] **Step 2: Test.**

```tsx
import { describe, it, expect, vi } from 'vitest';
import { render } from '@testing-library/react';
import { AquariumCanvas } from '@/components/aquarium/AquariumCanvas';

describe('AquariumCanvas', () => {
  it('mounts a canvas and starts a RAF', () => {
    const raf = vi.spyOn(globalThis, 'requestAnimationFrame');
    const { container, rerender } = render(<AquariumCanvas fishes={[]} breeds={[]} />);
    expect(container.querySelector('canvas')).not.toBeNull();
    expect(raf).toHaveBeenCalled();
    const callsBefore = raf.mock.calls.length;
    // Parent re-render must not restart the loop.
    rerender(<AquariumCanvas fishes={[]} breeds={[]} />);
    expect(raf.mock.calls.length).toBe(callsBefore);
  });
});
```

- [ ] **Step 3: Commit.**

```bash
git add frontend/src/components/aquarium/AquariumCanvas.tsx frontend/tests/unit/components/aquarium/AquariumCanvas.test.tsx
git commit -m "$(cat <<'EOF'
feat(frontend): add AquariumCanvas — single RAF loop, refs only, reduced-motion + visibility-aware

Per AGENT.md §3: no React state in the loop. Parent re-render does not restart RAF; verified in test.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 21: Frontend — `HoverTooltip`

**Files:**
- Create: `frontend/src/components/aquarium/HoverTooltip.tsx`

- [ ] **Step 1: Implement.**

```tsx
'use client';

import { useAquariumStore } from '@/stores/aquarium-store';
import { useFishesQuery } from '@/hooks/use-fish-queries';
import { useEffect, useState } from 'react';

export function HoverTooltip() {
  const hoveredId = useAquariumStore((s) => s.hoveredFishId);
  const { data } = useFishesQuery();
  const [pos, setPos] = useState<{ x: number; y: number } | null>(null);

  useEffect(() => {
    if (!hoveredId) { setPos(null); return; }
    const onMove = (e: MouseEvent) => setPos({ x: e.clientX, y: e.clientY });
    window.addEventListener('mousemove', onMove);
    return () => window.removeEventListener('mousemove', onMove);
  }, [hoveredId]);

  if (!hoveredId || !pos) return null;
  const fish = data?.data.find((f: any) => f.id === hoveredId);
  if (!fish) return null;

  return (
    <div
      className="pointer-events-none fixed z-50 px-3 py-1 rounded-full bg-white/20 backdrop-blur-md border border-white/20 text-on-surface font-label-caps text-[12px] tracking-[0.1em] uppercase"
      style={{ left: pos.x + 16, top: pos.y + 16 }}
      role="tooltip"
    >
      {fish.nickname}
    </div>
  );
}
```

- [ ] **Step 2: Commit.**

```bash
git add frontend/src/components/aquarium/HoverTooltip.tsx
git commit -m "$(cat <<'EOF'
feat(frontend): add HoverTooltip — React island following the hovered fish (glass-sm, label-caps)

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 22: Frontend — `AddFishDialog` + `EditFishDialog`

**Files:**
- Create: `frontend/src/components/manage/AddFishDialog.tsx`
- Create: `frontend/src/components/manage/EditFishDialog.tsx`
- Create: `frontend/tests/unit/components/manage/AddFishDialog.test.tsx`
- Create: `frontend/tests/unit/components/manage/EditFishDialog.test.tsx`

- [ ] **Step 1: Implement `AddFishDialog`.**

```tsx
'use client';

import * as Dialog from '@radix-ui/react-dialog';
import { useForm, Controller } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useBreedsQuery, useCreateFishMutation } from '@/hooks/use-fish-queries';
import { createFishSchema, type CreateFishInput } from '@/lib/fish/schemas';
import clsx from 'clsx';

export function AddFishDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (b: boolean) => void }) {
  const { data: breeds } = useBreedsQuery();
  const create = useCreateFishMutation();
  const { register, handleSubmit, control, watch, formState: { errors } } = useForm<CreateFishInput>({
    resolver: zodResolver(createFishSchema),
    defaultValues: { nickname: '', breed: 'guppy', color_hex: '#FF6B9D', size: 12 },
  });
  const breedId = watch('breed');
  const breed = breeds?.data.find((b: any) => b.id === breedId);

  const onSubmit = (input: CreateFishInput) => create.mutate(input, { onSuccess: () => onOpenChange(false) });

  return (
    <Dialog.Root open={open} onOpenChange={onOpenChange}>
      <Dialog.Portal>
        <Dialog.Overlay className="fixed inset-0 bg-black/30 backdrop-blur-sm z-50" />
        <Dialog.Content className="fixed left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 z-50 w-[min(90vw,520px)] max-h-[80vh] overflow-y-auto p-8 rounded-xl bg-white/50 backdrop-blur-xl border border-white/20">
          <Dialog.Title className="text-headline-md font-headline-md mb-6">Curate a new fish</Dialog.Title>
          <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
            <div>
              <label className="font-label-caps text-[12px] tracking-[0.1em] uppercase text-on-surface-variant">Breed</label>
              <div className="grid grid-cols-5 gap-2 mt-2">
                {breeds?.data.map((b: any) => (
                  <Controller key={b.id} control={control} name="breed" render={({ field }) => (
                    <button type="button"
                      onClick={() => field.onChange(b.id)}
                      className={clsx('p-2 rounded-lg border', field.value === b.id ? 'ring-2 ring-primary border-transparent' : 'border-outline-variant')}>
                      <img src={`/sprites/fish/${b.id}.svg`} alt={b.label} className="w-12 h-6" />
                    </button>
                  )} />
                ))}
              </div>
            </div>
            <label className="block">
              <span className="font-label-caps text-[12px] tracking-[0.1em] uppercase text-on-surface-variant">Color</span>
              <input type="color" {...register('color_hex')} className="block mt-1 h-8 w-16" />
            </label>
            <label className="block">
              <span className="font-label-caps text-[12px] tracking-[0.1em] uppercase text-on-surface-variant">Size</span>
              <input type="range" min={breed?.min_size ?? 1} max={breed?.max_size ?? 100} {...register('size', { valueAsNumber: true })} className="block w-full mt-1" />
            </label>
            <label className="block">
              <span className="font-label-caps text-[12px] tracking-[0.1em] uppercase text-on-surface-variant">Nickname</span>
              <input type="text" {...register('nickname')} className="block w-full mt-1 bg-white/20 border-0 border-b border-outline-variant py-2 px-3 rounded-t-lg outline-none focus:bg-white/40 focus:border-primary" />
              {errors.nickname && <p className="text-error text-sm mt-1">{errors.nickname.message}</p>}
            </label>
            <div className="flex justify-end gap-2 pt-2">
              <Dialog.Close className="px-6 py-2 rounded-full bg-white/20 border border-white/20 font-label-caps text-[12px] tracking-[0.1em] uppercase">Cancel</Dialog.Close>
              <button type="submit" disabled={create.isPending} className="px-6 py-2 rounded-full bg-primary/30 backdrop-blur-md border border-white/40 text-on-primary-container font-label-caps text-[12px] tracking-[0.1em] uppercase active:scale-95 transition-all">Add fish</button>
            </div>
          </form>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
```

- [ ] **Step 2: Implement `EditFishDialog` (breed is read-only).** Same structure; replace the breed grid with a chip showing the existing breed; submit calls `useUpdateFishMutation`.

- [ ] **Step 3: Tests.** Validate zod errors render; submit calls the mutation; size slider clamps; edit excludes breed in payload.

- [ ] **Step 4: Commit.**

```bash
git add frontend/src/components/manage/Add*.tsx frontend/src/components/manage/Edit*.tsx frontend/tests/unit/components/manage/
git commit -m "$(cat <<'EOF'
feat(frontend): add Radix-backed AddFishDialog + EditFishDialog with rhf+zod and brand styling

Edit dialog enforces immutable breed per SPEC §2.2.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 23: Frontend — `FishManagerModal`

**Files:**
- Create: `frontend/src/components/manage/FishManagerModal.tsx`
- Create: `frontend/src/hooks/use-debounced-value.ts`
- Create: `frontend/tests/unit/components/manage/FishManagerModal.test.tsx`

- [ ] **Step 1: `useDebouncedValue`.**

```ts
import { useEffect, useState } from 'react';
export function useDebouncedValue<T>(value: T, delayMs = 300): T {
  const [v, setV] = useState(value);
  useEffect(() => {
    const id = setTimeout(() => setV(value), delayMs);
    return () => clearTimeout(id);
  }, [value, delayMs]);
  return v;
}
```

- [ ] **Step 2: `FishManagerModal`.**

```tsx
'use client';

import * as Dialog from '@radix-ui/react-dialog';
import { useState } from 'react';
import { useBreedsQuery, useDeleteFishMutation, useFishesQuery } from '@/hooks/use-fish-queries';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { EditFishDialog } from './EditFishDialog';

export function FishManagerModal({ open, onOpenChange }: { open: boolean; onOpenChange: (b: boolean) => void }) {
  const [search, setSearch] = useState('');
  const debounced = useDebouncedValue(search, 300);
  const [breed, setBreed] = useState<string | undefined>();
  const [sort, setSort] = useState<'name'|'breed'|'created_at'|'size'>('created_at');
  const [direction, setDirection] = useState<'asc'|'desc'>('desc');
  const [editingId, setEditingId] = useState<string | null>(null);

  const { data: breeds } = useBreedsQuery();
  const { data } = useFishesQuery({ search: debounced || undefined, breed, sort, direction });
  const del = useDeleteFishMutation();

  return (
    <Dialog.Root open={open} onOpenChange={onOpenChange}>
      <Dialog.Portal>
        <Dialog.Overlay className="fixed inset-0 bg-black/30 backdrop-blur-sm z-40" />
        <Dialog.Content className="fixed left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 z-40 w-[min(95vw,768px)] max-h-[80vh] overflow-y-auto p-8 rounded-xl bg-white/50 backdrop-blur-xl border border-white/20">
          <Dialog.Title className="text-headline-md font-headline-md mb-4">Manage your sanctuary</Dialog.Title>
          <div className="flex gap-3 mb-4">
            <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search nickname"
              className="flex-1 bg-white/20 border-0 border-b border-outline-variant py-2 px-3 rounded-t-lg outline-none focus:bg-white/40 focus:border-primary" />
            <select value={breed ?? ''} onChange={(e) => setBreed(e.target.value || undefined)} className="bg-white/20 rounded-lg px-3 py-2">
              <option value="">All breeds</option>
              {breeds?.data.map((b: any) => <option key={b.id} value={b.id}>{b.label}</option>)}
            </select>
            <select value={`${sort}:${direction}`} onChange={(e) => { const [s,d] = e.target.value.split(':'); setSort(s as any); setDirection(d as any); }} className="bg-white/20 rounded-lg px-3 py-2">
              <option value="created_at:desc">Newest</option>
              <option value="created_at:asc">Oldest</option>
              <option value="name:asc">Name A→Z</option>
              <option value="name:desc">Name Z→A</option>
              <option value="size:asc">Size ↑</option>
              <option value="size:desc">Size ↓</option>
            </select>
          </div>
          <table className="w-full text-left">
            <thead><tr className="font-label-caps text-[12px] tracking-[0.1em] uppercase text-on-surface-variant">
              <th>Nickname</th><th>Breed</th><th>Color</th><th>Size</th><th></th>
            </tr></thead>
            <tbody>
              {data?.data.map((f: any) => (
                <tr key={f.id} className="border-t border-white/20">
                  <td className="py-2">{f.nickname}</td>
                  <td><span className="px-3 py-1 rounded-full bg-secondary-container/30 text-xs uppercase">{f.breed}</span></td>
                  <td><span className="inline-block w-5 h-5 rounded-full border border-white/40" style={{ background: f.color_hex }} /></td>
                  <td className="tabular-nums">{f.size}</td>
                  <td className="text-right">
                    <button onClick={() => setEditingId(f.id)} className="mr-2 text-primary">Edit</button>
                    <button onClick={() => del.mutate(f.id)} className="text-error">Delete</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          <EditFishDialog open={editingId !== null} onOpenChange={(b) => !b && setEditingId(null)} fishId={editingId} />
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
```

- [ ] **Step 3: Tests.** Mount, type into search, advance fake timers by 300 ms, assert the query was called with the debounced value. Click delete → assert mutation called.

- [ ] **Step 4: Commit.**

```bash
git add frontend/src/components/manage/FishManagerModal.tsx frontend/src/hooks/use-debounced-value.ts frontend/tests/unit/components/manage/FishManagerModal.test.tsx
git commit -m "$(cat <<'EOF'
feat(frontend): add FishManagerModal with debounced search, filter, sort, optimistic delete

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 24: Frontend — `/fish` page (Server Component + client tree)

**Files:**
- Create: `frontend/src/app/fish/page.tsx`
- Create: `frontend/src/app/fish/_client.tsx`
- Create: `frontend/src/lib/server-fetch.ts`

- [ ] **Step 1: `server-fetch` helper.**

```ts
import { cookies } from 'next/headers';
import { getIronSession } from 'iron-session';
import type { Session } from '@/lib/session-types';

export async function serverFetch(path: string): Promise<any> {
  const session = await getIronSession<Session>(await cookies(), { /* opts from slice 2 */ } as any);
  const r = await fetch(`${process.env.BACKEND_INTERNAL_URL}${path}`, {
    headers: session.token ? { Authorization: `Bearer ${session.token}`, accept: 'application/json' } : {},
    cache: 'no-store',
  });
  if (!r.ok) return null;
  return r.json();
}
```

- [ ] **Step 2: Page (RSC).**

```tsx
import { HydrationBoundary, dehydrate, QueryClient } from '@tanstack/react-query';
import { serverFetch } from '@/lib/server-fetch';
import { FishPageClient } from './_client';

export default async function FishPage() {
  const qc = new QueryClient();
  const [list, breeds] = await Promise.all([
    serverFetch('/fishes?per_page=100'),
    serverFetch('/fishes/breeds'),
  ]);
  qc.setQueryData(['fishes', 'list', {}], list);
  qc.setQueryData(['fishes', 'breeds'], breeds);
  return (
    <HydrationBoundary state={dehydrate(qc)}>
      <FishPageClient initialEmpty={(list?.data?.length ?? 0) === 0} />
    </HydrationBoundary>
  );
}
```

- [ ] **Step 3: Client tree.**

```tsx
'use client';

import { useState } from 'react';
import { AquariumCanvas } from '@/components/aquarium/AquariumCanvas';
import { HoverTooltip } from '@/components/aquarium/HoverTooltip';
import { FishManagerModal } from '@/components/manage/FishManagerModal';
import { AddFishDialog } from '@/components/manage/AddFishDialog';
import { useBreedsQuery, useFishesQuery } from '@/hooks/use-fish-queries';

export function FishPageClient({ initialEmpty }: { initialEmpty: boolean }) {
  const [manageOpen, setManageOpen] = useState(false);
  const [addOpen, setAddOpen] = useState(initialEmpty);
  const { data: fishes } = useFishesQuery();
  const { data: breeds } = useBreedsQuery();

  return (
    <>
      <AquariumCanvas fishes={fishes?.data ?? []} breeds={breeds?.data ?? []} />
      <HoverTooltip />
      <div className="fixed bottom-6 right-6 flex gap-2 z-10">
        <button onClick={() => setAddOpen(true)} className="px-6 py-3 rounded-full bg-white/20 border border-white/20 font-label-caps text-[12px] tracking-[0.1em] uppercase">Add fish</button>
        <button onClick={() => setManageOpen(true)} className="px-6 py-3 rounded-full bg-primary/30 border border-white/40 text-on-primary-container font-label-caps text-[12px] tracking-[0.1em] uppercase">Manage</button>
      </div>
      <FishManagerModal open={manageOpen} onOpenChange={setManageOpen} />
      <AddFishDialog open={addOpen} onOpenChange={setAddOpen} />
    </>
  );
}
```

- [ ] **Step 4: Commit.**

```bash
git add frontend/src/app/fish/ frontend/src/lib/server-fetch.ts
git commit -m "$(cat <<'EOF'
feat(frontend): wire /fish page — RSC fetches initial fishes+breeds, client mounts canvas+dock

Empty-state opens AddFishDialog by default.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 25: Acceptance verification

- [ ] **Step 1: Stack up + migrate.**

```bash
docker compose up -d db redis backend frontend
sleep 12
docker compose exec backend php artisan migrate --force
```

- [ ] **Step 2: Backend acceptance (curl).**

```bash
# Register a user, capture token.
TOKEN=$(curl -s -X POST http://localhost:8000/api/v1/auth/register \
  -H 'content-type: application/json' \
  -d '{"username":"slice3","email":"s3@a.co","password":"a-strong-pass-123!","password_confirmation":"a-strong-pass-123!"}' \
  | python3 -c 'import json,sys;print(json.load(sys.stdin)["token"])')

# Breeds (public).
curl -s http://localhost:8000/api/v1/fishes/breeds | python3 -c 'import json,sys;print(len(json.load(sys.stdin)["data"]))'
# Expect: 10

# Create a fish.
curl -s -X POST http://localhost:8000/api/v1/fishes -H "Authorization: Bearer $TOKEN" -H 'content-type: application/json' \
  -d '{"nickname":"Blub","breed":"guppy","color_hex":"#FF6B9D","size":12}'
# Expect: 201 + data.source == "manual"

# List.
curl -s http://localhost:8000/api/v1/fishes -H "Authorization: Bearer $TOKEN"
# Expect: data array with 1 entry

# Soft-delete.
FID=$(curl -s http://localhost:8000/api/v1/fishes -H "Authorization: Bearer $TOKEN" | python3 -c 'import json,sys;print(json.load(sys.stdin)["data"][0]["id"])')
curl -s -o /dev/null -w "%{http_code}\n" -X DELETE "http://localhost:8000/api/v1/fishes/$FID" -H "Authorization: Bearer $TOKEN"
# Expect: 204
curl -s http://localhost:8000/api/v1/fishes -H "Authorization: Bearer $TOKEN" | python3 -c 'import json,sys;print(len(json.load(sys.stdin)["data"]))'
# Expect: 0
docker compose exec db psql -U fishbook -d fishbook -c "select count(*) from fishes where deleted_at is not null;"
# Expect: 1
```

- [ ] **Step 3: Backend test suite + coverage.**

```bash
docker compose exec backend ./vendor/bin/pest --coverage --min=80
docker compose exec backend ./vendor/bin/phpstan analyse --memory-limit=512M
docker compose exec backend ./vendor/bin/pint --test
```

- [ ] **Step 4: Frontend tests + build.**

```bash
cd frontend && npm test -- --run --coverage
cd frontend && npm run lint && npm run typecheck && npm run build
```

Expected: ≥70% statements; `Fish` + `useAquariumStore` at 100%.

- [ ] **Step 5: Regen-no-diff.**

```bash
docker compose exec backend php artisan l5-swagger:generate
git diff --exit-code backend/storage/api-docs/openapi.json
cd frontend && npm run generate:api
git -C .. diff --exit-code frontend/src/lib/api-client
```

- [ ] **Step 6: Manual smoke (browser).** Open `http://localhost:3000/login`, register/login, land at `/fish`, click Add Fish, fill the form, submit, watch the fish swim. Hover for tooltip. Click canvas to drop food; fish swims to it and eats. Open Manage; search; delete.

- [ ] **Step 7: Tear down + tag.**

```bash
docker compose down
git tag -a slice-3-fish-canvas -m "Slice 3 — Fish CRUD + Canvas complete"
git log --oneline -30
```

- [ ] **Step 8: No commit needed** (acceptance only). Any small fixes during verification land as their own `fix(...)` / `test(...)` commits *before* the tag.

---

## What's intentionally NOT here

These appear in later slices, per the SPEC and the slice 3 design's §2 "Out" list:

- **Backgrounds** (table, upload, Fal AI generation, `BackgroundLayer`, background panel) → **Slice 5**
- **GitHub-repo aquarium** (`/[username]/[repo]`, `RepoAquariumGenerator`, `source='github_repo'` materialization, "Fork to My Aquarium") → **Slice 4**
- **Playwright E2E** of the full canvas journey (register → add fish → hover → feed → delete) → **Slice 6** polish (the auth E2E that slice 2 deferred lands there too)
- **Sentry instrumentation** (frontend + backend `beforeSend` scrubbers) → **Slice 7**
- **CSP / HSTS / security headers middleware** — finalized in **Slice 7** once all asset sources are known (S3 backgrounds will require the `img-src` allowlist update)
- **Real 100-fish performance profiling** — only a sanity check here; the budget is verified with a synthetic fixture in **Slice 6** polish
- **Swagger UI page** at `/api-docs` → **Slice 7**
- **AR-on-mobile, real-time multiplayer, fish breeding genetics** — never in v1
- **Camera pan/zoom** — `cameraOffset` is typed in the store but never mutated in slice 3; slice 4 or 5 can wire it

If a later task feels like it belongs in slice 3, push back — slice 3 is the canvas + CRUD surface, not the whole aquarium product.
