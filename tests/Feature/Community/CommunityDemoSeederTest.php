<?php

use App\Models\User;
use Database\Seeders\CommunityDemoSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Community\Models\Area;
use Modules\Community\Models\Household;
use Modules\Community\Models\HouseholdMembership;
use Modules\Community\Models\Resident;
use Modules\Community\Models\RoleAssignment;
use Modules\Community\Services\UserContexts;

uses(DatabaseMigrations::class);

beforeEach(function () {
    Cache::store('redis')->getStore()->setPrefix('demo-test:'.Str::uuid().':');
    Storage::fake('local');
});

it('creates repeatable demo data and preserves edited records and account passwords', function () {
    $this->seed(CommunityDemoSeeder::class);
    expect(Area::query()->count())->toBe(5)->and(Household::query()->count())->toBe(10)->and(Resident::query()->count())->toBe(40)->and(HouseholdMembership::query()->whereNull('ends_at')->count())->toBe(40)->and(User::query()->count())->toBe(25);
    $user = User::query()->where('email', 'demo.warga01@rukun.test')->firstOrFail();
    $user->update(['password' => 'Changed-Demo-Password-42!']);
    $household = Household::query()->where('reference', 'DEMO-COM-H01')->firstOrFail();
    $household->update(['address' => 'Edited by FE tester']);
    $assignmentCount = RoleAssignment::query()->count();
    $this->seed(CommunityDemoSeeder::class);
    expect(Hash::check('Changed-Demo-Password-42!', $user->fresh()->password))->toBeTrue()->and($household->fresh()->address)->toBe('Edited by FE tester')->and(HouseholdMembership::query()->count())->toBe(40)->and(RoleAssignment::query()->count())->toBe($assignmentCount)->and(User::query()->count())->toBe(25);
    $manifest = json_decode(Storage::disk('local')->get(CommunityDemoSeeder::CREDENTIAL_FILE), true);
    expect($manifest['accounts'])->toHaveCount(25)->and(fileperms(Storage::disk('local')->path(CommunityDemoSeeder::CREDENTIAL_FILE)) & 0777)->toBe(0600);
});

it('supports login and scopes the actual community endpoints for each demo role', function () {
    $this->seed(CommunityDemoSeeder::class);
    $manifest = json_decode(Storage::disk('local')->get(CommunityDemoSeeder::CREDENTIAL_FILE), true);
    $this->postJson('/api/auth/login', ['identifier' => 'demo.ketua.rt01@rukun.test', 'password' => $manifest['password'], 'device_name' => 'FE demo test'])->assertOk()->assertJsonPath('data.user.must_change_password', false)->assertJsonPath('data.user.permissions', [])->assertJsonPath('data.user.contexts.0.scope.type', 'rt');
    foreach (['admin' => 10, 'ketua.rw01' => 8, 'ketua.rt01' => 4, 'sekretaris.rt02' => 4, 'ketua.rw02' => 2, 'warga01' => 1, 'pengelola.kk08' => 1, 'bendahara.rt01' => 1, 'ronda' => 1, 'vendor' => 0] as $login => $count) {
        $user = User::query()->where('email', 'demo.'.$login.'@rukun.test')->firstOrFail();
        $this->actingAs($user)->getJson('/api/community/households?per_page=100')->assertOk()->assertJsonCount($count, 'data.data');
        $this->getJson('/api/community/residents?per_page=100')->assertOk()->assertJsonCount($count * 4, 'data.data');
        if ($login !== 'admin') {
            expect($user->getAllPermissions())->toHaveCount(0);
        }
    }
    $this->actingAs(User::query()->where('email', 'demo.warga01@rukun.test')->firstOrFail());
    $this->getJson('/api/community/households/'.Household::query()->where('reference', 'DEMO-COM-H09')->firstOrFail()->public_id)->assertForbidden();
});

it('removes revoked, future and expired assignments from profile contexts', function () {
    $this->seed(CommunityDemoSeeder::class);
    $user = User::query()->where('email', 'demo.ketua.rt01@rukun.test')->firstOrFail();
    $assignment = RoleAssignment::query()->where('user_id', $user->id)->firstOrFail();
    foreach ([['status' => 'revoked'], ['status' => 'active', 'starts_at' => now()->addDay()], ['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]] as $change) {
        $assignment->update($change);
        $contexts = app(UserContexts::class)->forUser($user);
        expect(array_column($contexts, 'type'))->toBe(['household']);
    }
    $user->forceFill(['must_change_password' => true])->save();
    expect(app(UserContexts::class)->forUser($user))->toBe([]);
});

it('refuses demo data outside development and does not populate the standard seeder', function () {
    $this->seed(DatabaseSeeder::class);
    expect(Household::query()->count())->toBe(0);
    $original = app()['env'];
    app()['env'] = 'production';
    try {
        expect(fn () => (new CommunityDemoSeeder)->run())->toThrow(RuntimeException::class);
        expect(User::query()->count())->toBe(0);
    } finally {
        app()['env'] = $original;
    }
});
