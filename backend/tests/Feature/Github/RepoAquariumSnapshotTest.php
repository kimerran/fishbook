<?php

namespace Tests\Feature\Github;

use App\Services\Github\RepoAquariumGenerator;
use Tests\TestCase;

class RepoAquariumSnapshotTest extends TestCase
{
    public function test_vercel_nextjs_snapshot_is_pinned(): void
    {
        $statsPath = base_path('tests/fixtures/repo_aquarium/vercel-nextjs-stats.json');
        $fishPath = base_path('tests/fixtures/repo_aquarium/vercel-nextjs-fish.json');
        $stats = json_decode((string) file_get_contents($statsPath), true, flags: JSON_THROW_ON_ERROR);
        $expected = json_decode((string) file_get_contents($fishPath), true, flags: JSON_THROW_ON_ERROR);

        $actual = app(RepoAquariumGenerator::class)->generate('vercel', 'next.js', $stats);
        $this->assertSame($expected, $actual);
    }
}
