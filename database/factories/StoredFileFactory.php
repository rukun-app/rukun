<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Files\Models\StoredFile;

/** @extends Factory<StoredFile> */
class StoredFileFactory extends Factory
{
    protected $model = StoredFile::class;

    public function definition(): array
    {
        $publicId = (string) Str::uuid();

        return [
            'public_id' => $publicId,
            'owner_id' => User::factory(),
            'disk' => 'local',
            'path' => 'files/'.now()->format('Y/m').'/'.$publicId.'.txt',
            'original_name' => 'document.txt',
            'display_name' => 'document.txt',
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'size' => 8,
            'checksum' => hash('sha256', 'document'),
            'visibility' => 'private',
            'metadata' => null,
        ];
    }
}
