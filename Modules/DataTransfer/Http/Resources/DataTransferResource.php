<?php

namespace Modules\DataTransfer\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DataTransferResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'type' => $this->type,
            'direction' => $this->direction,
            'format' => $this->direction === 'export' ? ($this->options['format'] ?? 'csv') : ($this->inputFile?->extension ?? 'csv'),
            'status' => $this->status->value,
            'progress' => [
                'total_rows' => $this->total_rows,
                'processed_rows' => $this->processed_rows,
                'successful_rows' => $this->successful_rows,
                'failed_rows' => $this->failed_rows,
            ],
            'input_file_id' => $this->inputFile?->public_id,
            'output_file_id' => $this->outputFile?->public_id,
            'errors' => $this->error_summary ?? [],
            'failure_message' => $this->failure_message,
            'started_at' => $this->started_at?->toISOString(),
            'finished_at' => $this->finished_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
