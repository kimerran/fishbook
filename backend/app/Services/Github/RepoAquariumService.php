<?php

namespace App\Services\Github;

use App\Models\Fish;
use App\Models\RepoAquariumCache;
use App\Models\User;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RepoAquariumService
{
    /** @var list<string> */
    private const RESERVED = ['login', 'register', 'fish', 'onboarding', 'api-docs', 'api', '_next', 'auth', 'admin'];

    public function __construct(
        private readonly CacheFactory $cacheFactory,
        private readonly GithubStatsClient $client,
        private readonly RepoAquariumGenerator $generator,
        private readonly Config $config,
    ) {}

    public static function isReservedRoute(string $owner): bool
    {
        return in_array(strtolower($owner), self::RESERVED, true);
    }

    /** @return array{stats: array<string, mixed>, fish_set: array<int, array<string, mixed>>} */
    public function getOrGenerate(string $owner, string $repo): array
    {
        $key = $this->cacheKey($owner, $repo);
        $ttl = (int) $this->config->get('services.github.cache_ttl_seconds', 600);
        $lockK = "repo_aquarium_lock:{$owner}/{$repo}";
        $lockT = (int) $this->config->get('services.github.lock_ttl_seconds', 60);
        $block = (int) $this->config->get('services.github.lock_block_seconds', 5);

        if (($hit = $this->cache()->get($key)) !== null) {
            return $this->mark($hit, 'redis');
        }
        if (($hit = $this->fromDb($owner, $repo, $ttl, $key)) !== null) {
            return $this->mark($hit, 'db');
        }

        $store = $this->cache()->getStore();
        if (! $store instanceof LockProvider) {
            // Cache store doesn't support locks (e.g. array store fallback).
            return $this->fetchAndStore($owner, $repo, $ttl, $key);
        }

        try {
            return $store->lock($lockK, $lockT)->block($block, function () use ($owner, $repo, $ttl, $key) {
                if (($hit = $this->cache()->get($key)) !== null) {
                    return $this->mark($hit, 'redis');
                }
                if (($hit = $this->fromDb($owner, $repo, $ttl, $key)) !== null) {
                    return $this->mark($hit, 'db');
                }

                return $this->fetchAndStore($owner, $repo, $ttl, $key);
            });
        } catch (LockTimeoutException) {
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

        if ($exists) {
            return ['added' => 0];
        }

        $now = now();
        $rows = array_map(fn (array $f) => [
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'user_id' => $user->id,
            'nickname' => $f['nickname'],
            'breed' => $f['breed'],
            'color_hex' => $f['color_hex'],
            'size' => $f['size'],
            'source' => 'github_repo',
            'source_ref' => $sourceRef,
            'created_at' => $now,
            'updated_at' => $now,
        ], $payload['fish_set']);

        if (count($rows) === 0) {
            return ['added' => 0];
        }

        DB::transaction(fn () => Fish::insert($rows));

        return ['added' => count($rows)];
    }

    private function cache(): CacheRepository
    {
        /** @var CacheRepository $store */
        $store = $this->cacheFactory->store('redis');

        return $store;
    }

    private function cacheKey(string $owner, string $repo): string
    {
        $v = (string) $this->config->get('services.github.cache_key_version', 'v1');

        return "repo_aquarium:{$owner}/{$repo}:{$v}";
    }

    /** @return array{stats: array<string, mixed>, fish_set: array<int, array<string, mixed>>}|null */
    private function fromDb(string $owner, string $repo, int $ttl, string $key): ?array
    {
        $row = RepoAquariumCache::query()
            ->where('owner', $owner)->where('repo', $repo)
            ->notStale($ttl)
            ->first();
        if (! $row) {
            return null;
        }
        /** @var array<string, mixed> $stats */
        $stats = $row->stats_json;
        /** @var array<int, array<string, mixed>> $fish */
        $fish = $row->fish_set_json;
        $payload = ['stats' => $stats, 'fish_set' => $fish];
        $this->cache()->put($key, $payload, $ttl);

        return $payload;
    }

    /** @return array{stats: array<string, mixed>, fish_set: array<int, array<string, mixed>>} */
    private function fetchAndStore(string $owner, string $repo, int $ttl, string $key): array
    {
        $stats = $this->client->fetchStats($owner, $repo);
        $statsArr = array_map(
            fn ($v) => $v instanceof \DateTimeInterface ? $v->format(\DateTimeInterface::ATOM) : $v,
            $stats,
        );
        $fish = $this->generator->generate($owner, $repo, $stats);

        RepoAquariumCache::updateOrCreate(
            ['owner' => $owner, 'repo' => $repo],
            ['stats_json' => $statsArr, 'fish_set_json' => $fish, 'fetched_at' => now()],
        );
        $payload = ['stats' => $statsArr, 'fish_set' => $fish];
        $this->cache()->put($key, $payload, $ttl);

        return $this->mark($payload, 'fresh');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function mark(array $payload, string $via): array
    {
        Log::debug('repo_aquarium_cached_via', ['via' => $via]);

        return $payload;
    }
}
