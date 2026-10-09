<?php

namespace Modules\DataTransfer\Http;

use Core\Audit\Audit;
use Core\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\DataTransfer\DataTransferRegistry;
use Modules\DataTransfer\Enums\TransferStatus;
use Modules\DataTransfer\Http\Resources\DataTransferResource;
use Modules\DataTransfer\Models\DataTransfer;
use Modules\DataTransfer\Services\DataTransferService;
use Modules\Files\Models\StoredFile;
use Modules\Realtime\Contracts\RealtimePublisher;

class DataTransferController
{
    public function types(DataTransferRegistry $registry): JsonResponse
    {
        return ApiResponse::success(['types' => $registry->metadata()]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'direction' => ['sometimes', Rule::in(['import', 'export'])],
            'status' => ['sometimes', Rule::enum(TransferStatus::class)],
            'type' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $transfers = DataTransfer::query()
            ->where('user_id', $request->user()->getKey())
            ->with(['inputFile', 'outputFile'])
            ->when($data['direction'] ?? null, fn ($query, string $value) => $query->where('direction', $value))
            ->when($data['status'] ?? null, fn ($query, string $value) => $query->where('status', $value))
            ->when($data['type'] ?? null, fn ($query, string $value) => $query->where('type', $value))
            ->orderByDesc('id')
            ->collectionPaginate($request->integer('per_page', 20))
            ->withQueryString();
        $transfers->setCollection(DataTransferResource::collection($transfers->getCollection())->collection);

        return ApiResponse::success($transfers);
    }

    public function export(Request $request, DataTransferRegistry $registry, DataTransferService $service): JsonResponse
    {
        $this->validateIdempotencyKey($request);
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(collect($registry->metadata())->where('export', true)->pluck('key')->all())],
            'format' => ['sometimes', Rule::in(['csv', 'xlsx'])],
            'options' => ['sometimes', 'array', 'max:20'],
        ]);

        $options = $data['options'] ?? [];
        $options['format'] = $data['format'] ?? 'csv';

        return ApiResponse::success(new DataTransferResource($service->createExport($request->user(), $data['type'], $options)), 202);
    }

    public function import(Request $request, DataTransferRegistry $registry, DataTransferService $service): JsonResponse
    {
        $this->validateIdempotencyKey($request);
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(collect($registry->metadata())->where('import', true)->pluck('key')->all())],
            'file_id' => ['required', 'uuid'],
            'options' => ['sometimes', 'array', 'max:20'],
        ]);
        $file = StoredFile::query()->where('public_id', $data['file_id'])->where('owner_id', $request->user()->getKey())->first();
        if (! $file || ! in_array($file->mime_type, ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], true)) {
            throw ValidationException::withMessages(['file_id' => [__('data-transfer::messages.invalid_input_file')]]);
        }

        return ApiResponse::success(new DataTransferResource($service->createImport($request->user(), $data['type'], $file, $data['options'] ?? [])), 202);
    }

    public function show(Request $request, DataTransfer $dataTransfer): JsonResponse
    {
        abort_unless($dataTransfer->user_id === $request->user()->getKey() || $request->user()->can('data-transfers.view-any'), 404);

        return ApiResponse::success(new DataTransferResource($dataTransfer->load(['inputFile', 'outputFile'])));
    }

    public function cancel(Request $request, DataTransfer $dataTransfer, RealtimePublisher $realtime): JsonResponse
    {
        abort_unless($dataTransfer->user_id === $request->user()->getKey() || $request->user()->can('data-transfers.cancel-any'), 404);
        if ($dataTransfer->status->terminal()) {
            return ApiResponse::error(__('data-transfer::messages.cannot_cancel'), 409, [], 'data_transfer.cannot_cancel');
        }
        $dataTransfer->update(['status' => TransferStatus::Cancelled, 'finished_at' => now()]);
        Audit::record('data_transfer.cancelled', $dataTransfer);
        $realtime->publish($dataTransfer->user, 'data_transfer.updated', [
            'transfer_id' => $dataTransfer->public_id,
            'direction' => $dataTransfer->direction,
            'status' => TransferStatus::Cancelled->value,
            'processed_rows' => $dataTransfer->processed_rows,
            'total_rows' => $dataTransfer->total_rows,
        ], $dataTransfer);

        return ApiResponse::success(new DataTransferResource($dataTransfer->fresh()->load(['inputFile', 'outputFile'])));
    }

    private function validateIdempotencyKey(Request $request): void
    {
        Validator::make(
            ['idempotency_key' => $request->header('Idempotency-Key')],
            ['idempotency_key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/']],
        )->validate();
    }
}
