<?php

namespace Modules\DataTransfer\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\DataTransfer\Models\DataTransfer;
use Modules\Files\Jobs\DeleteStoredFile;
use Modules\Settings\Settings;

class PruneDataTransfersCommand extends Command
{
    protected $signature = 'data-transfers:prune';

    protected $description = 'Delete expired terminal data transfers and generated export files';

    public function handle(Settings $settings): int
    {
        $cutoff = now()->subDays((int) $settings->get('data_transfers.retention_days'));
        $count = 0;

        DataTransfer::query()
            ->whereIn('status', ['completed', 'failed', 'cancelled'])
            ->where('updated_at', '<', $cutoff)
            ->with('outputFile')
            ->chunkById(100, function ($transfers) use (&$count): void {
                foreach ($transfers as $transfer) {
                    DB::transaction(function () use ($transfer, &$count): void {
                        $output = $transfer->outputFile;
                        $transfer->delete();
                        if ($output && ! $output->trashed()) {
                            $output->delete();
                            DeleteStoredFile::dispatch($output->public_id, $output->disk, $output->path)->afterCommit();
                        }
                        $count++;
                    });
                }
            });

        $this->info("Pruned {$count} data transfer(s).");

        return self::SUCCESS;
    }
}
