<?php

namespace Modules\Community\Http;

use Core\Http\ApiResponse;
use Core\Http\Middleware\EnsureIdempotency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Community\Handlers\PopulationHandler;
use Modules\Community\Models\AccountOperation;
use Modules\Community\Models\Resident;
use Modules\DataTransfer\Http\Resources\DataTransferResource;
use Modules\DataTransfer\Models\DataTransfer;
use Modules\DataTransfer\Services\DataTransferService;
use Modules\Files\Models\StoredFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PopulationTransferController
{
    public function store(Request $request, PopulationHandler $handler, DataTransferService $service): Response
    {
        $import = $request->route('direction') === 'imports';
        $data = $request->validate(['area_id' => ['required', 'uuid'], 'format' => ['sometimes', 'in:csv,xlsx'], 'file_id' => [$import ? 'required' : 'prohibited', 'uuid']]);
        validator(['key' => $request->header('Idempotency-Key')], ['key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/']])->validate();
        $options = ['area_id' => $data['area_id'], 'format' => $data['format'] ?? 'csv'];
        $handler->authorize($request->user(), $import ? 'import' : 'export', $options);
        $file = null;
        if ($import) {
            $file = StoredFile::query()->where('public_id', $data['file_id'])->where('owner_id', $request->user()->id)->firstOrFail();
            abort_unless(in_array($file->mime_type, ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], true), 422);
        }

        return app(EnsureIdempotency::class)->handle($request, fn () => ApiResponse::success(new DataTransferResource($import ? $service->createImport($request->user(), $handler->key(), $file, $options) : $service->createExport($request->user(), $handler->key(), $options)), 202));
    }

    public function results(Request $request, DataTransfer $transfer, PopulationHandler $handler): JsonResponse
    {
        $this->authorize($request, $transfer, $handler);
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $results = DB::connection('rukun')->table('population_import_results')->where('transfer_id', $transfer->public_id)->orderBy('id')->collectionPaginate($request->integer('per_page', 20));

        return ApiResponse::success($results->through(function ($row) {
            $operation = $row->operation_id ? AccountOperation::query()->find($row->operation_id) : null;

            return ['id' => $row->id, 'resident_id' => Resident::query()->findOrFail($row->resident_id)->public_id, 'operation_id' => $operation?->public_id, 'credential_url' => $operation ? route('community.credentials.download', $operation) : null, 'expires_at' => $operation?->expires_at->toISOString()];
        }));
    }

    public function errors(Request $request, DataTransfer $transfer, PopulationHandler $handler): StreamedResponse
    {
        $this->authorize($request, $transfer, $handler);

        return response()->streamDownload(function () use ($transfer): void {
            $stream = fopen('php://output', 'wb');
            fputcsv($stream, ['row', 'field', 'message'], escape: '');
            foreach ($transfer->error_summary ?? [] as $error) {
                foreach ($error['errors'] as $field => $messages) {
                    foreach ((array) $messages as $message) {
                        fputcsv($stream, [$error['row'], $this->safeCell($field), $this->safeCell($message)], escape: '');
                    }
                }
            }
            fclose($stream);
        }, 'population-errors.csv', ['Content-Type' => 'text/csv', 'Cache-Control' => 'private, no-store']);
    }

    private function authorize(Request $request, DataTransfer $transfer, PopulationHandler $handler): void
    {
        abort_unless($transfer->type === $handler->key() && $transfer->user_id === $request->user()->id, 404);
        $handler->authorize($request->user(), $transfer->direction, $transfer->options ?? []);
    }

    private function safeCell(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) ? "'".$value : $value;
    }
}
