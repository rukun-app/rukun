<?php

use App\Models\User;
use Core\Http\CollectionProfile;
use Core\OpenApi\CollectionQuerySpec;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentType;
use Modules\Billing\Models\Tariff;
use Modules\Community\Models\Area;
use Modules\Community\Models\Household;
use Modules\Community\Models\Resident;
use Modules\Community\Models\RoleAssignment;
use Modules\Engagement\Models\Team;
use Modules\Files\Models\StoredFile;
use Spatie\Permission\Models\Role;

uses(DatabaseMigrations::class);

beforeEach(function () {
    Cache::store('redis')->getStore()->setPrefix('rukun-collection-test:'.Str::uuid().':');
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
    $this->area = Area::factory()->rt()->create();
    $this->otherArea = Area::factory()->rt()->create();
    $this->officer = User::factory()->create();
    RoleAssignment::factory()->create(['user_id' => $this->officer->id, 'role_id' => Role::findByName('ketua-rt', 'web')->id, 'scope_type' => 'rt', 'area_id' => $this->area->id, 'assigned_by' => $this->admin->id]);
    $this->homes = collect(['Alpha', 'Beta', 'Gamma'])->map(fn ($name) => Household::factory()->create(['area_id' => $this->area->id, 'address' => $name]));
    $this->outside = Household::factory()->create(['area_id' => $this->otherArea->id, 'address' => 'Outside']);
});

it('projects fields and expands only safe relations without breaking cursor navigation', function () {
    $query = ['fields' => ['households' => 'address', 'areas' => 'public_id,name'], 'include' => 'area', 'per_page' => 1];
    $this->actingAs($this->officer);
    $first = $this->getJson('/api/community/households?'.http_build_query($query))->assertOk();
    $first->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.area.public_id', $this->area->public_id);
    expect(array_keys($first->json('data.data.0')))->toBe(['address', 'area']);
    expect($first->json('data.next_cursor'))->not->toBeNull();
    $second = $this->getJson($first->json('data.next_page_url'))->assertOk();
    expect($second->json('data.data.0.address'))->not->toBe($first->json('data.data.0.address'));
    expect(array_keys($second->json('data.data.0')))->toBe(['address', 'area']);
});

it('keeps native multi-value filters and search inside authorized RT scope', function () {
    $this->actingAs($this->officer);
    $query = ['filter' => ['status' => 'active', 'address' => 'Alpha,Outside', 'search' => 'a,Outside']];
    $this->getJson('/api/community/households?'.http_build_query($query))->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.address', 'Alpha');
    $query = ['filter' => ['area_id' => $this->otherArea->public_id]];
    $this->getJson('/api/community/households?'.http_build_query($query))->assertOk()->assertJsonCount(0, 'data.data');
    $query = ['filter' => ['area_id' => $this->area->public_id.','.$this->otherArea->public_id]];
    $this->getJson('/api/community/households?'.http_build_query($query))->assertOk()->assertJsonCount(3, 'data.data');
});

it('supports native descending sort and Laravel pagination', function () {
    $this->actingAs($this->officer);
    $query = ['sort' => '-address', 'per_page' => 1, 'page' => 2, 'fields' => ['households' => 'address']];
    $this->getJson('/api/community/households?'.http_build_query($query))->assertOk()->assertJsonPath('data.total', 3)->assertJsonPath('data.current_page', 2)->assertJsonPath('data.per_page', 1)->assertJsonPath('data.data', [['address' => 'Beta']]);
});

it('rejects private fields injection malformed syntax and conflicting pagination', function () {
    $this->actingAs($this->officer);
    foreach ([
        ['fields' => ['households' => 'kk_number']], ['fields' => ['households' => 'id']], ['filter' => ['kk_hash' => 'guess']],
        ['filter' => ['address); DROP TABLE households;--' => 'x']],
        ['filter' => ['public_id' => 'not-a-uuid']], ['filter' => ['area_id' => '1']],
        ['sort' => '-address;SELECT 1'], ['include' => 'residents'], ['include' => 'area', 'fields' => ['areas' => 'id']],
        ['per_page' => 101], ['page' => 0], ['cursor' => 'invalid'], ['cursor' => 'abc', 'sort' => 'address'],
        ['fields' => ['address']], ['filter' => ['status||$eq||active']], ['sort' => ['address,DESC']],
        ['filter' => 'status||$eq||active'], ['filter' => ['status' => ['nested' => 'active']]],
        ['join' => 'area'], ['s' => '{}'], ['or' => ['anything']], ['cache' => 0], ['offset' => 0], ['limit' => 10],
    ] as $query) {
        $this->getJson('/api/community/households?'.http_build_query($query))->assertUnprocessable();
    }
    expect(Household::query()->count())->toBe(4);
});

it('handles SQL wildcard literals and nullable sorts without cursor errors', function () {
    $this->homes[0]->update(['address' => '100% complete', 'block' => null]);
    $this->actingAs($this->officer);
    $this->getJson('/api/community/households?'.http_build_query(['filter' => ['address' => '%']]))->assertOk()->assertJsonCount(1, 'data.data');
    $this->getJson('/api/community/households?'.http_build_query(['sort' => 'block', 'per_page' => 1, 'page' => 2]))->assertOk()->assertJsonPath('data.total', 3);
});

it('preserves flat access lists and never exposes token secrets', function () {
    $this->admin->createToken('Test device');
    $this->actingAs($this->admin);
    $this->getJson('/api/roles?'.http_build_query(['fields' => ['roles' => 'name'], 'filter' => ['name' => 'super-admin']]))->assertOk()->assertJsonPath('data', [['name' => 'super-admin']]);
    $this->getJson('/api/permissions?'.http_build_query(['fields' => ['permissions' => 'name'], 'per_page' => 2]))->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/auth/tokens?'.http_build_query(['fields' => ['personal_access_tokens' => 'name']]))->assertOk()->assertJsonPath('data', [['name' => 'Test device']]);
    $this->getJson('/api/auth/tokens?'.http_build_query(['fields' => ['personal_access_tokens' => 'token']]))->assertUnprocessable();
    $this->getJson('/api/users?'.http_build_query(['fields' => ['users' => 'password']]))->assertUnprocessable();
    $this->getJson('/api/files?'.http_build_query(['fields' => ['files' => 'path']]))->assertUnprocessable();
});

it('checks every collection profile against real schema and documents every supported path', function () {
    $core = ['users', 'roles', 'permissions', 'personal_access_tokens', 'files', 'data_transfers', 'notifications', 'audit_events'];
    $document = $this->getJson('/docs/api/openapi.json')->assertOk()->json();
    foreach (CollectionQuerySpec::resources() as $path => $table) {
        $profile = CollectionProfile::for($table);
        $columns = DB::connection(in_array($table, $core, true) ? 'core' : 'rukun')->getSchemaBuilder()->getColumnListing($table);
        expect(array_diff([...array_values($profile['columns']), ...array_values($profile['relations'])], $columns))->toBe([], $table);
        $parameters = array_column($document['paths'][$path]['get']['parameters'], 'name');
        foreach (['fields['.$table.']', 'sort', 'include', 'per_page', 'page', 'cursor'] as $name) {
            expect($parameters)->toContain($name);
        }
    }
});

it('does not flush shared caches and reflects changes immediately', function () {
    Cache::put('collection-probe', 'preserved', 60);
    $this->actingAs($this->officer);
    $query = ['filter' => ['public_id' => $this->homes[0]->public_id]];
    $this->getJson('/api/community/households?'.http_build_query($query))->assertOk()->assertJsonPath('data.data.0.address', 'Alpha');
    $this->homes[0]->update(['address' => 'Updated']);
    $this->getJson('/api/community/households?'.http_build_query($query))->assertOk()->assertJsonPath('data.data.0.address', 'Updated');
    expect(Cache::get('collection-probe'))->toBe('preserved');
});

it('expands resident references across Core and Rukun using public IDs', function () {
    $resident = Resident::factory()->create(['area_id' => $this->area->id, 'household_id' => $this->homes[0]->id, 'user_id' => $this->officer->id]);
    $this->actingAs($this->officer);
    $query = ['fields' => ['residents' => 'name', 'households' => 'reference', 'users' => 'public_id,name'], 'include' => 'household,user', 'filter' => ['user_id' => $this->officer->public_id]];
    $this->getJson('/api/community/residents?'.http_build_query($query))->assertOk()
        ->assertJsonPath('data.data.0.name', $resident->name)
        ->assertJsonPath('data.data.0.household', ['reference' => $this->homes[0]->reference])
        ->assertJsonPath('data.data.0.user', ['public_id' => $this->officer->public_id, 'name' => $this->officer->name]);
});

it('keeps joins scoped even when a parent references a household outside the officers area', function () {
    $resident = Resident::factory()->create(['area_id' => $this->area->id, 'household_id' => $this->outside->id]);
    $this->actingAs($this->officer);
    $query = ['filter' => ['public_id' => $resident->public_id], 'include' => 'household'];
    $this->getJson('/api/community/residents?'.http_build_query($query))->assertOk()->assertJsonPath('data.data.0.household', null);
});

it('uses Spatie includes and public UUID filters on raw member queries', function () {
    $team = Team::query()->create(['area_id' => $this->area->id, 'name' => 'Regu Uji']);
    foreach ([$this->officer, $this->admin] as $user) {
        DB::connection('rukun')->table('engagement_team_members')->insert(['team_id' => $team->id, 'user_id' => $user->id, 'household_id' => $this->homes[0]->id]);
    }
    $this->actingAs($this->officer);
    $query = ['fields' => ['engagement_team_members' => 'user_id', 'users' => 'name'], 'include' => 'user', 'per_page' => 1];
    $first = $this->getJson('/api/engagement/teams/'.$team->public_id.'/members?'.http_build_query($query))->assertOk();
    expect(array_keys($first->json('data.data.0')))->toBe(['user_id', 'user']);
    $this->getJson($first->json('data.next_page_url'))->assertOk()->assertJsonCount(1, 'data.data');
    $query['filter'] = ['user_id' => $this->admin->public_id];
    $this->getJson('/api/engagement/teams/'.$team->public_id.'/members?'.http_build_query($query))->assertOk()->assertJsonPath('data.data.0.user.name', $this->admin->name);
});

it('projects derived billing fields without changing the underlying financial calculation', function () {
    $type = PaymentType::query()->create(['area_id' => $this->area->id, 'code' => 'QUERY', 'name' => 'Query fixture', 'collection_policy' => 'can_accumulate', 'fund_classification' => 'operational']);
    $tariff = Tariff::query()->create(['payment_type_id' => $type->id, 'amount' => 100000, 'starts_at' => '2026-01-01']);
    $invoice = Invoice::query()->create(['area_id' => $this->area->id, 'household_id' => $this->homes[0]->id, 'payment_type_id' => $type->id, 'tariff_id' => $tariff->id, 'period' => '2026-10-01', 'amount' => 100000, 'due_date' => '2026-10-28', 'settle_by' => '2026-10-28', 'collection_policy' => 'can_accumulate', 'fund_classification' => 'operational', 'state' => 'issued']);
    $this->actingAs($this->officer);
    $query = ['fields' => ['invoices' => 'public_id,payment_type_id,paid_amount,outstanding_amount', 'payment_types' => 'name'], 'include' => 'payment_type', 'filter' => ['amount' => '100000']];
    $this->getJson('/api/billing/invoices?'.http_build_query($query))->assertOk()->assertJsonPath('data.data.0', ['public_id' => $invoice->public_id, 'payment_type_id' => $type->public_id, 'paid_amount' => 0, 'outstanding_amount' => 100000, 'payment_type' => ['name' => 'Query fixture']]);
    foreach (['1.5', '100000 OR 1=1'] as $invalid) {
        $query['filter']['amount'] = $invalid;
        $this->getJson('/api/billing/invoices?'.http_build_query($query))->assertUnprocessable();
    }
});

it('preserves file UUID aliases and enforces ownership during sparse queries', function () {
    $file = StoredFile::factory()->create(['owner_id' => $this->officer->id]);
    $outside = StoredFile::factory()->create(['owner_id' => $this->admin->id]);
    $this->actingAs($this->officer);
    $query = ['fields' => ['files' => 'id,attachments_count'], 'filter' => ['id' => $file->public_id.','.$outside->public_id], 'sort' => '-id'];
    $this->getJson('/api/files?'.http_build_query($query))->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0', ['id' => $file->public_id, 'attachments_count' => 0]);
});
