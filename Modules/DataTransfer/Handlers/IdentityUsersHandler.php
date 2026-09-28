<?php

namespace Modules\DataTransfer\Handlers;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\DataTransfer\Contracts\DataTransferHandler;

class IdentityUsersHandler implements DataTransferHandler
{
    public function key(): string
    {
        return 'identity.users';
    }

    public function label(): string
    {
        return 'Identity users';
    }

    public function supportsImport(): bool
    {
        return true;
    }

    public function supportsExport(): bool
    {
        return true;
    }

    public function columns(): array
    {
        return ['name', 'email', 'status', 'locale', 'email_verified_at', 'created_at'];
    }

    public function importColumns(): array
    {
        return ['name', 'email', 'status', 'locale'];
    }

    public function exportRows(User $actor, array $options): iterable
    {
        foreach (User::query()->orderBy('id')->cursor() as $user) {
            yield [
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status->value,
                'locale' => $user->locale,
                'email_verified_at' => $user->email_verified_at?->toISOString(),
                'created_at' => $user->created_at?->toISOString(),
            ];
        }
    }

    public function importRow(User $actor, array $row, array $options): void
    {
        $data = validator($row, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'status' => ['nullable', 'in:active,suspended'],
            'locale' => ['nullable', 'in:en,id'],
        ])->validate();

        $existing = User::query()->where('email', mb_strtolower($data['email']))->first();
        if ($existing?->hasRole('super-admin')) {
            throw ValidationException::withMessages(['email' => ['The super administrator cannot be changed through import.']]);
        }

        User::query()->updateOrCreate(
            ['email' => mb_strtolower($data['email'])],
            [
                'name' => $data['name'],
                'status' => $data['status'] ?? UserStatus::Active->value,
                'locale' => $data['locale'] ?? config('app.locale'),
                'email_verified_at' => $existing?->email_verified_at ?? now(),
                'password' => $existing?->password ?? Hash::make(Str::password(32)),
            ],
        );
    }
}
