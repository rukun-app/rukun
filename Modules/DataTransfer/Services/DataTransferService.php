<?php

namespace Modules\DataTransfer\Services;

use App\Models\User;
use Core\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\DataTransfer\DataTransferRegistry;
use Modules\DataTransfer\Enums\TransferStatus;
use Modules\DataTransfer\Jobs\ProcessDataTransfer;
use Modules\DataTransfer\Models\DataTransfer;
use Modules\Files\Models\StoredFile;

class DataTransferService
{
    public function __construct(private DataTransferRegistry $registry) {}

    public function createExport(User $user, string $type, array $options = []): DataTransfer
    {
        $handler = $this->registry->get($type);
        throw_unless($handler->supportsExport(), new \InvalidArgumentException('This data type does not support export.'));

        return $this->create($user, $type, 'export', null, $options);
    }

    public function createImport(User $user, string $type, StoredFile $input, array $options = []): DataTransfer
    {
        $handler = $this->registry->get($type);
        throw_unless($handler->supportsImport(), new \InvalidArgumentException('This data type does not support import.'));
        throw_unless($input->owner_id === $user->getKey(), new \InvalidArgumentException('The input file is unavailable.'));

        return $this->create($user, $type, 'import', $input, $options);
    }

    private function create(User $user, string $type, string $direction, ?StoredFile $input, array $options): DataTransfer
    {
        return DB::transaction(function () use ($user, $type, $direction, $input, $options): DataTransfer {
            $transfer = DataTransfer::query()->create([
                'public_id' => (string) Str::uuid(),
                'user_id' => $user->getKey(),
                'type' => $type,
                'direction' => $direction,
                'status' => TransferStatus::Pending,
                'input_file_id' => $input?->getKey(),
                'options' => $options ?: null,
            ]);

            Audit::record('data_transfer.created', $transfer, ['type' => $type, 'direction' => $direction]);
            ProcessDataTransfer::dispatch($transfer->public_id)->onQueue('low')->afterCommit();

            return $transfer;
        });
    }
}
