<?php

namespace Tests\Unit\Services\Github;

use App\Models\Fish;
use App\Models\RepoAquariumCache;
use App\Models\User;
use App\Services\Github\RepoAquariumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RepoAquariumServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::store('redis')->flush();
    }

    public function test_l1_hit_skips_github(): void
    {
        Cache::store('redis')->put('repo_aquarium:o/r:v1', ['stats' => ['stars' => 1], 'fish_set' => []], 600);
        Http::fake();
        app(RepoAquariumService::class)->getOrGenerate('o', 'r');
        Http::assertNothingSent();
    }

    public function test_l2_hit_skips_github_and_promotes_to_l1(): void
    {
        RepoAquariumCache::create([
            'owner' => 'o', 'repo' => 'r',
            'stats_json' => ['stars' => 5], 'fish_set_json' => [],
            'fetched_at' => now(),
        ]);
        Http::fake();
        app(RepoAquariumService::class)->getOrGenerate('o', 'r');
        Http::assertNothingSent();
        $this->assertNotNull(Cache::store('redis')->get('repo_aquarium:o/r:v1'));
    }

    public function test_l3_writes_both_tiers(): void
    {
        Http::fake([
            'api.github.com/repos/o/r' => Http::response([
                'stargazers_count' => 100, 'forks_count' => 5, 'open_issues_count' => 3,
                'subscribers_count' => 4, 'language' => 'Go', 'created_at' => now()->subDays(400)->toISOString(),
            ], 200),
            'api.github.com/repos/o/r/contributors*' => Http::response([['login' => 'a']], 200),
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
                'stargazers_count' => 50, 'forks_count' => 3, 'open_issues_count' => 2,
                'subscribers_count' => 3, 'language' => 'Go', 'created_at' => now()->subDays(200)->toISOString(),
            ], 200),
            'api.github.com/repos/o/r/contributors*' => Http::response([['login' => 'a']], 200),
        ]);
        $svc = app(RepoAquariumService::class);
        $first = $svc->materializeForUser($user, 'o', 'r');
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
