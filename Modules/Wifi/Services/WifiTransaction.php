<?php

namespace Modules\Wifi\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Community\Models\Area;
use Modules\Community\Services\SensitiveIdentifier;

class WifiTransaction
{
    public function run(User $actor, Area $area, string $operation, string $key, array $data, callable $authorize, callable $action): array
    {
        return DB::connection('rukun')->transaction(function () use ($actor, $area, $operation, $key, $data, $authorize, $action): array {
            Area::query()->whereKey($area->parent_id ?? $area->id)->lockForUpdate()->firstOrFail();
            DB::connection('rukun')->table(config('database.connections.core.prefix').'users')->where('id', $actor->id)->lockForUpdate()->firstOrFail();
            $actor->refresh();
            $authorize();
            $identity = ['actor_id' => $actor->id, 'operation' => 'wifi.'.$operation, 'request_key' => $key];
            $fingerprint = SensitiveIdentifier::fingerprint(json_encode($data, JSON_THROW_ON_ERROR));
            $previous = DB::connection('rukun')->table('billing_requests')->where($identity)->first();
            if ($previous) {
                abort_unless(hash_equals($previous->fingerprint, $fingerprint), 409, __('api.errors.idempotency_conflict'));

                return json_decode($previous->result, true, flags: JSON_THROW_ON_ERROR);
            }
            $result = $action();
            DB::connection('rukun')->table('billing_requests')->insert([...$identity, 'fingerprint' => $fingerprint, 'result' => json_encode($result, JSON_THROW_ON_ERROR)]);

            return $result;
        }, 3);
    }
}
