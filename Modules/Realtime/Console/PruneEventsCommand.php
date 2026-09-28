<?php

namespace Modules\Realtime\Console;

use Illuminate\Console\Command;
use Modules\Realtime\Models\UserEvent;

class PruneEventsCommand extends Command
{
    protected $signature = 'realtime:prune-events';

    protected $description = 'Delete expired durable user events';

    public function handle(): int
    {
        $deleted = UserEvent::query()->where('expires_at', '<=', now())->delete();
        $this->components->info("Pruned {$deleted} expired events.");

        return self::SUCCESS;
    }
}
