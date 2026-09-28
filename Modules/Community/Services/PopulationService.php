<?php

namespace Modules\Community\Services;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Community\Models\AccountOperation;
use Modules\Community\Models\Area;
use Modules\Community\Models\Household;
use Modules\Community\Models\HouseholdMembership;
use Modules\Community\Models\Resident;
use Modules\Community\Models\RoleAssignment;
use Modules\Identity\Support\LoginIdentifier;

class PopulationService
{
    public function __construct(private ScopeResolver $scopes, private AccountProvisioner $accounts) {}

    public function createHousehold(User $actor, array $data): Household
    {
        $area = Area::query()->where('public_id', $data['area_id'])->where('kind', 'rt')->firstOrFail();
        abort_unless($this->scopes->allows($actor, 'households.manage', $area), 403);

        return $this->write(function () use ($actor, $data, $area): Household {
            $this->lockUsers($actor, null);
            abort_unless($this->scopes->allows($actor, 'households.manage', $area), 403);
            $household = Household::query()->create([...$data, 'area_id' => $area->id, 'reference' => $data['reference'] ?? (string) Str::uuid()]);
            CommunityAudit::record('household.created', $household, actorId: $actor->id);

            return $household->refresh();
        });
    }

    public function updateHousehold(User $actor, Household $household, array $data): Household
    {
        abort_unless($this->scopes->allowsHousehold($actor, 'households.manage', $household), 403);

        return $this->write(function () use ($actor, $household, $data): Household {
            $this->lockUsers($actor, null);
            $household = Household::query()->whereKey($household->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->scopes->allowsHousehold($actor, 'households.manage', $household), 403);
            if (($data['status'] ?? $household->status) !== 'active') {
                abort_if(HouseholdMembership::query()->where('household_id', $household->id)->whereNull('ends_at')->exists(), 409, __('community::messages.household_has_members'));
            }
            $household->update($data);
            CommunityAudit::record('household.updated', $household, ['fields' => array_keys($data)], $actor->id);

            return $household;
        });
    }

    public function createResident(User $actor, array $data): Resident
    {
        $household = Household::query()->where('public_id', $data['household_id'])->firstOrFail();
        abort_unless($this->scopes->allowsHousehold($actor, 'residents.manage', $household), 403);

        return $this->write(function () use ($actor, $data, $household): Resident {
            $this->lockUsers($actor, null);
            $household = Household::query()->whereKey($household->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->scopes->allowsHousehold($actor, 'residents.manage', $household), 403);
            abort_unless($household->status === 'active', 409, __('community::messages.inactive_household'));
            $resident = Resident::query()->create(['reference' => $data['reference'] ?? (string) Str::uuid(), 'name' => $data['name'], 'birth_date' => $data['birth_date'] ?? null, 'phone' => isset($data['phone']) ? LoginIdentifier::phone($data['phone']) : null, 'area_id' => $household->area_id, 'household_id' => $household->id, 'status' => 'active']);
            HouseholdMembership::query()->create(['resident_id' => $resident->id, 'household_id' => $household->id, 'relationship' => $data['relationship'], 'starts_at' => now()]);
            CommunityAudit::record('resident.created', $resident, ['household_public_id' => $household->public_id], $actor->id);

            return $resident;
        });
    }

    public function updateResident(User $actor, Resident $resident, array $data): Resident
    {
        abort_unless($this->scopes->allowsResident($actor, 'residents.manage', $resident), 403);

        return $this->write(function () use ($actor, $resident, $data): Resident {
            $this->lockUsers($actor, $resident->user_id);
            $resident = Resident::query()->whereKey($resident->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->scopes->allowsResident($actor, 'residents.manage', $resident), 403);
            if (isset($data['phone'])) {
                $data['phone'] = LoginIdentifier::phone($data['phone']);
            }
            if (($data['status'] ?? 'active') !== 'active') {
                $this->closeMembership($actor, $resident);
            }
            $resident->update($data);
            CommunityAudit::record('resident.updated', $resident, ['fields' => array_keys($data)], $actor->id);

            return $resident;
        });
    }

    public function move(User $actor, Resident $resident, ?Household $destination, string $relationship = 'other'): Resident
    {
        return $this->write(function () use ($actor, $resident, $destination, $relationship): Resident {
            $this->lockUsers($actor, $resident->user_id);
            $resident = Resident::query()->whereKey($resident->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->scopes->allowsResident($actor, 'residents.manage', $resident), 403);
            if ($destination) {
                $destination = Household::query()->whereKey($destination->id)->lockForUpdate()->firstOrFail();
                abort_unless($this->scopes->allowsHousehold($actor, 'residents.manage', $destination), 403);
                abort_unless($resident->status === 'active' && $destination->status === 'active', 409, __('community::messages.inactive_household'));
                $current = HouseholdMembership::query()->where('resident_id', $resident->id)->whereNull('ends_at')->first();
                if ($current && $current->household_id === $destination->id && $current->relationship === $relationship) {
                    return $resident;
                }
            }
            $sameHousehold = $destination && $resident->household_id === $destination->id;
            $this->closeMembership($actor, $resident, ! $sameHousehold);
            if ($destination) {
                HouseholdMembership::query()->create(['resident_id' => $resident->id, 'household_id' => $destination->id, 'relationship' => $relationship, 'starts_at' => now()]);
                $resident->update(['household_id' => $destination->id, 'area_id' => $destination->area_id]);
                $this->syncAccountScope($resident);
            }
            CommunityAudit::record('membership.changed', $resident, ['household_public_id' => $destination?->public_id], $actor->id);

            return $resident;
        });
    }

    public function account(User $actor, Resident $resident, string $key, array $data): ?AccountOperation
    {
        return $this->write(function () use ($actor, $resident, $key, $data): ?AccountOperation {
            $existing = isset($data['user_id']) ? User::query()->where('public_id', $data['user_id'])->firstOrFail() : null;
            $this->lockUsers($actor, $existing?->id ?? $resident->user_id);
            $resident = Resident::query()->whereKey($resident->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->scopes->allowsResident($actor, 'residents.manage', $resident), 403);
            abort_unless($resident->status === 'active' && $resident->household_id, 409, __('community::messages.membership_required'));
            $area = Area::query()->findOrFail($resident->area_id);
            if ($existing) {
                abort_unless($actor->can('users.assign-roles'), 403);
                abort_if($resident->user_id && $resident->user_id !== $existing->id, 409, __('community::messages.account_linked'));
                $resident->update(['user_id' => $existing->id]);
                $this->syncAccountScope($resident);
                CommunityAudit::record('resident.account_linked', $resident, ['user_public_id' => $existing->public_id], $actor->id);

                return null;
            }
            if ($resident->user_id) {
                abort_unless(AccountOperation::query()->where('actor_id', $actor->id)->where('idempotency_key', $key)->where('user_id', $resident->user_id)->exists(), 409, __('community::messages.account_linked'));
            }
            $payload = ['name' => $resident->name, 'area_id' => $area->public_id, 'email' => $data['email'] ?? null, 'phone' => isset($data['phone']) ? LoginIdentifier::phone($data['phone']) : $resident->phone, 'locale' => $data['locale'] ?? 'id'];
            if ($payload['email']) {
                $payload['email'] = mb_strtolower(trim($payload['email']));
            }
            validator($payload, ['email' => ['nullable', 'required_without:phone', 'email', 'max:255'], 'phone' => ['nullable', 'required_without:email', 'string']])->validate();
            $operation = $this->accounts->execute($actor, $key, $payload);
            $resident->update(['user_id' => $operation->user_id]);
            CommunityAudit::record('resident.account_provisioned', $resident, ['operation_public_id' => $operation->public_id], $actor->id);

            return $operation;
        });
    }

    public function sensitive(User $actor, Household|Resident $subject, ?string $value): void
    {
        $allowed = $subject instanceof Resident ? $this->scopes->allowsResident($actor, 'residents.manage', $subject) : $this->scopes->allowsHousehold($actor, 'households.manage', $subject);
        $sensitive = $subject instanceof Resident ? $this->scopes->allowsResident($actor, 'residents.view-sensitive', $subject) : $this->scopes->allowsHousehold($actor, 'residents.view-sensitive', $subject);
        abort_unless($allowed && $sensitive, 403);
        $field = $subject instanceof Resident ? 'nik' : 'kk_number';
        $hash = $subject instanceof Resident ? 'nik_hash' : 'kk_hash';
        $this->write(function () use ($actor, $subject, $field, $hash, $value): void {
            $this->lockUsers($actor, $subject instanceof Resident ? $subject->user_id : null);
            $subject = $subject->newQuery()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            $manage = $subject instanceof Resident ? $this->scopes->allowsResident($actor, 'residents.manage', $subject) : $this->scopes->allowsHousehold($actor, 'households.manage', $subject);
            $sensitive = $subject instanceof Resident ? $this->scopes->allowsResident($actor, 'residents.view-sensitive', $subject) : $this->scopes->allowsHousehold($actor, 'residents.view-sensitive', $subject);
            abort_unless($manage && $sensitive, 403);
            $subject->update([$field => $value, $hash => $value !== null ? SensitiveIdentifier::fingerprint($value) : null]);
            CommunityAudit::record('population.sensitive_updated', $subject, actorId: $actor->id);
        });
    }

    private function closeMembership(User $actor, Resident $resident, bool $revoke = true): void
    {
        HouseholdMembership::query()->where('resident_id', $resident->id)->whereNull('ends_at')->update(['ends_at' => now(), 'updated_at' => now()]);
        if ($resident->user_id && $revoke) {
            $assignments = RoleAssignment::query()->where('user_id', $resident->user_id)->where('scope_type', 'household')->whereNotNull('household_id')->where('household_id', $resident->household_id)->where('status', 'active')->get();
            foreach ($assignments as $assignment) {
                $assignment->update(['status' => 'revoked']);
                CommunityAudit::record('role_assignment.revoked', $assignment, ['reason' => 'membership_ended'], $actor->id);
            }
            DB::connection('rukun')->table('account_scopes')->where('user_id', $resident->user_id)->delete();
            DB::connection('rukun')->table(config('database.connections.core.prefix').'personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $resident->user_id)->delete();
        }
        $resident->update(['household_id' => null]);
    }

    private function syncAccountScope(Resident $resident): void
    {
        if ($resident->user_id) {
            DB::connection('rukun')->table('account_scopes')->updateOrInsert(['user_id' => $resident->user_id], ['area_id' => $resident->area_id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function lockUsers(User $actor, ?int $targetId): void
    {
        DB::connection('rukun')->table(config('database.connections.core.prefix').'users')->whereIn('id', array_filter([$actor->id, $targetId]))->orderBy('id')->lockForUpdate()->get();
        $actor->refresh();
    }

    private function write(callable $callback): mixed
    {
        try {
            return DB::connection('rukun')->transaction($callback, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['resource' => __('community::messages.population_duplicate')]);
        }
    }
}
