<?php

namespace App\Console\Commands;

use App\Jobs\PurgeBackgroundJob;
use App\Models\Background;
use Illuminate\Console\Command;

class BackgroundsPurgeOrphansCommand extends Command
{
    /** @var string */
    protected $signature = 'backgrounds:purge-orphans';

    /** @var string */
    protected $description = 'Reconcile soft-deleted backgrounds older than 7 days whose purge job did not fire.';

    public function handle(): int
    {
        $count = 0;
        Background::onlyTrashed()
            ->where('deleted_at', '<', now()->subDays(7))
            ->chunkById(100, function ($rows) use (&$count) {
                foreach ($rows as $bg) {
                    PurgeBackgroundJob::dispatch($bg->id);
                    $count++;
                }
            });
        $this->info("Dispatched purge for $count orphans.");

        return self::SUCCESS;
    }
}
