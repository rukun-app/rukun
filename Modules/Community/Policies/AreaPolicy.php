<?php

namespace Modules\Community\Policies;

use App\Models\User;
use Modules\Community\Models\Area;
use Modules\Community\Services\ScopeResolver;

class AreaPolicy
{
    public function __construct(private ScopeResolver $scopes) {}

    public function view(User $user, Area $area): bool
    {
        return $this->scopes->allows($user, 'areas.view', $area);
    }

    public function update(User $user, Area $area): bool
    {
        return $this->scopes->allows($user, 'areas.manage', $area);
    }

    public function delete(User $user, Area $area): bool
    {
        return $this->update($user, $area);
    }
}
