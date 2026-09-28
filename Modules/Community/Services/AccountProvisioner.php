<?php

namespace Modules\Community\Services;

use App\Models\User;
use Core\Http\ApiResponse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Community\Models\AccountOperation;
use Modules\Community\Models\Area;

class AccountProvisioner
{
    public function __construct(private ScopeResolver $scopes) {}

    public function execute(User $actor, string $key, array $data, ?User $target = null): AccountOperation
    {
        $kind = $target ? 'recover' : 'provision';
        $area = $target ? $this->scopes->accountArea($target) : Area::query()->where('public_id', $data['area_id'])->where('kind', 'rt')->firstOrFail();
        abort_unless($target ? $this->scopes->recoverable($actor, $target) : $this->scopes->allows($actor, 'accounts.provision', $area), 403);
        $fingerprint = hash('sha256', json_encode([$kind, $target?->public_id, $data], JSON_THROW_ON_ERROR));
        $db = DB::connection('rukun');
        $prefix = config('database.connections.core.prefix');
        try {
            return $db->transaction(function () use ($db, $prefix, $actor, $target, $key, $data, $kind, $area, $fingerprint): AccountOperation {
                $db->table($prefix.'users')->whereIn('id', array_filter([$actor->id, $target?->id]))->orderBy('id')->lockForUpdate()->get();
                $actor->unsetRelation('roles')->unsetRelation('permissions');
                abort_unless($target ? $this->scopes->recoverable($actor, $target) : $this->scopes->allows($actor, 'accounts.provision', $area), 403);
                $existing = AccountOperation::query()->where('actor_id', $actor->id)->where('idempotency_key', $key)->first();
                if ($existing) {
                    if (! hash_equals($existing->fingerprint, $fingerprint)) {
                        throw new HttpResponseException(ApiResponse::error(__('api.errors.idempotency_conflict'), 409, code: 'idempotency.key_reused'));
                    }

                    return $existing;
                }
                $password = Str::password(24);
                if ($target) {
                    $db->table($prefix.'users')->where('id', $target->id)->lockForUpdate()->firstOrFail();
                    abort_unless($this->scopes->recoverable($actor, $target->fresh()), 403);
                    $db->table($prefix.'users')->where('id', $target->id)->update(['password' => Hash::make($password), 'must_change_password' => true, 'remember_token' => Str::random(60), 'updated_at' => now()]);
                    $db->table($prefix.'personal_access_tokens')->where('tokenable_type', $target->getMorphClass())->where('tokenable_id', $target->id)->delete();
                    if ($target->email) {
                        $db->table($prefix.'password_reset_tokens')->where('email', $target->email)->delete();
                    }
                    $id = $target->id;
                    $publicId = $target->public_id;
                } else {
                    $publicId = (string) Str::uuid();
                    $id = $db->table($prefix.'users')->insertGetId(['public_id' => $publicId, 'name' => $data['name'], 'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null, 'password' => Hash::make($password), 'must_change_password' => true, 'status' => 'active', 'locale' => $data['locale'] ?? 'id', 'created_at' => now(), 'updated_at' => now()]);
                    $db->table('account_scopes')->insert(['user_id' => $id, 'area_id' => $area->id, 'created_at' => now(), 'updated_at' => now()]);
                }
                $operation = AccountOperation::query()->create(['actor_id' => $actor->id, 'user_id' => $id, 'kind' => $kind, 'idempotency_key' => $key, 'fingerprint' => $fingerprint, 'expires_at' => now()->addMinutes(15)]);
                // Redis stores ciphertext only. Plaintext is streamed once, never persisted in the DB or audit.
                Cache::store('redis')->put('community:credential:'.$operation->public_id, Crypt::encryptString(json_encode(['user_id' => $publicId, 'initial_password' => $password], JSON_THROW_ON_ERROR)), $operation->expires_at);
                CommunityAudit::record('account.'.$kind, $operation, ['user_public_id' => $publicId, 'area_public_id' => $area?->public_id, 'recovery_method' => $target ? $this->scopes->recoveryMethod($actor, $target) : null], actorId: $actor->id);

                return $operation;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['identifier' => __('community::messages.identifier_taken')]);
        }
    }

    public function credential(User $actor, AccountOperation $operation): string
    {
        return DB::connection('rukun')->transaction(function () use ($actor, $operation): string {
            DB::connection('rukun')->table(config('database.connections.core.prefix').'users')->where('id', $actor->id)->lockForUpdate()->firstOrFail();
            $operation = AccountOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            abort_unless($operation->actor_id === $actor->id, 403);
            $target = User::query()->findOrFail($operation->user_id);
            $allowed = $operation->kind === 'recover' ? $this->scopes->recoverable($actor, $target) : $this->scopes->allows($actor, 'accounts.provision', $this->scopes->accountArea($target));
            abort_unless($allowed, 403);
            $ciphertext = Cache::store('redis')->get('community:credential:'.$operation->public_id);
            if ($operation->downloaded_at || $operation->expires_at->isPast() || ! $ciphertext) {
                throw new HttpResponseException(ApiResponse::error(__('community::messages.credential_expired'), 410, code: 'account.credential_unavailable'));
            }
            // Older recovery outputs must not remain usable after another credential was issued.
            if (AccountOperation::query()->where('user_id', $target->id)->where('id', '>', $operation->id)->exists() || ! $target->must_change_password) {
                throw new HttpResponseException(ApiResponse::error(__('community::messages.credential_expired'), 410, code: 'account.credential_unavailable'));
            }
            $operation->update(['downloaded_at' => now()]);
            CommunityAudit::record('account.credential_downloaded', $operation);

            return Crypt::decryptString($ciphertext);
        });
    }
}
