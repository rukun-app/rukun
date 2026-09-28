<?php

namespace Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Modules\Settings\Settings;

class PruneFailedJobsCommand extends Command
{
    protected $signature = 'core:prune-failed-jobs';

    protected $description = 'Prune failed jobs using the configured retention period';

    public function handle(Settings $settings): int
    {
        $hours = (int) $settings->get('ops.failed_job_retention_hours');
        Artisan::call('queue:prune-failed', ['--hours' => $hours]);
        $this->components->info("Pruned failed jobs older than {$hours} hours.");

        return self::SUCCESS;
    }
}
