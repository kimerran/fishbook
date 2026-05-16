<?php

namespace Tests\Unit\Services\Github;

use App\Services\Github\RepoAquariumGenerator;
use Tests\TestCase;

class RepoAquariumGeneratorTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
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
        $below = app(RepoAquariumGenerator::class)->generate('a', 'b', $this->stats(['stars' => 9]));
        $above = app(RepoAquariumGenerator::class)->generate('a', 'b', $this->stats(['stars' => 10]));
        $guppyBelow = collect($below)->where('breed', 'guppy')->count();
        $guppyAbove = collect($above)->where('breed', 'guppy')->count();
        $this->assertSame(1, $guppyBelow);
        $this->assertSame(2, $guppyAbove);
    }

    public function test_100_fish_cap(): void
    {
        $out = app(RepoAquariumGenerator::class)->generate('big', 'repo', $this->stats([
            'stars' => 200000, 'forks' => 50000, 'issues' => 5000,
            'watchers' => 10000, 'contributors' => 1000, 'age_days' => 4000,
        ]));
        $this->assertLessThanOrEqual(100, count($out));
    }

    public function test_every_fish_carries_source(): void
    {
        foreach (app(RepoAquariumGenerator::class)->generate('vercel', 'next.js', $this->stats()) as $f) {
            $this->assertSame('github_repo', $f['source']);
            $this->assertSame('vercel/next.js', $f['source_ref']);
            $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/i', $f['color_hex']);
            $this->assertMatchesRegularExpression('/^repo-vercel-next\.js-\d+$/', $f['id']);
        }
    }

    public function test_age_to_cory_catfish_buckets(): void
    {
        $gen = app(RepoAquariumGenerator::class);
        $young = $gen->generate('x', 'y', $this->stats(['age_days' => 30]));
        $mid = $gen->generate('x', 'y', $this->stats(['age_days' => 365]));
        $older = $gen->generate('x', 'y', $this->stats(['age_days' => 1500]));
        $oldest = $gen->generate('x', 'y', $this->stats(['age_days' => 4000]));

        $count = fn ($arr) => collect($arr)->where('breed', 'cory_catfish')->count();
        $this->assertSame(0, $count($young));
        $this->assertSame(1, $count($mid));
        $this->assertSame(2, $count($older));
        $this->assertSame(3, $count($oldest));
    }
}
