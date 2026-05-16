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
                'stargazers_count' => 116000,
                'forks_count' => 25000,
                'open_issues_count' => 2400,
                'subscribers_count' => 750,
                'language' => 'TypeScript',
                'created_at' => now()->subDays(2900)->toISOString(),
            ], 200),
            'api.github.com/repos/vercel/next.js/contributors*' => Http::response(
                [['login' => 'a']],
                200,
                ['Link' => '<https://api.github.com/repositories/...?per_page=1&page=480>; rel="last"']
            ),
        ]);

        $stats = app(GithubStatsClient::class)->fetchStats('vercel', 'next.js');

        $this->assertSame(116000, $stats['stars']);
        $this->assertSame(25000, $stats['forks']);
        $this->assertSame(2400, $stats['issues']);
        $this->assertSame(750, $stats['watchers']);
        $this->assertSame(480, $stats['contributors']);
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
            'stargazers_count' => 0, 'forks_count' => 0, 'open_issues_count' => 0, 'subscribers_count' => 0,
            'language' => null, 'created_at' => now()->toISOString(),
        ], 200)]);
        app(GithubStatsClient::class)->fetchStats('o', 'r');
        Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'Bearer ghp_TESTTOKEN'));
    }

    public function test_no_bearer_header_when_token_absent(): void
    {
        config()->set('services.github.token', null);
        Http::fake(['api.github.com/*' => Http::response([
            'stargazers_count' => 0, 'forks_count' => 0, 'open_issues_count' => 0, 'subscribers_count' => 0,
            'language' => null, 'created_at' => now()->toISOString(),
        ], 200)]);
        app(GithubStatsClient::class)->fetchStats('o', 'r');
        Http::assertSent(fn ($req) => ! $req->hasHeader('Authorization'));
    }

    public function test_token_not_in_logs(): void
    {
        config()->set('services.github.token', 'ghp_SECRETLEAK');
        $handler = new TestHandler;
        Log::getLogger()->pushHandler($handler);

        Http::fake(['api.github.com/*' => Http::response([
            'stargazers_count' => 0, 'forks_count' => 0, 'open_issues_count' => 0, 'subscribers_count' => 0,
            'language' => null, 'created_at' => now()->toISOString(),
        ], 200)]);
        app(GithubStatsClient::class)->fetchStats('o', 'r');

        foreach ($handler->getRecords() as $rec) {
            $encoded = json_encode($rec);
            $this->assertIsString($encoded);
            $this->assertStringNotContainsString('ghp_SECRETLEAK', $encoded);
            $this->assertStringNotContainsString('Bearer ', $encoded);
        }
    }
}
