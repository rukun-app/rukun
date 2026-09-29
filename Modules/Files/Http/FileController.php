<?php

namespace Modules\Files\Http;

use Core\Audit\Audit;
use Core\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Files\Http\Resources\StoredFileResource;
use Modules\Files\Jobs\DeleteStoredFile;
use Modules\Files\Models\StoredFile;
use Modules\Files\Services\FileStorageService;
use Modules\Settings\Settings;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'mime_type' => ['sometimes', 'string', 'max:150'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $files = StoredFile::query()
            ->whereBelongsTo($request->user(), 'owner')
            ->withCount('attachments')
            ->when($data['search'] ?? null, function ($query, string $search): void {
                $query->where(fn ($query) => $query->where('display_name', 'ilike', '%'.$search.'%')->orWhere('original_name', 'ilike', '%'.$search.'%'));
            })
            ->when($data['mime_type'] ?? null, fn ($query, string $mimeType) => $query->where('mime_type', $mimeType))
            ->when($data['from'] ?? null, fn ($query, string $from) => $query->whereDate('created_at', '>=', $from))
            ->when($data['to'] ?? null, fn ($query, string $to) => $query->whereDate('created_at', '<=', $to))
            ->orderByDesc('id')
            ->cursorPaginate($request->integer('per_page', 20))
            ->withQueryString();

        $files->setCollection(StoredFileResource::collection($files->getCollection())->collection);

        return ApiResponse::success($files);
    }

    public function store(Request $request, Settings $settings, FileStorageService $storage): JsonResponse
    {
        $maxKilobytes = (int) $settings->get('files.max_upload_mb') * 1024;
        $allowedMimeTypes = array_values(array_intersect((array) $settings->get('files.allowed_mime_types'), config('files.allowed_mime_types')));

        if ($allowedMimeTypes === []) {
            throw ValidationException::withMessages(['file' => ['No upload MIME types are currently enabled.']]);
        }

        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.$maxKilobytes, 'mimetypes:'.implode(',', $allowedMimeTypes)],
            'display_name' => ['sometimes', 'string', 'max:255'],
            'metadata' => ['sometimes', 'array', 'max:20'],
            'metadata.*' => ['nullable', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_scalar($value) || (is_string($value) && mb_strlen($value) > 1000)) {
                    $fail("The {$attribute} value must be a scalar value with at most 1000 characters.");
                }
            }],
        ]);

        $file = $storage->store($data['file'], $request->user(), $data['display_name'] ?? null, $data['metadata'] ?? null);

        return ApiResponse::success(new StoredFileResource($file), 201);
    }

    public function show(Request $request, StoredFile $file): JsonResponse
    {
        $request->user()->can('view', $file) || abort(404);

        return ApiResponse::success(new StoredFileResource($file->loadCount('attachments')));
    }

    public function update(Request $request, StoredFile $file): JsonResponse
    {
        $request->user()->can('update', $file) || abort(404);
        $data = $request->validate([
            'display_name' => ['sometimes', 'string', 'max:255'],
            'metadata' => ['sometimes', 'nullable', 'array', 'max:20'],
            'metadata.*' => ['nullable', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_scalar($value) || (is_string($value) && mb_strlen($value) > 1000)) {
                    $fail("The {$attribute} value must be a scalar value with at most 1000 characters.");
                }
            }],
        ]);
        $before = $file->only(array_keys($data));
        $file->update($data);
        Audit::record('file.updated', $file, ['before' => $before, 'after' => $data]);

        return ApiResponse::success(new StoredFileResource($file->fresh()->loadCount('attachments')));
    }

    public function download(Request $request, StoredFile $file): StreamedResponse
    {
        $request->user()->can('download', $file) || abort(404);
        abort_unless(Storage::disk($file->disk)->exists($file->path), 404, __('api.files.content_missing'));

        if (! $request->user()->is($file->owner)) {
            Audit::record('file.downloaded_by_admin', $file);
        }

        return Storage::disk($file->disk)->download($file->path, $file->display_name, [
            'Content-Type' => $file->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, StoredFile $file): JsonResponse
    {
        $request->user()->can('delete', $file) || abort(404);
        abort_if($file->attachments()->exists(), 422, __('api.files.attached'));

        DB::transaction(function () use ($request, $file): void {
            $file = StoredFile::query()->whereKey($file->id)->lockForUpdate()->firstOrFail();
            $request->user()->can('delete', $file) || abort(404);
            abort_if($file->attachments()->exists(), 422, __('api.files.attached'));
            $file->delete();
            Audit::record('file.deleted', $file, ['display_name' => $file->display_name, 'size' => $file->size, 'mime_type' => $file->mime_type]);
            DeleteStoredFile::dispatch($file->public_id, $file->disk, $file->path)->afterCommit();
        });

        return ApiResponse::success(['message' => __('api.files.deletion_scheduled')]);
    }
}
