<?php

namespace Modules\Community\Handlers;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Community\Models\AccountOperation;
use Modules\Community\Models\Area;
use Modules\Community\Models\Household;
use Modules\Community\Models\HouseholdMembership;
use Modules\Community\Models\Resident;
use Modules\Community\Services\PopulationService;
use Modules\Community\Services\ScopeResolver;
use Modules\Community\Services\SensitiveIdentifier;
use Modules\DataTransfer\Contracts\AuthorizesDataTransfer;
use Modules\DataTransfer\Contracts\DataTransferHandler;
use Modules\Files\Models\StoredFile;
use Modules\Identity\Support\LoginIdentifier;

class PopulationHandler implements AuthorizesDataTransfer, DataTransferHandler
{
    public function __construct(private ScopeResolver $scopes, private PopulationService $population) {}

    public function key(): string
    {
        return 'community.population';
    }

    public function label(): string
    {
        return 'Households and residents';
    }

    public function supportsImport(): bool
    {
        return true;
    }

    public function supportsExport(): bool
    {
        return true;
    }

    public function importColumns(): array
    {
        return ['household_reference', 'resident_reference', 'household_address', 'resident_name', 'relationship', 'create_account'];
    }

    public function columns(): array
    {
        return [...$this->importColumns(), 'block', 'house_number', 'occupancy_status', 'birth_date', 'phone', 'email'];
    }

    public function protectExport(StoredFile $file, array $options): void
    {
        DB::table(DB::raw('population_exports'))->insert(['file_id' => $file->id, 'area_id' => Area::query()->where('public_id', $options['area_id'])->firstOrFail()->id]);
    }

    public function authorize(User $actor, string $direction, array $options): void
    {
        validator(['options' => $options], ['options' => ['required', 'array:area_id,format'], 'options.area_id' => ['required', 'uuid'], 'options.format' => ['sometimes', 'in:csv,xlsx']])->validate();
        $area = Area::query()->where('public_id', $options['area_id'])->where('kind', 'rt')->firstOrFail();
        foreach ($direction === 'import' ? ['population.transfer', 'households.manage', 'residents.manage'] : ['population.transfer', 'households.view', 'residents.view'] as $permission) {
            abort_unless($this->scopes->allows($actor, $permission, $area), 403);
        }
    }

    public function importRow(User $actor, array $row, array $options): void
    {
        $transferId = $options['_transfer_id'] ?? null;
        unset($options['_transfer_id']);
        $actor = $actor->fresh();
        $this->authorize($actor, 'import', $options);
        if (array_diff(array_keys($row), $this->columns())) {
            throw ValidationException::withMessages(['columns' => __('community::messages.import_columns')]);
        }
        $row = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $row);
        $row = array_replace(array_fill_keys($this->columns(), null), $row);
        $row['occupancy_status'] = $row['occupancy_status'] ?: 'occupied';
        validator($row, ['household_reference' => ['required', 'string', 'max:100'], 'resident_reference' => ['required', 'string', 'max:100'], 'household_address' => ['required', 'string', 'max:2000'], 'resident_name' => ['required', 'string', 'max:100'], 'relationship' => ['required', 'in:head,spouse,child,parent,other'], 'create_account' => ['required', 'in:0,1'], 'block' => ['nullable', 'string', 'max:50'], 'house_number' => ['nullable', 'string', 'max:50'], 'occupancy_status' => ['required', 'in:occupied,vacant,rented,other'], 'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'phone' => ['nullable', 'string', 'max:40'], 'email' => ['nullable', 'email', 'max:255']])->validate();
        $row['phone'] = $row['phone'] ? LoginIdentifier::phone(ltrim($row['phone'], "'")) : null;
        $row['email'] = $row['email'] ? mb_strtolower($row['email']) : null;
        $fingerprint = SensitiveIdentifier::fingerprint(json_encode([$options['area_id'], $row], JSON_THROW_ON_ERROR));
        try {
            DB::connection('rukun')->transaction(function () use ($actor, $row, $options, $fingerprint, $transferId): void {
                $area = Area::query()->where('public_id', $options['area_id'])->lockForUpdate()->firstOrFail();
                $this->authorize($actor, 'import', $options);
                $previous = DB::connection('rukun')->table('population_import_rows')->where('resident_reference', $row['resident_reference'])->first();
                if ($previous) {
                    $resident = Resident::query()->findOrFail($previous->resident_id);
                    if (! hash_equals($previous->fingerprint, $fingerprint) || $resident->area_id !== $area->id) {
                        $this->conflict();
                    }
                    $operation = AccountOperation::query()->where('actor_id', $actor->id)->where('user_id', $resident->user_id)->where('kind', 'provision')->latest('id')->first();
                    $this->result($transferId, $resident, $operation);

                    return;
                }
                if (Resident::query()->where('reference', $row['resident_reference'])->exists()) {
                    $this->conflict();
                }
                $household = Household::query()->where('reference', $row['household_reference'])->first();
                $householdData = ['reference' => $row['household_reference'], 'area_id' => $area->public_id, 'address' => $row['household_address'], 'block' => $row['block'] ?: null, 'house_number' => $row['house_number'] ?: null, 'occupancy_status' => $row['occupancy_status']];
                if ($household) {
                    if ($household->area_id !== $area->id || $household->status !== 'active') {
                        $this->conflict();
                    }
                    foreach (['address', 'block', 'house_number', 'occupancy_status'] as $field) {
                        if ($household->{$field} !== $householdData[$field]) {
                            $this->conflict();
                        }
                    }
                } else {
                    $household = $this->population->createHousehold($actor, $householdData);
                }
                $resident = $this->population->createResident($actor, ['reference' => $row['resident_reference'], 'household_id' => $household->public_id, 'name' => $row['resident_name'], 'relationship' => $row['relationship'], 'birth_date' => $row['birth_date'] ?: null, 'phone' => $row['phone']]);
                $operation = null;
                if ($row['create_account'] === '1') {
                    $operation = $this->population->account($actor, $resident, 'population:'.hash('sha256', $row['resident_reference']), ['email' => $row['email'], 'phone' => $row['phone']]);
                }
                DB::connection('rukun')->table('population_import_rows')->insert(['resident_reference' => $row['resident_reference'], 'resident_id' => $resident->id, 'fingerprint' => $fingerprint, 'created_at' => now(), 'updated_at' => now()]);
                $this->result($transferId, $resident, $operation);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            $this->conflict();
        }
    }

    public function exportRows(User $actor, array $options): iterable
    {
        $this->authorize($actor->fresh(), 'export', $options);
        $area = Area::query()->where('public_id', $options['area_id'])->firstOrFail();
        foreach (Resident::query()->where('area_id', $area->id)->where('status', 'active')->whereNotNull('household_id')->orderBy('id')->lazyById(100) as $resident) {
            $this->authorize($actor->fresh(), 'export', $options);
            $household = Household::query()->findOrFail($resident->household_id);
            $membership = HouseholdMembership::query()->where('resident_id', $resident->id)->whereNull('ends_at')->firstOrFail();
            yield ['household_reference' => $household->reference, 'resident_reference' => $resident->reference, 'household_address' => $household->address, 'resident_name' => $resident->name, 'relationship' => $membership->relationship, 'create_account' => '0', 'block' => $household->block, 'house_number' => $household->house_number, 'occupancy_status' => $household->occupancy_status, 'birth_date' => $resident->birth_date?->format('Y-m-d'), 'phone' => $resident->phone, 'email' => $resident->user_id ? User::query()->find($resident->user_id)?->email : null];
        }
    }

    private function result(?string $transferId, Resident $resident, ?AccountOperation $operation): void
    {
        if ($transferId) {
            DB::connection('rukun')->table('population_import_results')->updateOrInsert(['transfer_id' => $transferId, 'resident_id' => $resident->id], ['operation_id' => $operation?->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function conflict(): never
    {
        throw ValidationException::withMessages(['reference' => __('community::messages.import_conflict')]);
    }
}
