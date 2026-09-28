<?php

namespace Modules\DataTransfer\Contracts;

use App\Models\User;
use Modules\Files\Models\StoredFile;

interface AuthorizesDataTransfer
{
    /** Validate options and current authorization at submission and during processing. */
    public function protectExport(StoredFile $file, array $options): void;

    public function authorize(User $actor, string $direction, array $options): void;
}
