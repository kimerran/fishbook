<?php

namespace Tests\Feature\Github;

use App\Models\Fish;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ForkRepoTest extends TestCase
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
                'stargazers_count' => 1000, 'forks_count' => 50, 'open_issues_count' => 10,
                'subscribers_count' => 30, 'language' => 'TypeScript',
                'created_at' => now()->subDays(900)->toISOString(),
            ], 200),
            'api.github.com/repos/vercel/next.js/contributors*' => Http::response([['login' => 'a']], 200,
                ['Link' => '<...page=15>; rel="last"']),
        ]);
    }

    public function test_unauthed_401(): void
    {
        $this->fakeRepo();
        $this->postJson('/api/v1/repos/vercel/next.js/fork-to-my-aquarium')->assertUnauthorized();
    }

    public function test_first_fork_adds_n_fish(): void
    {
        $this->fakeRepo();
        $u = User::factory()->create();
        Sanctum::actingAs($u);

        $r = $this->postJson('/api/v1/repos/vercel/next.js/fork-to-my-aquarium')->assertCreated();
        $added = (int) $r->json('added');
        $this->assertGreaterThan(0, $added);
        $this->assertSame($added, Fish::where('user_id', $u->id)->count());
        $this->assertTrue(Fish::where('user_id', $u->id)
            ->where('source', 'github_repo')
            ->where('source_ref', 'vercel/next.js')
            ->exists());
    }

    public function test_second_fork_is_idempotent(): void
    {
        $this->fakeRepo();
        $u = User::factory()->create();
        Sanctum::actingAs($u);
        $this->postJson('/api/v1/repos/vercel/next.js/fork-to-my-aquarium')->assertCreated();
        $count = Fish::where('user_id', $u->id)->count();
        $this->postJson('/api/v1/repos/vercel/next.js/fork-to-my-aquarium')
            ->assertCreated()
            ->assertJson(['added' => 0]);
        $this->assertSame($count, Fish::where('user_id', $u->id)->count());
    }
}
