<?php

namespace App\Jobs;

use App\Models\Background;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PurgeBackgroundJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $backgroundId) {}

    public function handle(): void
    {
        $bg = Background::withTrashed()->find($this->backgroundId);
        if (! $bg) {
            return;
        }
        if ($bg->deleted_at === null) {
            return; // restore race
        }

        Storage::disk('s3')->delete($bg->storage_key);
        Log::info('background.purged', ['background_id' => $bg->id]);
    }
}
