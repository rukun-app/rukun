<?php

namespace Modules\DataTransfer;

use Modules\DataTransfer\Contracts\DataTransferHandler;

class DataTransferRegistry
{
    /** @var array<string, DataTransferHandler> */
    private array $handlers = [];

    public function register(DataTransferHandler $handler): void
    {
        $this->handlers[$handler->key()] = $handler;
    }

    public function get(string $key): DataTransferHandler
    {
        return $this->handlers[$key] ?? throw new \InvalidArgumentException("Unknown data transfer type: {$key}");
    }

    /** @return list<array{key:string,label:string,import:bool,export:bool,formats:list<string>,columns:list<string>,import_columns:list<string>}> */
    public function metadata(): array
    {
        return array_values(array_map(fn (DataTransferHandler $handler): array => [
            'key' => $handler->key(),
            'label' => $handler->label(),
            'import' => $handler->supportsImport(),
            'export' => $handler->supportsExport(),
            'formats' => ['csv', 'xlsx'],
            'columns' => $handler->columns(),
            'import_columns' => $handler->importColumns(),
        ], $this->handlers));
    }
}
