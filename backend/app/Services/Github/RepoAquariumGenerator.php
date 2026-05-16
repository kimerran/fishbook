<?php

namespace App\Services\Github;

use App\Services\Fish\BreedCatalog;
use Random\Engine\Mt19937;
use Random\Randomizer;

class RepoAquariumGenerator
{
    private const STARS_BPS = [10, 50, 200, 1000, 5000, 20000, 100000];

    private const FORKS_BPS = [5, 25, 100, 500, 2500, 10000];

    private const ISSUES_BPS = [1, 10, 50, 200, 1000];

    private const WATCHERS_BPS = [5, 20, 100, 500, 2500];

    private const CONTRIBUTORS_BPS = [1, 5, 20, 100, 500];

    private const MAX_FISH = 100;

    private const ALLOC_STARS_GUPPY = [1, 2, 3, 4, 5, 6, 7, 8];

    private const ALLOC_STARS_NEON_TETRA = [0, 0, 3, 5, 7, 9, 11, 13];

    private const ALLOC_STARS_MOLLY = [0, 0, 0, 1, 2, 3, 4, 5];

    private const ALLOC_STARS_CHERRY_BARB = [0, 0, 0, 0, 1, 2, 3, 4];

    private const ALLOC_FORKS_ZEBRA_DANIO = [0, 1, 2, 3, 4, 5, 6];

    private const ALLOC_ISSUES_OTOCINCLUS = [0, 1, 2, 3, 4, 5];

    private const ALLOC_WATCHERS_PLATY = [0, 1, 2, 3, 4, 5];

    private const ALLOC_CONTRIBUTORS_ENDLER_CAPS = [0, 3, 6, 10, 15, 20];

    public function __construct(
        private readonly BreedAccentMap $accents,
        private readonly BreedCatalog $breeds,
    ) {}

    /**
     * @param  array<string, mixed>  $stats
     * @return list<array{id:string,breed:string,color_hex:string,size:int,nickname:string,source:string,source_ref:string}>
     */
    public function generate(string $owner, string $repo, array $stats): array
    {
        $seed = crc32($owner.'/'.$repo);
        $rng = new Randomizer(new Mt19937($seed));
        $accent = $this->accents->for(isset($stats['language']) ? (string) $stats['language'] : null);

        /** @var list<string> $plan */
        $plan = [];

        // stars
        $tStars = $this->tier((int) ($stats['stars'] ?? 0), self::STARS_BPS);
        $this->push($plan, 'guppy', self::ALLOC_STARS_GUPPY[$tStars] ?? 8);
        $this->push($plan, 'neon_tetra', self::ALLOC_STARS_NEON_TETRA[$tStars] ?? 13);
        $this->push($plan, 'molly', self::ALLOC_STARS_MOLLY[$tStars] ?? 5);
        $this->push($plan, 'cherry_barb', self::ALLOC_STARS_CHERRY_BARB[$tStars] ?? 4);

        // forks
        $tForks = $this->tier((int) ($stats['forks'] ?? 0), self::FORKS_BPS);
        $this->push($plan, 'zebra_danio', self::ALLOC_FORKS_ZEBRA_DANIO[$tForks] ?? 6);

        // issues
        $tIssues = $this->tier((int) ($stats['issues'] ?? 0), self::ISSUES_BPS);
        $this->push($plan, 'otocinclus', self::ALLOC_ISSUES_OTOCINCLUS[$tIssues] ?? 5);

        // watchers
        $tWatchers = $this->tier((int) ($stats['watchers'] ?? 0), self::WATCHERS_BPS);
        $this->push($plan, 'platy', self::ALLOC_WATCHERS_PLATY[$tWatchers] ?? 5);

        // contributors
        $tContrib = $this->tier((int) ($stats['contributors'] ?? 0), self::CONTRIBUTORS_BPS);
        $cap = self::ALLOC_CONTRIBUTORS_ENDLER_CAPS[$tContrib] ?? 20;
        $this->push($plan, 'endler', min((int) ($stats['contributors'] ?? 0), $cap));

        // age → cory_catfish
        $age = (int) ($stats['age_days'] ?? 0);
        $cory = $age < 180 ? 0 : ($age < 730 ? 1 : ($age < 1825 ? 2 : 3));
        $this->push($plan, 'cory_catfish', $cory);

        if (count($plan) > self::MAX_FISH) {
            $plan = $this->downsample($plan, self::MAX_FISH);
        }

        $out = [];
        foreach ($plan as $index => $breed) {
            $defaults = $this->breeds->find($breed);
            if ($defaults === null) {
                continue;
            }
            $base = (string) $defaults['default_color'];
            $color = $this->color($rng, $base, $accent);
            $size = $rng->getInt((int) $defaults['min_size'], (int) $defaults['max_size']);
            $shortRaw = strtoupper(substr(dechex($rng->getInt(0, 0xFFFFFF)), 0, 3));
            $short = str_pad($shortRaw, 3, '0', STR_PAD_LEFT);

            $out[] = [
                'id' => sprintf('repo-%s-%s-%d', $owner, $repo, $index),
                'breed' => $breed,
                'color_hex' => $color,
                'size' => $size,
                'nickname' => sprintf('%s-%s', (string) $defaults['label'], $short),
                'source' => 'github_repo',
                'source_ref' => "{$owner}/{$repo}",
            ];
        }

        return $out;
    }

    /** @param  array<int, int>  $bps */
    private function tier(int $value, array $bps): int
    {
        foreach ($bps as $i => $bp) {
            if ($value < $bp) {
                return $i;
            }
        }

        return count($bps);
    }

    /** @param  list<string>  $plan */
    private function push(array &$plan, string $breed, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $plan[] = $breed;
        }
    }

    /**
     * @param  list<string>  $plan
     * @return list<string>
     */
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
        if ($accent !== null && $rng->getInt(0, 99) < 30) {
            return $this->blend($base, $accent, 0.5);
        }
        $delta = $rng->getInt(-10, 10);

        return $this->clampHex($base, $delta);
    }

    private function blend(string $a, string $b, float $t): string
    {
        [$ra, $ga, $ba] = sscanf($a, '#%02x%02x%02x') ?? [0, 0, 0];
        [$rb, $gb, $bb] = sscanf($b, '#%02x%02x%02x') ?? [0, 0, 0];

        return sprintf('#%02X%02X%02X',
            (int) ($ra * (1 - $t) + $rb * $t),
            (int) ($ga * (1 - $t) + $gb * $t),
            (int) ($ba * (1 - $t) + $bb * $t),
        );
    }

    private function clampHex(string $hex, int $delta): string
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x') ?? [0, 0, 0];

        return sprintf('#%02X%02X%02X',
            max(0, min(255, $r + $delta)),
            max(0, min(255, $g + $delta)),
            max(0, min(255, $b + $delta)),
        );
    }
}
