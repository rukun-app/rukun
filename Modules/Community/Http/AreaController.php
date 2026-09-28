<?php

namespace Modules\Community\Http;

use Core\Http\ApiResponse;
use Core\Http\Middleware\EnsureIdempotency;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Community\Models\Area;
use Modules\Community\Services\CommunityAudit;
use Modules\Community\Services\ScopeResolver;
use Symfony\Component\HttpFoundation\Response;

class AreaController
{
    public function index(Request $request, ScopeResolver $scopes): JsonResponse
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return ApiResponse::success($scopes->constrain(Area::query()->with('parent'), $request->user(), 'areas.view')->orderBy('public_id')->cursorPaginate($request->integer('per_page', 20))->through(fn ($area) => $this->data($area)));
    }

    public function show(Area $area): JsonResponse
    {
        Gate::authorize('view', $area);

        return ApiResponse::success($this->data($area));
    }

    public function store(Request $request, ScopeResolver $scopes): Response
    {
        $data = $request->validate(['kind' => ['required', 'in:rw,rt'], 'parent_id' => ['nullable', 'uuid', 'required_if:kind,rt', 'prohibited_if:kind,rw'], 'code' => ['required', 'string', 'max:20'], 'name' => ['required', 'string', 'max:100']]);
        $parent = isset($data['parent_id']) ? Area::query()->where('public_id', $data['parent_id'])->where('kind', 'rw')->firstOrFail() : null;
        abort_unless($scopes->allows($request->user(), 'areas.manage', $parent), 403);

        return app(EnsureIdempotency::class)->handle($request, function () use ($data, $parent) {
            try {
                $area = DB::connection('rukun')->transaction(function () use ($data, $parent) {
                    $area = Area::query()->create([...$data, 'parent_id' => $parent?->id]);
                    CommunityAudit::record('area.created', $area);

                    return $area;
                });
            } catch (QueryException $exception) {
                if ($exception->getCode() !== '23505') {
                    throw $exception;
                }

                return ApiResponse::error(__('community::messages.area_conflict'), 409, code: 'area.conflict');
            }

            return ApiResponse::success($this->data($area), 201);
        });
    }

    public function update(Request $request, Area $area): JsonResponse
    {
        Gate::authorize('update', $area);
        $data = $request->validate(['code' => ['sometimes', 'required', 'string', 'max:20'], 'name' => ['sometimes', 'required', 'string', 'max:100'], 'kind' => ['prohibited'], 'parent_id' => ['prohibited']]);
        try {
            DB::connection('rukun')->transaction(function () use ($area, $data): void {
                $area->update($data);
                CommunityAudit::record('area.updated', $area);
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23505') {
                throw $exception;
            }

            return ApiResponse::error(__('community::messages.area_conflict'), 409, code: 'area.conflict');
        }

        return ApiResponse::success($this->data($area));
    }

    public function destroy(Area $area): JsonResponse
    {
        Gate::authorize('delete', $area);
        try {
            DB::connection('rukun')->transaction(function () use ($area): void {
                $area->delete();
                CommunityAudit::record('area.deleted', $area);
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23503') {
                throw $exception;
            }

            return ApiResponse::error(__('community::messages.area_in_use'), 409, code: 'area.in_use');
        }

        return ApiResponse::success(['deleted' => true]);
    }

    private function data(Area $area): array
    {
        return ['public_id' => $area->public_id, 'kind' => $area->kind, 'code' => $area->code, 'name' => $area->name, 'parent_id' => $area->parent?->public_id];
    }
}
