<?php

namespace Tests\Feature\Github;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GetRepoAquariumTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::store('redis')->flush();
    }

    private function fakeRepo(): void
    {
        Http::fake([
            'api.github.com/repos/vercel/next.js' => Http::response([
                'stargazers_count' => 116000, 'forks_count' => 25000, 'open_issues_count' => 2400,
                'subscribers_count' => 750, 'language' => 'TypeScript',
                'created_at' => now()->subDays(2900)->toISOString(),
            ], 200),
            'api.github.com/repos/vercel/next.js/contributors*' => Http::response([['login' => 'a']], 200,
                ['Link' => '<...page=480>; rel="last"']),
        ]);
    }

    public function test_happy_path_returns_stats_and_fish_set(): void
    {
        $this->fakeRepo();
        $this->getJson('/api/v1/repos/vercel/next.js/aquarium')
            ->assertOk()
            ->assertJsonStructure(['data' => ['stats' => ['stars', 'forks', 'issues', 'watchers', 'contributors', 'language', 'age_days', 'fetched_at'], 'fish_set']]);
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
        $this->getJson('/api/v1/repos/no/such-repo/aquarium')->assertNotFound();
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
