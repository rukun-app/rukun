<?php

namespace Modules\Realtime\Contracts;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

interface RealtimePublisher
{
    public function publish(User $user, string $type, array $payload, ?Model $resource = null): void;
}
