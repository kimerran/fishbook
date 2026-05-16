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
                'stargazers_count' => 100, 'forks_count' => 5, 'open_issues_count' => 2,
                'subscribers_count' => 3, 'language' => 'Go', 'created_at' => now()->subDays(300)->toISOString(),
            ], 200),
            'api.github.com/repos/race/repo/contributors*' => Http::response([['login' => 'a']], 200),
        ]);

        $this->getJson('/api/v1/repos/race/repo/aquarium')->assertOk();
        // second call hits L1 — no further GitHub calls
        $this->getJson('/api/v1/repos/race/repo/aquarium')->assertOk();

        // exactly 2 GitHub calls happened (one for /repos, one for /contributors); both during the first request
        Http::assertSentCount(2);
    }
}
