<?php

namespace Modules\Community\Http;

use App\Models\User;
use Core\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Community\Models\AccountOperation;
use Modules\Community\Services\AccountProvisioner;
use Modules\Identity\Support\LoginIdentifier;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountController
{
    public function store(Request $request, AccountProvisioner $provisioner): JsonResponse
    {
        $request->validate(['email' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:40']]);
        $request->merge(['email' => $request->filled('email') ? mb_strtolower(trim($request->input('email'))) : null, 'phone' => $request->filled('phone') ? LoginIdentifier::phone($request->input('phone')) : null]);
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'email' => ['nullable', 'required_without:phone', 'email', 'max:255'], 'phone' => ['nullable', 'required_without:email', 'string', 'max:20'], 'area_id' => ['required', 'uuid'], 'locale' => ['sometimes', Rule::in(['id', 'en'])]]);

        return $this->result($provisioner->execute($request->user(), $this->key($request), $data), 201);
    }

    public function recover(Request $request, User $user, AccountProvisioner $provisioner): JsonResponse
    {
        return $this->result($provisioner->execute($request->user(), $this->key($request), [], $user), 201);
    }

    public function download(Request $request, AccountOperation $operation, AccountProvisioner $provisioner): StreamedResponse
    {
        $content = $provisioner->credential($request->user(), $operation);

        return response()->streamDownload(function () use ($content): void {
            echo $content;
        }, 'initial-credential.json', ['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function key(Request $request): string
    {
        return validator(['key' => $request->header('Idempotency-Key')], ['key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/']])->validate()['key'];
    }

    private function result(AccountOperation $operation, int $status): JsonResponse
    {
        return ApiResponse::success(['public_id' => $operation->public_id, 'user_id' => User::query()->findOrFail($operation->user_id)->public_id, 'expires_at' => $operation->expires_at->toISOString(), 'credential_url' => route('community.credentials.download', $operation)], $status);
    }
}
