<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RepoAquariumCache extends Model
{
    protected $table = 'repo_aquarium_cache';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['owner', 'repo', 'stats_json', 'fish_set_json', 'fetched_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'stats_json' => 'array',
            'fish_set_json' => 'array',
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<RepoAquariumCache>  $q
     * @return Builder<RepoAquariumCache>
     */
    public function scopeNotStale(Builder $q, int $ttlSeconds): Builder
    {
        return $q->where('fetched_at', '>=', now()->subSeconds($ttlSeconds));
    }
}
