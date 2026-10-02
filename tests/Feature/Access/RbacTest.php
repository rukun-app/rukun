<?php

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Community\Models\Area;
use Modules\Community\Models\RoleAssignment;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

it('denies RBAC management without permission', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    Sanctum::actingAs($user);

    $this->getJson('/api/roles')->assertForbidden()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'You do not have permission to perform this action.');
});

it('manages dynamic roles and assigns them to users', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    $target = User::factory()->create();
    Sanctum::actingAs($admin);

    $this->postJson('/api/roles', ['name' => 'editor', 'permissions' => ['settings.view']])->assertCreated();
    $this->putJson("/api/users/{$target->id}/roles", ['roles' => ['editor']])->assertOk()->assertJsonFragment(['editor']);

    expect(User::query()->findOrFail($target->id)->roles()->pluck('name')->all())->toContain('editor')
        ->and(Role::findByName('editor', 'web')->hasPermissionTo('settings.view'))->toBeTrue();
});

it('uses cursor pagination for user collections', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    User::factory()->count(15)->create();
    Sanctum::actingAs($admin);

    $first = $this->getJson('/api/users?per_page=5')->assertOk()
        ->assertJsonCount(5, 'data.data')
        ->assertJsonMissingPath('data.current_page');

    $cursor = $first->json('data.next_cursor');
    expect($cursor)->toBeString()->not->toBeEmpty();

    $this->getJson('/api/users?per_page=5&cursor='.urlencode($cursor))->assertOk()
        ->assertJsonCount(5, 'data.data');
});

it('exposes normalized role and permission data for admin screens', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    Sanctum::actingAs($admin);

    $this->postJson('/api/roles', ['name' => 'editor', 'permissions' => ['settings.view']])->assertCreated();

    $this->getJson('/api/roles')->assertOk()
        ->assertJsonPath('data.0.name', 'admin')
        ->assertJsonFragment(['settings.view']);

    $this->getJson('/api/users')->assertOk()
        ->assertJsonPath('data.data.0.roles.0.name', 'super-admin')
        ->assertJsonFragment(['settings.view']);
});

it('creates, filters, and shows managed users', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    Sanctum::actingAs($admin);

    $response = $this->postJson('/api/users', [
        'name' => 'Support Operator',
        'email' => 'SUPPORT@example.test',
        'password' => 'secure-password',
        'password_confirmation' => 'secure-password',
        'roles' => ['user'],
    ])->assertCreated()->assertJsonPath('data.email', 'support@example.test');

    $id = $response->json('data.id');
    $this->getJson('/api/users?search=Support&status=active&role=user')->assertOk()
        ->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.id', $id);
    $this->getJson("/api/users/{$id}")->assertOk()
        ->assertJsonPath('data.active_tokens', 0)->assertJsonPath('data.roles.0.name', 'user');
});

it('rejects a stale role update', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
    $staleTimestamp = $role->updated_at->subSecond()->toISOString();
    Sanctum::actingAs($admin);

    $this->patchJson("/api/roles/{$role->id}", ['updated_at' => $staleTimestamp, 'name' => 'publisher'])
        ->assertConflict()->assertJsonPath('message', 'Role was changed by another request. Reload it and try again.');
});

it('updates a role when the client version is current', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
    Sanctum::actingAs($admin);

    $this->patchJson("/api/roles/{$role->id}", [
        'updated_at' => $role->updated_at->toISOString(),
        'name' => 'publisher',
        'permissions' => ['settings.view'],
    ])->assertOk()->assertJsonPath('data.name', 'publisher');

    expect($role->fresh()->hasPermissionTo('settings.view'))->toBeTrue();
});

it('protects the last access manager from losing management access', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    Sanctum::actingAs($admin);

    $this->putJson("/api/users/{$admin->id}/roles", ['roles' => ['user']])
        ->assertUnprocessable()->assertJsonPath('message', 'The last access manager must keep access management permission.');
    $this->patchJson("/api/users/{$admin->id}/status", ['status' => 'suspended'])
        ->assertUnprocessable();

    expect($admin->fresh()->hasRole('super-admin'))->toBeTrue();
});

it('includes community role and permission data for every user row', function () {
    $admin = new User([
        'name' => 'Community Admin',
        'email' => 'community-admin-'.fake()->uuid().'@example.test',
        'password' => bcrypt('secret-secret'),
        'email_verified_at' => now(),
        'status' => 'active',
    ]);
    $admin->setConnection('core');
    $admin->save();
    $admin->assignRole('super-admin');
    $target = new User([
        'name' => 'Community User',
        'email' => 'community-user-'.fake()->uuid().'@example.test',
        'password' => bcrypt('secret-secret'),
        'email_verified_at' => now(),
        'status' => 'active',
    ]);
    $target->setConnection('core');
    $target->save();
    $area = Area::factory()->rt()->create();
    Permission::findOrCreate('areas.view', 'web');
    Permission::findOrCreate('residents.manage', 'web');
    $role = Role::findOrCreate('ketua-rt', 'web');
    $role->givePermissionTo(['areas.view', 'residents.manage']);
    RoleAssignment::query()->create([
        'user_id' => $target->id,
        'role_id' => $role->id,
        'scope_type' => 'rt',
        'area_id' => $area->id,
        'starts_at' => now()->subDay(),
        'assigned_by' => $admin->id,
        'status' => 'active',
    ]);
    Sanctum::actingAs($admin);

    $this->getJson('/api/users')->assertOk()
        ->assertJsonFragment(['ketua-rt'])
        ->assertJsonFragment(['residents.manage'])
        ->assertJsonFragment(['areas.view']);
});

it('allows authorized users to filter audit events', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    Sanctum::actingAs($admin);
    $this->postJson('/api/roles', ['name' => 'audited-role', 'permissions' => []])->assertCreated();

    $this->getJson('/api/audit-events?event=rbac.role_created')->assertOk()
        ->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.actor_id', $admin->id);
});
