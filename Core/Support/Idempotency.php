<?php

namespace Core\Support;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class Idempotency
{
    public function scope(Request $request, string $key): string
    {
        $actor = $request->user()?->getAuthIdentifier() ?? 'guest:'.sha1((string) $request->ip());

        return hash('sha256', $actor.'|'.$request->method().'|'.$request->route()?->uri().'|'.$key);
    }

    public function fingerprint(Request $request): string
    {
        $files = collect($request->allFiles())->map(fn (mixed $file) => $this->fileFingerprint($file))->all();
        $payload = ['input' => $this->sort($request->input()), 'files' => $this->sort($files)];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function fileFingerprint(mixed $file): mixed
    {
        if ($file instanceof UploadedFile) {
            return ['name' => $file->getClientOriginalName(), 'size' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath())];
        }

        return is_array($file) ? array_map(fn (mixed $item) => $this->fileFingerprint($item), $file) : null;
    }

    private function sort(array $values): array
    {
        ksort($values);
        foreach ($values as &$value) {
            if (is_array($value)) {
                $value = $this->sort($value);
            }
        }

        return $values;
    }
}
