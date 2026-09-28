<?php

namespace Modules\Community\Http;

use App\Models\User;
use Core\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Community\Database\Seeders\CommunitySeeder;
use Modules\Community\Models\Area;
use Modules\Community\Models\RoleAssignment;
use Modules\Community\Services\CommunityAudit;
use Spatie\Permission\Models\Role;

class RoleAssignmentController
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return ApiResponse::success(RoleAssignment::query()->orderBy('public_id')->cursorPaginate($request->integer('per_page', 20))->through(fn ($assignment) => $this->data($assignment)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'uuid'], 'role' => ['required', 'string', 'max:100'], 'scope_type' => ['required', 'in:global,rw,rt'], 'area_id' => ['nullable', 'uuid', 'required_unless:scope_type,global', 'prohibited_if:scope_type,global'], 'starts_at' => ['required', 'date'], 'ends_at' => ['nullable', 'date', 'after:starts_at']]);
        $user = User::query()->where('public_id', $data['user_id'])->firstOrFail();
        $role = Role::query()->where('name', $data['role'])->where('guard_name', 'web')->firstOrFail();
        // Foundation permissions remain global and must never be granted through a scoped role.
        abort_if($role->permissions()->whereNotIn('name', CommunitySeeder::PERMISSIONS)->exists(), 422, __('community::messages.invalid_scoped_role'));
        $area = isset($data['area_id']) ? Area::query()->where('public_id', $data['area_id'])->where('kind', $data['scope_type'])->firstOrFail() : null;
        $assignment = DB::connection('rukun')->transaction(function () use ($request, $data, $user, $role, $area) {
            DB::connection('rukun')->table(config('database.connections.core.prefix').'users')->where('id', $user->id)->lockForUpdate()->firstOrFail();
            $assignment = RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => $role->id, 'scope_type' => $data['scope_type'], 'area_id' => $area?->id, 'starts_at' => Carbon::parse($data['starts_at'])->setTimezone(config('app.timezone')), 'ends_at' => isset($data['ends_at']) ? Carbon::parse($data['ends_at'])->setTimezone(config('app.timezone')) : null, 'assigned_by' => $request->user()->id]);
            CommunityAudit::record('role_assignment.created', $assignment, ['role' => $role->name, 'scope_type' => $data['scope_type'], 'area_public_id' => $area?->public_id]);

            return $assignment;
        });

        return ApiResponse::success($this->data($assignment), 201);
    }

    public function destroy(RoleAssignment $assignment): JsonResponse
    {
        DB::connection('rukun')->transaction(function () use ($assignment): void {
            DB::connection('rukun')->table(config('database.connections.core.prefix').'users')->where('id', $assignment->user_id)->lockForUpdate()->firstOrFail();
            $assignment = RoleAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            if ($assignment->status !== 'revoked') {
                $assignment->update(['status' => 'revoked']);
                CommunityAudit::record('role_assignment.revoked', $assignment);
            }
        });

        return ApiResponse::success(['revoked' => true]);
    }

    private function data(RoleAssignment $assignment): array
    {
        return ['public_id' => $assignment->public_id, 'user_id' => User::query()->findOrFail($assignment->user_id)->public_id, 'role' => Role::query()->findOrFail($assignment->role_id)->name, 'scope_type' => $assignment->scope_type, 'area_id' => $assignment->area_id ? Area::query()->findOrFail($assignment->area_id)->public_id : null, 'starts_at' => $assignment->starts_at->toISOString(), 'ends_at' => $assignment->ends_at?->toISOString(), 'status' => $assignment->status];
    }
}
