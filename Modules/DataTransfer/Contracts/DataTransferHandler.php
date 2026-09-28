<?php

namespace Modules\DataTransfer\Contracts;

use App\Models\User;

interface DataTransferHandler
{
    public function key(): string;

    public function label(): string;

    public function supportsImport(): bool;

    public function supportsExport(): bool;

    /** @return list<string> */
    public function columns(): array;

    /** @return list<string> */
    public function importColumns(): array;

    /** @return iterable<array<string, scalar|null>> */
    public function exportRows(User $actor, array $options): iterable;

    /** @param array<string, string|null> $row */
    public function importRow(User $actor, array $row, array $options): void;
}
