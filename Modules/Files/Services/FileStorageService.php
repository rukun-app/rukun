<?php

namespace Modules\Files\Services;

use App\Models\User;
use Core\Audit\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Files\Models\StoredFile;
use RuntimeException;
use Throwable;

class FileStorageService
{
    public function store(UploadedFile $upload, User $owner, ?string $displayName = null, ?array $metadata = null): StoredFile
    {
        $disk = config('filesystems.default');
        $publicId = (string) Str::uuid();
        $extension = mb_strtolower($upload->guessExtension() ?: $upload->extension());
        $path = 'files/'.now()->format('Y/m').'/'.$publicId.($extension !== '' ? '.'.$extension : '');
        $checksum = hash_file('sha256', $upload->getRealPath());

        if (! Storage::disk($disk)->putFileAs(dirname($path), $upload, basename($path))) {
            throw new RuntimeException('The file could not be stored.');
        }

        try {
            return DB::transaction(function () use ($upload, $owner, $displayName, $metadata, $disk, $publicId, $path, $extension, $checksum): StoredFile {
                $file = StoredFile::query()->create([
                    'public_id' => $publicId,
                    'owner_id' => $owner->id,
                    'disk' => $disk,
                    'path' => $path,
                    'original_name' => $this->safeName($upload->getClientOriginalName()),
                    'display_name' => $this->safeName($displayName ?: $upload->getClientOriginalName()),
                    'mime_type' => $upload->getMimeType() ?: 'application/octet-stream',
                    'extension' => $extension ?: null,
                    'size' => $upload->getSize(),
                    'checksum' => $checksum,
                    'visibility' => 'private',
                    'metadata' => $metadata,
                ]);
                Audit::record('file.uploaded', $file, ['size' => $file->size, 'mime_type' => $file->mime_type]);

                return $file;
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }
    }

    private function safeName(string $name): string
    {
        $normalized = preg_replace('/[\x00-\x1F\x7F]+/u', '', basename(str_replace('\\', '/', $name))) ?: 'file';

        return Str::limit(trim($normalized), 255, '');
    }
}
