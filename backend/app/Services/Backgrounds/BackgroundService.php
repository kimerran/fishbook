<?php

namespace App\Services\Backgrounds;

use App\Jobs\PurgeBackgroundJob;
use App\Models\Background;
use App\Models\User;
use App\Services\Backgrounds\Prompts\PromptDenylist;
use App\Services\FalAi\FalAiClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class BackgroundService
{
    public function __construct(
        private readonly BackgroundImageProcessor $processor,
        private readonly FalAiClient $fal,
        private readonly PromptDenylist $denylist,
    ) {}

    public function upload(User $user, UploadedFile $file): Background
    {
        $r = $this->processor->process($file, $user->id);

        return $this->createAndMaybeActivate($user, [
            'kind' => 'upload', 'storage_key' => $r['storage_key'],
            'width' => $r['width'], 'height' => $r['height'], 'prompt' => null,
        ]);
    }

    public function generate(User $user, string $prompt, string $aspectRatio): Background
    {
        $this->denylist->assertAllowed($prompt);
        $r = $this->fal->generateBackground($prompt, $aspectRatio, $user->id);

        return $this->createAndMaybeActivate($user, [
            'kind' => 'generated', 'storage_key' => $r['storage_key'],
            'width' => $r['width'], 'height' => $r['height'], 'prompt' => $prompt,
        ]);
    }

    public function select(User $user, Background $bg): Background
    {
        return DB::transaction(function () use ($user, $bg) {
            Background::query()->forUser($user->id)->active()->update(['is_active' => false]);
            $bg->forceFill(['is_active' => true])->save();

            return $bg->fresh();
        });
    }

    public function delete(User $user, Background $bg): void
    {
        $bg->delete();
        PurgeBackgroundJob::dispatch($bg->id)->delay(now()->addDays(7));
    }

    /** @param array<string,mixed> $attrs */
    private function createAndMaybeActivate(User $user, array $attrs): Background
    {
        return DB::transaction(function () use ($user, $attrs) {
            $bg = Background::create([...$attrs, 'user_id' => $user->id, 'is_active' => false]);
            $hasActive = Background::query()->forUser($user->id)->active()->exists();
            if (! $hasActive) {
                $bg->forceFill(['is_active' => true])->save();
            }

            return $bg->fresh();
        });
    }
}
