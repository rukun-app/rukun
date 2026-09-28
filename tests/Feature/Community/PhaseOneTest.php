<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Modules\Community\Models\AccountOperation;
use Modules\Community\Models\Area;
use Modules\Community\Models\RoleAssignment;
use Modules\Community\Services\AccountProvisioner;
use Spatie\Permission\Models\Role;

uses(DatabaseMigrations::class);

beforeEach(function () {
    Cache::store('redis')->getStore()->setPrefix('rukun-phase1-test:'.Str::uuid().':');
    $this->seed(DatabaseSeeder::class);
    $this->rw = Area::factory()->create(['kind' => 'rw', 'code' => '01', 'name' => 'RW 01']);
    $this->rt = Area::factory()->create(['kind' => 'rt', 'parent_id' => $this->rw->id, 'code' => '01', 'name' => 'RT 01']);
    $this->otherRt = Area::factory()->create(['kind' => 'rt', 'parent_id' => $this->rw->id, 'code' => '02', 'name' => 'RT 02']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
    $this->operator = User::factory()->create();
    $this->assignment = RoleAssignment::factory()->create(['user_id' => $this->operator->id, 'role_id' => Role::findByName('ketua-rt', 'web')->id, 'area_id' => $this->rt->id, 'scope_type' => 'rt', 'starts_at' => now()->subDay(), 'assigned_by' => $this->admin->id]);
});

it('logs in with equivalent Indonesian phone formats and an email identifier', function () {
    $user = User::factory()->create(['phone' => '081234567890', 'password' => 'phone-password']);
    foreach (['0812-3456-7890', '6281234567890', '+62 812 3456 7890', strtoupper($user->email)] as $identifier) {
        $this->postJson('/api/auth/login', ['identifier' => $identifier, 'password' => 'phone-password', 'device_name' => 'phone-test'])
            ->assertOk()->assertJsonPath('data.user.phone', '+6281234567890')->assertJsonPath('data.user.public_id', $user->public_id);
    }
    $this->postJson('/api/auth/login', ['identifier' => [], 'password' => 'phone-password', 'device_name' => 'test'])->assertUnprocessable();
    $this->postJson('/api/auth/login', ['identifier' => '123invalid', 'password' => 'phone-password', 'device_name' => 'test'])->assertUnprocessable();
});

it('shares the failed-login limiter across equivalent phone identifiers', function () {
    User::factory()->create(['email' => null, 'phone' => '081234567891']);
    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/auth/login', ['identifier' => '081234567891', 'password' => 'wrong', 'device_name' => 'test'])->assertUnprocessable();
    }
    $this->postJson('/api/auth/login', ['identifier' => '+62 81234567891', 'password' => 'password', 'device_name' => 'test'])->assertStatus(429)->assertJsonPath('code', 'auth.rate_limited');
});

it('requires a real password change before any authenticated application access', function () {
    $user = User::factory()->create(['email' => null, 'phone' => '081234567892', 'password' => 'initial-password']);
    $user->forceFill(['must_change_password' => true])->save();
    $login = $this->postJson('/api/auth/login', ['identifier' => $user->phone, 'password' => 'initial-password', 'device_name' => 'first-login'])->assertOk();
    $this->withToken($login->json('data.token'));
    $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.must_change_password', true);
    foreach (['/api/community/areas', '/api/files', '/api/auth/tokens', '/api/events'] as $path) {
        $this->getJson($path)->assertForbidden()->assertJsonPath('code', 'auth.password_change_required');
    }
    $this->putJson('/api/auth/password', ['current_password' => 'initial-password', 'password' => 'initial-password', 'password_confirmation' => 'initial-password'])->assertUnprocessable();
    $this->putJson('/api/auth/password', ['current_password' => 'initial-password', 'password' => 'replacement-password', 'password_confirmation' => 'replacement-password'])->assertOk();
    expect($user->fresh()->must_change_password)->toBeFalse();
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/files')->assertOk();
});

it('provisions an account idempotently without persisting plaintext credentials', function () {
    $this->actingAs($this->operator);
    $payload = ['name' => 'Warga Baru', 'phone' => '081234567893', 'area_id' => $this->rt->public_id];
    $headers = ['Idempotency-Key' => 'provision-test-001'];
    $first = $this->postJson('/api/community/accounts', $payload, $headers)->assertCreated();
    $this->postJson('/api/community/accounts', $payload, $headers)->assertCreated()->assertJsonPath('data.public_id', $first->json('data.public_id'));
    $this->postJson('/api/community/accounts', [...$payload, 'name' => 'Changed'], $headers)->assertConflict();
    $target = User::query()->where('public_id', $first->json('data.user_id'))->firstOrFail();
    expect($target->email)->toBeNull()->and($target->must_change_password)->toBeTrue()->and(AccountOperation::query()->count())->toBe(1);
    $output = $this->get($first->json('data.credential_url'))->assertOk()->assertDownload('initial-credential.json')->streamedContent();
    $password = json_decode($output, true)['initial_password'];
    expect(Hash::check($password, $target->password))->toBeTrue();
    expect(Cache::store('redis')->get('community:credential:'.$first->json('data.public_id')))->not->toContain($password);
    expect(DB::table('audit_events')->pluck('metadata')->implode(''))->not->toContain($password);
    expect(AccountOperation::query()->first()->toJson())->not->toContain($password);
    $this->getJson($first->json('data.credential_url'))->assertStatus(410);
});

it('rejects cross RT provisioning and equivalent duplicate email or phone identifiers', function () {
    $this->actingAs($this->operator);
    $data = ['name' => 'Warga', 'phone' => '081234567894', 'area_id' => $this->otherRt->public_id];
    $this->postJson('/api/community/accounts', $data, ['Idempotency-Key' => 'cross-scope-001'])->assertForbidden();
    $data['area_id'] = $this->rt->public_id;
    $this->postJson('/api/community/accounts', $data, ['Idempotency-Key' => 'phone-first-001'])->assertCreated();
    $this->postJson('/api/community/accounts', [...$data, 'phone' => '+6281234567894'], ['Idempotency-Key' => 'phone-duplicate-001'])->assertUnprocessable();
    $data = ['name' => 'Email Warga', 'email' => 'New@Example.test', 'area_id' => $this->rt->public_id];
    $this->postJson('/api/community/accounts', $data, ['Idempotency-Key' => 'email-first-001'])->assertCreated();
    $this->postJson('/api/community/accounts', [...$data, 'email' => 'NEW@EXAMPLE.TEST'], ['Idempotency-Key' => 'email-duplicate-001'])->assertUnprocessable();
    $this->postJson('/api/community/accounts', ['name' => 'Missing', 'area_id' => $this->rt->public_id], ['Idempotency-Key' => 'missing-identifier'])->assertUnprocessable();
    $this->postJson('/api/community/accounts', [...$data, 'phone' => []], ['Idempotency-Key' => 'invalid-phone-array'])->assertUnprocessable();
});

it('expires private credential output and rejects other actors and revoked scope', function () {
    $this->actingAs($this->operator);
    $response = $this->postJson('/api/community/accounts', ['name' => 'Warga', 'phone' => '081234567895', 'area_id' => $this->rt->public_id], ['Idempotency-Key' => 'private-output-001'])->assertCreated();
    $url = $response->json('data.credential_url');
    $this->actingAs($this->admin)->getJson($url)->assertForbidden();
    $this->actingAs($this->operator);
    $this->assignment->update(['status' => 'revoked']);
    $this->getJson($url)->assertForbidden();
    $this->assignment->update(['status' => 'active']);
    $this->travel(16)->minutes();
    $this->getJson($url)->assertStatus(410);
    $this->travelBack();
});

it('recovers only managed accounts in scope and revokes existing tokens', function () {
    $this->actingAs($this->operator);
    $created = $this->postJson('/api/community/accounts', ['name' => 'Warga', 'phone' => '081234567896', 'area_id' => $this->rt->public_id], ['Idempotency-Key' => 'recover-setup-001'])->assertCreated();
    $target = User::query()->where('public_id', $created->json('data.user_id'))->firstOrFail();
    $target->createToken('old');
    $url = '/api/community/accounts/'.$target->public_id.'/recover';
    $recovered = $this->postJson($url, [], ['Idempotency-Key' => 'recover-once-001'])->assertCreated();
    $hash = $target->fresh()->password;
    $this->postJson($url, [], ['Idempotency-Key' => 'recover-once-001'])->assertCreated()->assertJsonPath('data.public_id', $recovered->json('data.public_id'));
    expect($target->tokens()->count())->toBe(0)->and($target->fresh()->password)->toBe($hash)->and($target->fresh()->must_change_password)->toBeTrue();
    $this->getJson($created->json('data.credential_url'))->assertStatus(410);
    $credential = json_decode($this->get($recovered->json('data.credential_url'))->assertOk()->streamedContent(), true);
    expect(Hash::check($credential['initial_password'], $hash))->toBeTrue();
    expect(DB::table('audit_events')->where('event', 'account.recover')->count())->toBe(1);
    $this->assignment->update(['area_id' => $this->otherRt->id]);
    $this->postJson($url, [], ['Idempotency-Key' => 'recover-foreign-001'])->assertForbidden();
    $this->postJson('/api/community/accounts/'.$this->admin->public_id.'/recover', [], ['Idempotency-Key' => 'recover-admin-001'])->assertForbidden();
});

it('grants RW inheritance without RT leakage and respects expired assignments', function () {
    $this->actingAs($this->operator);
    $this->getJson('/api/community/areas')->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.public_id', $this->rt->public_id);
    $this->getJson('/api/community/areas/'.$this->otherRt->public_id)->assertForbidden();
    $this->patchJson('/api/community/areas/'.$this->otherRt->public_id, ['name' => 'Forbidden'])->assertForbidden();
    $this->assignment->update(['ends_at' => now()->subHour()]);
    $this->getJson('/api/community/areas')->assertOk()->assertJsonCount(0, 'data.data');
    $this->assignment->update(['ends_at' => null, 'scope_type' => 'rw', 'area_id' => $this->rw->id]);
    $this->getJson('/api/community/areas')->assertOk()->assertJsonCount(3, 'data.data');
    $this->getJson('/api/community/areas/'.$this->otherRt->public_id)->assertOk();
});

it('supports temporal multi-role grants and forbids scoped operators from assigning roles', function () {
    $this->actingAs($this->operator)->postJson('/api/community/role-assignments', [])->assertForbidden();
    $this->actingAs($this->admin);
    $created = $this->postJson('/api/community/role-assignments', ['user_id' => $this->operator->public_id, 'role' => 'sekretaris-rt', 'scope_type' => 'rt', 'area_id' => $this->otherRt->public_id, 'starts_at' => now()->subHour()->toISOString()])->assertCreated();
    $this->actingAs($this->operator)->getJson('/api/community/areas')->assertOk()->assertJsonCount(2, 'data.data');
    $this->actingAs($this->admin)->deleteJson('/api/community/role-assignments/'.$created->json('data.public_id'))->assertOk();
    $this->actingAs($this->operator)->getJson('/api/community/areas')->assertOk()->assertJsonCount(1, 'data.data');
    expect(RoleAssignment::query()->where('status', 'revoked')->count())->toBe(1);
});

it('protects area mutations and keeps business tables unprefixed', function () {
    $this->actingAs($this->admin);
    $rw = $this->postJson('/api/community/areas', ['kind' => 'rw', 'code' => '02', 'name' => 'RW Baru'], ['Idempotency-Key' => 'area-create-001'])->assertCreated();
    $this->postJson('/api/community/areas', ['kind' => 'rw', 'code' => '02', 'name' => 'RW Baru'], ['Idempotency-Key' => 'area-create-001'])->assertCreated()->assertJsonPath('data.public_id', $rw->json('data.public_id'));
    $url = '/api/community/areas/'.$rw->json('data.public_id');
    $this->patchJson($url, ['name' => 'RW Dua'])->assertOk()->assertJsonPath('data.name', 'RW Dua');
    $this->patchJson($url, ['parent_id' => $this->rt->public_id])->assertUnprocessable();
    $this->deleteJson($url)->assertOk();
    $this->deleteJson('/api/community/areas/'.$this->rw->public_id)->assertConflict();
    $tables = collect(DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public'"))->pluck('tablename');
    expect($tables)->toContain('areas', 'role_assignments', 'account_scopes', 'account_operations', 'rcore_users')->not->toContain('rcore_areas');
});

it('revokes tokens and clears first-login restrictions after email password recovery', function () {
    $user = User::factory()->create();
    $user->forceFill(['must_change_password' => true])->save();
    $user->createToken('old-device');
    $token = Password::createToken($user);
    $this->postJson('/api/auth/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'])->assertOk();
    expect($user->tokens()->count())->toBe(0)->and($user->fresh()->must_change_password)->toBeFalse();
});

it('generates different initial credentials and rolls back if output storage fails', function () {
    $this->actingAs($this->operator);
    $passwords = [];
    foreach (['081234567897', '081234567898'] as $phone) {
        $response = $this->postJson('/api/community/accounts', ['name' => 'Warga', 'phone' => $phone, 'area_id' => $this->rt->public_id], ['Idempotency-Key' => 'distinct-'.$phone])->assertCreated();
        $passwords[] = json_decode($this->get($response->json('data.credential_url'))->assertOk()->streamedContent(), true)['initial_password'];
    }
    expect($passwords[0])->not->toBe($passwords[1]);
    $store = Cache::store('redis');
    $proxy = Mockery::mock($store);
    $proxy->shouldReceive('put')->withArgs(fn ($key) => str_starts_with($key, 'community:credential:'))->andThrow(new RuntimeException('Storage unavailable'));
    Cache::shouldReceive('store')->with('redis')->andReturn($proxy);
    try {
        app(AccountProvisioner::class)->execute($this->operator, 'rollback-operation-001', ['name' => 'Rollback', 'phone' => '+6281234567899', 'area_id' => $this->rt->public_id]);
        $this->fail('Expected a storage failure');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Storage unavailable');
    }
    expect(User::query()->where('phone', '+6281234567899')->exists())->toBeFalse()
        ->and(AccountOperation::query()->where('idempotency_key', 'rollback-operation-001')->exists())->toBeFalse();
});

it('does not activate future assignments or allow expired scope to replay a create', function () {
    $this->actingAs($this->operator);
    $this->assignment->update(['scope_type' => 'rw', 'area_id' => $this->rw->id]);
    $payload = ['kind' => 'rt', 'parent_id' => $this->rw->public_id, 'code' => '03', 'name' => 'RT 03'];
    $this->postJson('/api/community/areas', $payload, ['Idempotency-Key' => 'authorized-area-retry'])->assertCreated();
    $this->assignment->update(['starts_at' => now()->addHour()]);
    $this->postJson('/api/community/areas', $payload, ['Idempotency-Key' => 'authorized-area-retry'])->assertForbidden();
    $this->getJson('/api/community/areas')->assertOk()->assertJsonCount(0, 'data.data');
});

it('requires an explicit recovery permission rather than area membership or a role name', function () {
    $this->actingAs($this->operator);
    $response = $this->postJson('/api/community/accounts', ['name' => 'Warga', 'phone' => '081234567800', 'area_id' => $this->rt->public_id], ['Idempotency-Key' => 'recover-permission-setup'])->assertCreated();
    Role::findByName('ketua-rt', 'web')->revokePermissionTo('users.recover-account');
    $this->postJson('/api/community/accounts/'.$response->json('data.user_id').'/recover', [], ['Idempotency-Key' => 'recover-no-permission'])->assertForbidden();
});

it('enforces hierarchy and login identifier invariants at the database boundary', function () {
    try {
        Area::query()->create(['kind' => 'rt', 'parent_id' => $this->rt->id, 'code' => '99', 'name' => 'Invalid RT parent']);
        $this->fail('Expected hierarchy constraint');
    } catch (QueryException $exception) {
        expect($exception->getCode())->toBe('23503');
    }
    try {
        DB::table('users')->where('id', $this->operator->id)->update(['email' => null, 'phone' => null]);
        $this->fail('Expected identifier constraint');
    } catch (QueryException $exception) {
        expect($exception->getCode())->toBe('23514');
    }
});

it('paginates public area resources without losing the cursor key', function () {
    $this->actingAs($this->admin);
    $first = $this->getJson('/api/community/areas?per_page=2')->assertOk();
    $cursor = $first->json('data.next_cursor');
    expect($cursor)->not->toBeNull();
    $second = $this->getJson('/api/community/areas?per_page=2&cursor='.urlencode($cursor))->assertOk()->assertJsonCount(1, 'data.data');
    expect(collect([...$first->json('data.data'), ...$second->json('data.data')])->pluck('public_id')->unique())->toHaveCount(3);
    $first->assertJsonMissingPath('data.data.0.id');
});
