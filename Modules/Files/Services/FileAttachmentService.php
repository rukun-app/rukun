<?php

namespace Modules\Files\Services;

use App\Models\User;
use Core\Audit\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Files\Models\Attachment;
use Modules\Files\Models\StoredFile;

class FileAttachmentService
{
    public function attach(StoredFile $file, Model $attachable, User $actor, string $collection = 'default', int $sortOrder = 0): Attachment
    {
        if (! preg_match('/^[a-z][a-z0-9_-]{0,99}$/', $collection)) {
            throw ValidationException::withMessages(['collection' => ['The collection format is invalid.']]);
        }

        Gate::forUser($actor)->authorize('update', $attachable);
        Gate::forUser($actor)->authorize('update', $file);

        $attachment = Attachment::query()->firstOrCreate([
            'file_id' => $file->id,
            'attachable_type' => $attachable->getMorphClass(),
            'attachable_id' => $attachable->getKey(),
            'collection' => $collection,
        ], [
            'sort_order' => $sortOrder,
            'created_by' => $actor->id,
        ]);

        if ($attachment->wasRecentlyCreated) {
            Audit::record('file.attached', $file, [
                'attachable_type' => $attachable->getMorphClass(),
                'attachable_id' => $attachable->getKey(),
                'collection' => $collection,
            ]);
        }

        return $attachment;
    }

    public function detach(Attachment $attachment, User $actor): void
    {
        $attachment->loadMissing(['file', 'attachable']);
        Gate::forUser($actor)->authorize('update', $attachment->attachable);
        Gate::forUser($actor)->authorize('update', $attachment->file);

        Audit::record('file.detached', $attachment->file, [
            'attachable_type' => $attachment->attachable_type,
            'attachable_id' => $attachment->attachable_id,
            'collection' => $attachment->collection,
        ]);
        $attachment->delete();
    }
}
