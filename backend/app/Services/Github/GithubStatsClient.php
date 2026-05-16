<?php

namespace App\Services\Github;

use App\Exceptions\Github\GithubUnavailableException;
use App\Exceptions\Github\RepoForbiddenException;
use App\Exceptions\Github\RepoNotFoundException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

class GithubStatsClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly Repository $config,
    ) {}

    /**
     * @return array{stars:int,forks:int,issues:int,watchers:int,contributors:int,language:?string,age_days:int,fetched_at:CarbonImmutable}
     */
    public function fetchStats(string $owner, string $repo): array
    {
        $repoBody = $this->callRepo($owner, $repo);
        $contributors = $this->callContributors($owner, $repo);

        $createdAt = CarbonImmutable::parse((string) ($repoBody['created_at'] ?? CarbonImmutable::now()->toIso8601String()));

        return [
            'stars' => (int) ($repoBody['stargazers_count'] ?? 0),
            'forks' => (int) ($repoBody['forks_count'] ?? 0),
            'issues' => (int) ($repoBody['open_issues_count'] ?? 0),
            'watchers' => (int) ($repoBody['subscribers_count'] ?? 0),
            'contributors' => $contributors,
            'language' => isset($repoBody['language']) ? (string) $repoBody['language'] : null,
            'age_days' => (int) round(abs($createdAt->diffInDays(CarbonImmutable::now()))),
            'fetched_at' => CarbonImmutable::now(),
        ];
    }

    /** @return array<string, mixed> */
    private function callRepo(string $owner, string $repo): array
    {
        $r = $this->client()->get("/repos/{$owner}/{$repo}");
        $this->guard($r);
        $body = $r->json();

        return is_array($body) ? $body : [];
    }

    private function callContributors(string $owner, string $repo): int
    {
        $r = $this->client()->get("/repos/{$owner}/{$repo}/contributors", ['per_page' => 1, 'anon' => 'true']);
        $this->guard($r);

        $link = $r->header('Link');
        if (is_string($link) && $link !== '' && preg_match('/page=(\d+)>;\s*rel="last"/', $link, $m)) {
            return (int) $m[1];
        }
        $body = $r->json();

        return is_array($body) ? count($body) : 0;
    }

    private function client(): PendingRequest
    {
        $req = $this->http
            ->baseUrl((string) $this->config->get('services.github.base_url'))
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => (string) $this->config->get('services.github.user_agent'),
            ])
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout(5)
            ->timeout(30)
            ->retry(2, 1000, function ($ex) {
                if ($ex instanceof ConnectionException) {
                    return true;
                }
                if ($ex instanceof RequestException) {
                    return in_array($ex->response->status(), [429, 500, 502, 503, 504], true);
                }

                return false;
            }, throw: false);

        $token = $this->config->get('services.github.token');
        if (is_string($token) && $token !== '') {
            $req = $req->withToken($token);
        }

        return $req;
    }

    private function guard(Response $r): void
    {
        if ($r->status() === 404) {
            throw new RepoNotFoundException;
        }
        if ($r->status() === 403) {
            throw new RepoForbiddenException;
        }
        if ($r->serverError() || $r->status() === 429) {
            Log::warning('github_unavailable', ['status' => $r->status()]);
            throw new GithubUnavailableException("GitHub responded {$r->status()}");
        }
        if (! $r->successful()) {
            throw new GithubUnavailableException("GitHub unexpected status {$r->status()}");
        }
    }
}
