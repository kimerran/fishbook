<?php

namespace Tests\Unit\Services\Github;

use App\Services\Github\BreedAccentMap;
use Tests\TestCase;

class BreedAccentMapTest extends TestCase
{
    public function test_known_language_returns_hex(): void
    {
        $m = app(BreedAccentMap::class);
        $this->assertSame('#3178C6', $m->for('TypeScript'));
        $this->assertSame('#3776AB', $m->for('Python'));
    }

    public function test_unknown_language_returns_null(): void
    {
        $this->assertNull(app(BreedAccentMap::class)->for('Pony'));
    }

    public function test_null_language_returns_null(): void
    {
        $this->assertNull(app(BreedAccentMap::class)->for(null));
    }
}
