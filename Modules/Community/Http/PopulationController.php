<?php

namespace Modules\Community\Http;

use Core\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Community\Models\Area;
use Modules\Community\Models\Household;
use Modules\Community\Models\HouseholdMembership;
use Modules\Community\Models\Resident;
use Modules\Community\Services\CommunityAudit;
use Modules\Community\Services\PopulationService;
use Modules\Community\Services\ScopeResolver;

class PopulationController
{
    public function __construct(private ScopeResolver $scopes, private PopulationService $population) {}

    public function households(Request $request): JsonResponse
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'area_id' => ['sometimes', 'uuid']]);
        $query = $this->scopes->households(Household::query(), $request->user());
        if ($request->filled('area_id')) {
            $query->where('area_id', Area::query()->where('public_id', $request->input('area_id'))->value('id'));
        }

        return ApiResponse::success($query->orderBy('public_id')->collectionPaginate($request->integer('per_page', 20))->through(fn ($item) => (new PopulationResource($item))->resolve($request)));
    }

    public function household(Request $request, Household $household): JsonResponse
    {
        abort_unless($this->scopes->allowsHousehold($request->user(), 'households.view', $household), 403);

        return ApiResponse::success(new PopulationResource($household));
    }

    public function storeHousehold(Request $request): JsonResponse
    {
        $data = $request->validate(['area_id' => ['required', 'uuid'], 'reference' => ['sometimes', 'string', 'max:100'], 'address' => ['required', 'string', 'max:2000'], 'block' => ['nullable', 'string', 'max:50'], 'house_number' => ['nullable', 'string', 'max:50'], 'occupancy_status' => ['sometimes', 'in:occupied,vacant,rented,other'], 'kk_number' => ['prohibited']]);

        return ApiResponse::success(new PopulationResource($this->population->createHousehold($request->user(), $data)), 201);
    }

    public function updateHousehold(Request $request, Household $household): JsonResponse
    {
        $data = $request->validate(['address' => ['sometimes', 'required', 'string', 'max:2000'], 'block' => ['nullable', 'string', 'max:50'], 'house_number' => ['nullable', 'string', 'max:50'], 'occupancy_status' => ['sometimes', 'in:occupied,vacant,rented,other'], 'status' => ['sometimes', 'in:active,moved,inactive'], 'area_id' => ['prohibited'], 'reference' => ['prohibited'], 'kk_number' => ['prohibited']]);

        return ApiResponse::success(new PopulationResource($this->population->updateHousehold($request->user(), $household, $data)));
    }

    public function residents(Request $request): JsonResponse
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'household_id' => ['sometimes', 'uuid'], 'area_id' => ['sometimes', 'uuid']]);
        $query = $this->scopes->residents(Resident::query(), $request->user());
        if ($request->filled('household_id')) {
            $query->where('household_id', Household::query()->where('public_id', $request->input('household_id'))->value('id'));
        }
        if ($request->filled('area_id')) {
            $query->where('area_id', Area::query()->where('public_id', $request->input('area_id'))->value('id'));
        }

        return ApiResponse::success($query->orderBy('public_id')->collectionPaginate($request->integer('per_page', 20))->through(fn ($item) => (new PopulationResource($item))->resolve($request)));
    }

    public function resident(Request $request, Resident $resident): JsonResponse
    {
        abort_unless($this->scopes->allowsResident($request->user(), 'residents.view', $resident), 403);

        return ApiResponse::success(new PopulationResource($resident));
    }

    public function storeResident(Request $request): JsonResponse
    {
        $data = $request->validate(['household_id' => ['required', 'uuid'], 'reference' => ['sometimes', 'string', 'max:100'], 'name' => ['required', 'string', 'max:100'], 'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'phone' => ['nullable', 'string', 'max:40'], 'relationship' => ['required', 'in:head,spouse,child,parent,other'], 'nik' => ['prohibited'], 'user_id' => ['prohibited']]);

        return ApiResponse::success(new PopulationResource($this->population->createResident($request->user(), $data)), 201);
    }

    public function updateResident(Request $request, Resident $resident): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'required', 'string', 'max:100'], 'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'phone' => ['nullable', 'string', 'max:40'], 'status' => ['sometimes', 'in:active,moved,deceased,inactive'], 'household_id' => ['prohibited'], 'user_id' => ['prohibited'], 'nik' => ['prohibited'], 'reference' => ['prohibited']]);

        return ApiResponse::success(new PopulationResource($this->population->updateResident($request->user(), $resident, $data)));
    }

    public function move(Request $request, Resident $resident): JsonResponse
    {
        $data = $request->validate(['household_id' => ['present', 'nullable', 'uuid'], 'relationship' => ['required', 'in:head,spouse,child,parent,other']]);
        $household = $data['household_id'] ? Household::query()->where('public_id', $data['household_id'])->firstOrFail() : null;

        return ApiResponse::success(new PopulationResource($this->population->move($request->user(), $resident, $household, $data['relationship'])));
    }

    public function memberships(Request $request, Resident $resident): JsonResponse
    {
        abort_unless($this->scopes->allowsResident($request->user(), 'residents.view', $resident), 403);
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $ids = $this->scopes->households(Household::query(), $request->user(), 'residents.view')->pluck('id');

        return ApiResponse::success(HouseholdMembership::query()->where('resident_id', $resident->id)->whereIn('household_id', $ids)->orderBy('public_id')->collectionPaginate($request->integer('per_page', 20))->through(fn ($membership) => ['public_id' => $membership->public_id, 'household_id' => Household::query()->findOrFail($membership->household_id)->public_id, 'relationship' => $membership->relationship, 'starts_at' => $membership->starts_at->toISOString(), 'ends_at' => $membership->ends_at?->toISOString()]));
    }

    public function account(Request $request, Resident $resident): JsonResponse
    {
        $key = validator(['key' => $request->header('Idempotency-Key')], ['key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/']])->validate()['key'];
        $data = $request->validate(['user_id' => ['sometimes', 'uuid'], 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:40'], 'locale' => ['sometimes', 'in:id,en']]);
        $operation = $this->population->account($request->user(), $resident, $key, $data);

        return ApiResponse::success(['resident' => new PopulationResource($resident->fresh()), 'operation_id' => $operation?->public_id, 'credential_url' => $operation ? route('community.credentials.download', $operation) : null], $operation ? 201 : 200);
    }

    public function householdSensitive(Request $request, Household $household): JsonResponse
    {
        return $this->sensitive($request, $household);
    }

    public function residentSensitive(Request $request, Resident $resident): JsonResponse
    {
        return $this->sensitive($request, $resident);
    }

    private function sensitive(Request $request, Household|Resident $subject): JsonResponse
    {
        $isResident = $subject instanceof Resident;
        abort_unless($isResident ? $this->scopes->allowsResident($request->user(), 'residents.view-sensitive', $subject) : $this->scopes->allowsHousehold($request->user(), 'residents.view-sensitive', $subject), 403);
        $field = $isResident ? 'nik' : 'kk_number';
        if ($request->isMethod('PUT')) {
            $data = $request->validate([$field => ['present', 'nullable', 'string', 'regex:/^[0-9]{16}$/']]);
            $this->population->sensitive($request->user(), $subject, $data[$field]);

            return ApiResponse::success(['updated' => true])->header('Cache-Control', 'private, no-store');
        }
        CommunityAudit::record('population.sensitive_viewed', $subject, actorId: $request->user()->id);

        return ApiResponse::success([$field => $subject->{$field}])->header('Cache-Control', 'private, no-store');
    }
}
