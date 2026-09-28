<?php

namespace Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FailedJobsSummaryCommand extends Command
{
    protected $signature = 'queue:failed-summary {--limit=10 : Number of recent failures to display}';

    protected $description = 'Show a safe summary of failed queue jobs without payloads or exception traces';

    public function handle(): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));
        $counts = DB::table(config('queue.failed.table'))->selectRaw('queue, count(*) as failures')->groupBy('queue')->orderBy('queue')->get();
        $recent = DB::table(config('queue.failed.table'))->latest('failed_at')->limit($limit)->get(['uuid', 'connection', 'queue', 'failed_at']);

        $this->components->info('Failed jobs: '.$counts->sum('failures'));
        $this->table(['Queue', 'Failures'], $counts->map(fn ($row) => [$row->queue, $row->failures]));
        $this->table(['UUID', 'Connection', 'Queue', 'Failed at'], $recent->map(fn ($row) => [$row->uuid, $row->connection, $row->queue, $row->failed_at]));

        return self::SUCCESS;
    }
}
