<?php

namespace Modules\Files\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Modules\Files\Models\StoredFile;
use RuntimeException;

class DeleteStoredFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 120, 300];

    public function __construct(
        public string $publicId,
        public string $disk,
        public string $path,
    ) {
        $this->onQueue('low');
    }

    public function handle(): void
    {
        if (Storage::disk($this->disk)->exists($this->path) && ! Storage::disk($this->disk)->delete($this->path)) {
            throw new RuntimeException('The stored object could not be deleted.');
        }

        StoredFile::withTrashed()->where('public_id', $this->publicId)->first()?->forceDelete();
    }
}
