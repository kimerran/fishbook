<?php

namespace App\Models;

use Database\Factories\BackgroundFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $kind
 * @property string $storage_key
 * @property int $width
 * @property int $height
 * @property string|null $prompt
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Background extends Model
{
    /** @use HasFactory<BackgroundFactory> */
    use HasFactory, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'kind', 'storage_key', 'width', 'height', 'prompt', 'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'width' => 'integer', 'height' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<Background>  $q
     * @return Builder<Background>
     */
    public function scopeForUser(Builder $q, int $userId): Builder
    {
        return $q->where('user_id', $userId);
    }

    /**
     * @param  Builder<Background>  $q
     * @return Builder<Background>
     */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }
}
