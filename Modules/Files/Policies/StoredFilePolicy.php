<?php

namespace Modules\Files\Policies;

use App\Models\User;
use Modules\Files\Models\StoredFile;

class StoredFilePolicy
{
    public function view(User $user, StoredFile $file): bool
    {
        return $user->is($file->owner) || $user->can('files.view-any');
    }

    public function download(User $user, StoredFile $file): bool
    {
        return $user->is($file->owner) || $user->can('files.download-any');
    }

    public function update(User $user, StoredFile $file): bool
    {
        return $user->is($file->owner) || $user->can('files.update-any');
    }

    public function delete(User $user, StoredFile $file): bool
    {
        return $user->is($file->owner) || $user->can('files.delete-any');
    }
}
