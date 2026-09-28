<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Community\Handlers\PopulationHandler;
use Modules\Community\Models\AccountOperation;
use Modules\Community\Models\Area;
use Modules\Community\Models\Household;
use Modules\Community\Models\HouseholdMembership;
use Modules\Community\Models\Resident;
use Modules\Community\Models\RoleAssignment;
use Modules\Community\Models\Vendor;
use Modules\Community\Services\PopulationService;
use Modules\Community\Services\ScopeResolver;
use Modules\DataTransfer\Enums\TransferStatus;
use Modules\DataTransfer\Jobs\ProcessDataTransfer;
use Modules\DataTransfer\Models\DataTransfer;
use Modules\Files\Services\FileStorageService;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Spatie\Permission\Models\Role;

uses(DatabaseMigrations::class);

beforeEach(function () {
    Cache::store('redis')->getStore()->setPrefix('rukun-phase2-test:'.Str::uuid().':');
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
    $this->rt = Area::factory()->rt()->create();
    $this->otherRt = Area::factory()->rt()->create();
    $this->operator = User::factory()->create();
    $this->assignment = RoleAssignment::factory()->create(['user_id' => $this->operator->id, 'role_id' => Role::findByName('ketua-rt', 'web')->id, 'scope_type' => 'rt', 'area_id' => $this->rt->id, 'assigned_by' => $this->admin->id]);
    $this->household = Household::factory()->create(['area_id' => $this->rt->id]);
    $this->otherHousehold = Household::factory()->create(['area_id' => $this->otherRt->id]);
    $this->population = app(PopulationService::class);
});

function populationResident($test, ?Household $household = null, array $data = []): Resident
{
    return $test->population->createResident($test->admin, ['household_id' => ($household ?? $test->household)->public_id, 'name' => 'Warga Uji', 'relationship' => 'head', ...$data]);
}

it('creates households and multiple residents without creating login accounts', function () {
    $this->actingAs($this->operator);
    $hh = $this->postJson('/api/community/households', ['area_id' => $this->rt->public_id, 'address' => 'Jalan Warga 1', 'reference' => 'H-001'])->assertCreated()->assertJsonPath('data.status', 'active')->json('data.public_id');
    foreach (['head', 'spouse', 'child'] as $relationship) {
        $this->postJson('/api/community/residents', ['household_id' => $hh, 'name' => 'Resident '.$relationship, 'relationship' => $relationship])->assertCreated()->assertJsonPath('data.user_id', null)->assertJsonMissingPath('data.nik');
    }
    expect(Resident::query()->count())->toBe(3)->and(HouseholdMembership::query()->whereNull('ends_at')->count())->toBe(3)->and(User::query()->count())->toBe(2);
    $this->getJson('/api/community/households')->assertOk()->assertJsonCount(2, 'data.data');
    $this->postJson('/api/community/households', ['area_id' => $this->otherRt->public_id, 'address' => 'Forbidden'])->assertForbidden();
    $this->postJson('/api/community/residents', ['household_id' => $this->otherHousehold->public_id, 'name' => 'Forbidden', 'relationship' => 'child'])->assertForbidden();
    $this->patchJson('/api/community/households/'.$hh, ['status' => 'inactive'])->assertConflict();
});

it('encrypts identifiers, hides them by default and audits explicit sensitive access', function () {
    $resident = populationResident($this);
    $this->actingAs($this->operator);
    $nik = '3201010101010001';
    $kk = '3201010101010002';
    $this->putJson('/api/community/residents/'.$resident->public_id.'/sensitive', ['nik' => $nik])->assertOk();
    $this->putJson('/api/community/households/'.$this->household->public_id.'/sensitive', ['kk_number' => $kk])->assertOk();
    expect(DB::connection('rukun')->table('residents')->where('id', $resident->id)->value('nik'))->not->toContain($nik);
    expect(DB::connection('rukun')->table('households')->where('id', $this->household->id)->value('kk_number'))->not->toContain($kk);
    $this->getJson('/api/community/residents/'.$resident->public_id)->assertOk()->assertJsonMissingPath('data.nik')->assertJsonMissingPath('data.nik_hash');
    $this->getJson('/api/community/residents/'.$resident->public_id.'/sensitive')->assertOk()->assertJsonPath('data.nik', $nik)->assertHeader('Cache-Control', 'no-store, private');
    $this->getJson('/api/community/households/'.$this->household->public_id.'/sensitive')->assertOk()->assertJsonPath('data.kk_number', $kk);
    expect(DB::table('audit_events')->where('event', 'population.sensitive_viewed')->count())->toBe(2);
    expect(DB::table('audit_events')->pluck('metadata')->implode(''))->not->toContain($nik)->not->toContain($kk);
    $other = populationResident($this);
    $this->putJson('/api/community/residents/'.$other->public_id.'/sensitive', ['nik' => $nik])->assertUnprocessable();
    $this->putJson('/api/community/residents/'.$other->public_id.'/sensitive', ['nik' => '123'])->assertUnprocessable();
    $this->patchJson('/api/community/residents/'.$resident->public_id, ['nik' => $nik])->assertUnprocessable();
    $this->getJson('/api/community/households/'.$this->otherHousehold->public_id.'/sensitive')->assertForbidden();
    $reader = User::factory()->create();
    RoleAssignment::factory()->create(['user_id' => $reader->id, 'role_id' => Role::findByName('warga', 'web')->id, 'area_id' => $this->rt->id, 'scope_type' => 'rt']);
    $this->actingAs($reader)->getJson('/api/community/residents/'.$resident->public_id)->assertOk();
    $this->getJson('/api/community/residents/'.$resident->public_id.'/sensitive')->assertForbidden();
});

it('links and provisions separate accounts for members and prevents account reassignment', function () {
    $one = populationResident($this);
    $two = populationResident($this, null, ['relationship' => 'spouse']);
    $this->actingAs($this->operator);
    foreach ([$one, $two] as $index => $resident) {
        $response = $this->postJson('/api/community/residents/'.$resident->public_id.'/account', ['phone' => '08123456789'.$index], ['Idempotency-Key' => 'resident-account-'.$index])->assertCreated();
        $this->postJson('/api/community/residents/'.$resident->public_id.'/account', ['phone' => '08123456789'.$index], ['Idempotency-Key' => 'resident-account-'.$index])->assertCreated()->assertJsonPath('data.operation_id', $response->json('data.operation_id'));
    }
    expect($one->fresh()->user_id)->not->toBe($two->fresh()->user_id)->and(AccountOperation::query()->count())->toBe(2);
    $this->postJson('/api/community/residents/'.$one->public_id.'/account', ['phone' => '081234567899'], ['Idempotency-Key' => 'different-account-key'])->assertConflict();
    $third = populationResident($this);
    $existing = User::factory()->create();
    $this->postJson('/api/community/residents/'.$third->public_id.'/account', ['user_id' => $existing->public_id], ['Idempotency-Key' => 'link-existing-key'])->assertForbidden();
    $this->actingAs($this->admin)->postJson('/api/community/residents/'.$third->public_id.'/account', ['user_id' => $existing->public_id], ['Idempotency-Key' => 'link-existing-key'])->assertOk();
    $fourth = populationResident($this);
    $this->postJson('/api/community/residents/'.$fourth->public_id.'/account', ['user_id' => $existing->public_id], ['Idempotency-Key' => 'link-duplicate-key'])->assertUnprocessable();
});

it('requires explicit household recovery capability and revokes it when membership ends', function () {
    $manager = User::factory()->create();
    $target = User::factory()->create();
    $managerResident = populationResident($this);
    $targetResident = populationResident($this, null, ['relationship' => 'spouse']);
    foreach ([[$managerResident, $manager], [$targetResident, $target]] as [$resident,$user]) {
        $this->population->account($this->admin, $resident, 'link-'.$user->public_id, ['user_id' => $user->public_id]);
    }
    $this->actingAs($manager)->getJson('/api/community/residents/'.$targetResident->public_id)->assertOk();
    $this->getJson('/api/community/households/'.$this->otherHousehold->public_id)->assertForbidden();
    $this->postJson('/api/community/accounts/'.$target->public_id.'/recover', [], ['Idempotency-Key' => 'family-without-capability'])->assertForbidden();
    $grant = $this->actingAs($this->admin)->postJson('/api/community/role-assignments', ['user_id' => $manager->public_id, 'role' => 'household-account-manager', 'scope_type' => 'household', 'household_id' => $this->household->public_id, 'starts_at' => now()->subMinute()->toISOString()], ['Idempotency-Key' => 'grant-family-manager'])->assertCreated()->json('data.public_id');
    $target->forceFill(['must_change_password' => true])->save();
    $response = $this->actingAs($manager)->postJson('/api/community/accounts/'.$target->public_id.'/recover', [], ['Idempotency-Key' => 'family-valid-recovery'])->assertCreated();
    $this->get($response->json('data.credential_url'))->assertOk();
    $target->createToken('old-device');
    $this->actingAs($this->operator)->putJson('/api/community/residents/'.$targetResident->public_id.'/membership', ['household_id' => $this->otherHousehold->public_id, 'relationship' => 'other'])->assertForbidden();
    $this->population->move($this->admin, $managerResident->fresh(), $this->otherHousehold, 'head');
    expect(RoleAssignment::query()->where('public_id', $grant)->value('status'))->toBe('revoked');
    $this->actingAs($manager)->getJson('/api/community/households/'.$this->household->public_id)->assertForbidden();
    $this->postJson('/api/community/accounts/'.$target->public_id.'/recover', [], ['Idempotency-Key' => 'family-after-move'])->assertForbidden();
    $this->population->move($this->admin, $targetResident->fresh(), $this->otherHousehold, 'spouse');
    expect(app(ScopeResolver::class)->accountArea($target)->id)->toBe($this->otherRt->id)->and($target->tokens()->count())->toBe(0);
    expect(HouseholdMembership::query()->where('resident_id', $targetResident->id)->count())->toBe(2)->and(HouseholdMembership::query()->where('resident_id', $targetResident->id)->whereNull('ends_at')->count())->toBe(1);
    $this->actingAs($this->admin)->getJson('/api/community/residents/'.$targetResident->public_id.'/memberships')->assertOk()->assertJsonCount(2, 'data.data');
    $this->population->updateResident($this->admin, $targetResident->fresh(), ['status' => 'deceased']);
    expect($targetResident->fresh()->household_id)->toBeNull()->and(app(ScopeResolver::class)->accountArea($target))->toBeNull();
});

it('limits temporal vendor assignments to the assigned vendor without granting population access', function () {
    $vendor = Vendor::query()->create(['name' => 'Vendor One']);
    Vendor::query()->create(['name' => 'Vendor Two']);
    $user = User::factory()->create();
    $assignment = $this->actingAs($this->admin)->postJson('/api/community/role-assignments', ['user_id' => $user->public_id, 'role' => 'vendor-wifi', 'scope_type' => 'vendor', 'vendor_id' => $vendor->public_id, 'starts_at' => now()->subMinute()->toISOString(), 'ends_at' => now()->addDay()->toISOString()], ['Idempotency-Key' => 'vendor-role-scope'])->assertCreated()->json('data.public_id');
    $this->actingAs($user)->getJson('/api/community/vendors')->assertOk()->assertJsonCount(1, 'data.data');
    $this->getJson('/api/community/households')->assertOk()->assertJsonCount(0, 'data.data');
    $this->patchJson('/api/community/vendors/'.$vendor->public_id, ['name' => 'No'])->assertForbidden();
    RoleAssignment::query()->where('public_id', $assignment)->update(['ends_at' => now()->subSecond(), 'starts_at' => now()->subDay()]);
    $this->getJson('/api/community/vendors')->assertOk()->assertJsonCount(0, 'data.data');
});

it('imports rows atomically, reports errors and retries without duplicate residents or accounts', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
    Queue::fake();
    $csv = "household_reference,resident_reference,household_address,resident_name,relationship,create_account,phone\nH-PILOT,R-1,Jalan Pilot,Ayu,head,1,081234567801\nH-PILOT,R-2,Jalan Pilot,Budi,spouse,0,\nH-FAIL,R-3,Jalan Gagal,Missing Login,head,1,\n";
    $file = app(FileStorageService::class)->store(UploadedFile::fake()->createWithContent('population.csv', $csv), $this->operator);
    $this->actingAs($this->operator);
    $this->postJson('/api/data-transfers/exports', ['type' => 'identity.users'], ['Idempotency-Key' => 'no-global-export'])->assertForbidden();
    $payload = ['area_id' => $this->rt->public_id, 'file_id' => $file->public_id];
    $response = $this->postJson('/api/community/population/imports', $payload, ['Idempotency-Key' => 'population-import-1'])->assertAccepted();
    $this->postJson('/api/community/population/imports', $payload, ['Idempotency-Key' => 'population-import-1'])->assertAccepted()->assertJsonPath('data.id', $response->json('data.id'));
    $transfer = DataTransfer::query()->where('public_id', $response->json('data.id'))->firstOrFail();
    app()->call([new ProcessDataTransfer($transfer->public_id), 'handle']);
    expect($transfer->refresh()->status)->toBe(TransferStatus::Completed)->and($transfer->successful_rows)->toBe(2)->and($transfer->failed_rows)->toBe(1);
    expect(Household::query()->where('reference', 'H-FAIL')->exists())->toBeFalse()->and(Resident::query()->count())->toBe(2)->and(AccountOperation::query()->count())->toBe(1);
    $this->getJson('/api/community/population/transfers/'.$transfer->public_id.'/results')->assertOk()->assertJsonCount(2, 'data.data');
    expect($this->get('/api/community/population/transfers/'.$transfer->public_id.'/errors')->assertOk()->streamedContent())->toContain('4,')->not->toContain('Missing Login');
    $second = $this->postJson('/api/community/population/imports', $payload, ['Idempotency-Key' => 'population-import-2'])->assertAccepted()->json('data.id');
    app()->call([new ProcessDataTransfer($second), 'handle']);
    expect(Resident::query()->count())->toBe(2)->and(AccountOperation::query()->count())->toBe(1)->and(HouseholdMembership::query()->count())->toBe(2);
    $this->postJson('/api/community/population/imports', [...$payload, 'area_id' => $this->otherRt->public_id], ['Idempotency-Key' => 'population-wrong-rt'])->assertForbidden();
    $this->assignment->update(['status' => 'revoked']);
    $this->postJson('/api/community/population/imports', $payload, ['Idempotency-Key' => 'population-import-1'])->assertForbidden();
    $this->get('/api/community/population/transfers/'.$transfer->public_id.'/errors')->assertForbidden();
});

it('exports only the selected RT and denies output downloads after scope revocation', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
    Queue::fake();
    $resident = populationResident($this, null, ['name' => 'Visible Resident']);
    populationResident($this, $this->otherHousehold, ['name' => 'Other RT']);
    $this->population->sensitive($this->admin, $resident, '3201010101010001');
    $response = $this->actingAs($this->operator)->postJson('/api/community/population/exports', ['area_id' => $this->rt->public_id], ['Idempotency-Key' => 'population-export-1'])->assertAccepted();
    $transfer = DataTransfer::query()->where('public_id', $response->json('data.id'))->firstOrFail();
    app()->call([new ProcessDataTransfer($transfer->public_id), 'handle']);
    $transfer->refresh()->load('outputFile');
    expect($transfer->status)->toBe(TransferStatus::Completed)->and($transfer->successful_rows)->toBe(1);
    expect(Storage::disk('local')->get($transfer->outputFile->path))->toContain('Visible Resident')->not->toContain('Other RT')->not->toContain('3201010101010001')->not->toContain('nik');
    $this->get('/api/files/'.$transfer->outputFile->public_id.'/download')->assertOk();
    $transfer->outputFile->update(['metadata' => []]);
    $transfer->delete();
    $this->assignment->update(['status' => 'revoked']);
    $this->get('/api/files/'.$transfer->outputFile->public_id.'/download')->assertNotFound();
});

it('rejects import payload changes and sensitive columns without changing existing records', function () {
    $handler = app(PopulationHandler::class);
    $row = ['household_reference' => 'H-X', 'resident_reference' => 'R-X', 'household_address' => 'Jalan X', 'resident_name' => 'X', 'relationship' => 'head', 'create_account' => '0'];
    $options = ['area_id' => $this->rt->public_id];
    $handler->importRow($this->operator, $row, $options);
    expect(fn () => $handler->importRow($this->operator, [...$row, 'resident_name' => 'Changed'], $options))->toThrow(ValidationException::class);
    expect(fn () => $handler->importRow($this->operator, [...$row, 'nik' => '3201010101010001'], $options))->toThrow(ValidationException::class);
    expect(Resident::query()->where('reference', 'R-X')->value('name'))->toBe('X');
});

it('processes XLSX and reconciles pilot household membership and account totals', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
    Queue::fake();
    $path = tempnam(sys_get_temp_dir(), 'population-xlsx-');
    try {
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(app(PopulationHandler::class)->importColumns()));
        foreach ([['P-1', 'W-1', 'Jalan 1', 'Satu', 'head', '0'], ['P-1', 'W-2', 'Jalan 1', 'Dua', 'child', '0'], ['P-2', 'W-3', 'Jalan 2', 'Tiga', 'head', '0']] as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();
        $file = app(FileStorageService::class)->store(new UploadedFile($path, 'population.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true), $this->operator);
        $id = $this->actingAs($this->operator)->postJson('/api/community/population/imports', ['area_id' => $this->rt->public_id, 'file_id' => $file->public_id], ['Idempotency-Key' => 'population-xlsx-import'])->assertAccepted()->json('data.id');
        app()->call([new ProcessDataTransfer($id), 'handle']);
        $transfer = DataTransfer::query()->where('public_id', $id)->firstOrFail();
        expect($transfer->status)->toBe(TransferStatus::Completed)->and($transfer->successful_rows)->toBe(3)->and($transfer->failed_rows)->toBe(0);
        expect(Household::query()->whereIn('reference', ['P-1', 'P-2'])->count())->toBe(2)->and(Resident::query()->count())->toBe(3)->and(HouseholdMembership::query()->whereNull('ends_at')->count())->toBe(3)->and(Resident::query()->whereNotNull('user_id')->count())->toBe(0);
        $export = $this->postJson('/api/community/population/exports', ['area_id' => $this->rt->public_id, 'format' => 'xlsx'], ['Idempotency-Key' => 'population-xlsx-export'])->assertAccepted()->json('data.id');
        app()->call([new ProcessDataTransfer($export), 'handle']);
        $transfer = DataTransfer::query()->where('public_id', $export)->firstOrFail();
        expect($transfer->status)->toBe(TransferStatus::Completed)->and($transfer->successful_rows)->toBe(3);
        $reader = new Reader;
        $reader->open(Storage::disk('local')->path($transfer->outputFile->path));
        $rows = [];
        foreach ($reader->getSheetIterator()->current()->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
        $reader->close();
        expect($rows)->toHaveCount(4)->and($rows[0])->toBe(app(PopulationHandler::class)->columns());
    } finally {
        @unlink($path);
    }
});

it('rechecks queued transfer authorization and rejects forged handler options through Core', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
    Queue::fake();
    populationResident($this);
    $id = $this->actingAs($this->operator)->postJson('/api/community/population/exports', ['area_id' => $this->rt->public_id], ['Idempotency-Key' => 'queued-before-revoke'])->assertAccepted()->json('data.id');
    $this->assignment->update(['status' => 'revoked']);
    app()->call([new ProcessDataTransfer($id), 'handle']);
    $transfer = DataTransfer::query()->where('public_id', $id)->firstOrFail();
    expect($transfer->status)->toBe(TransferStatus::Failed)->and($transfer->output_file_id)->toBeNull();
    $this->actingAs($this->admin)->postJson('/api/data-transfers/exports', ['type' => 'community.population', 'options' => ['area_id' => $this->rt->public_id, '_transfer_id' => (string) Str::uuid()]], ['Idempotency-Key' => 'forged-transfer-options'])->assertUnprocessable();
});

it('combines RW inheritance with temporal multiple assignments and paginates using public IDs', function () {
    $sibling = Area::factory()->rt()->create(['parent_id' => $this->rt->parent_id]);
    Household::factory()->create(['area_id' => $sibling->id]);
    $this->assignment->update(['scope_type' => 'rw', 'area_id' => $this->rt->parent_id]);
    $this->actingAs($this->operator)->getJson('/api/community/households')->assertOk()->assertJsonCount(2, 'data.data');
    $second = RoleAssignment::factory()->create(['user_id' => $this->operator->id, 'role_id' => Role::findByName('sekretaris-rt', 'web')->id, 'scope_type' => 'rt', 'area_id' => $this->otherRt->id]);
    $first = $this->getJson('/api/community/households?per_page=2')->assertOk()->assertJsonCount(2, 'data.data');
    $next = $this->getJson('/api/community/households?per_page=2&cursor='.urlencode($first->json('data.next_cursor')))->assertOk()->assertJsonCount(1, 'data.data');
    expect(collect([...$first->json('data.data'), ...$next->json('data.data')])->pluck('public_id')->unique())->toHaveCount(3);
    $second->update(['starts_at' => now()->addDay()]);
    $this->getJson('/api/community/households/'.$this->otherHousehold->public_id)->assertForbidden();
});

it('retains independent office roles when closing an already detached resident and preserves relationship history', function () {
    $resident = populationResident($this);
    $user = User::factory()->create();
    $this->population->account($this->admin, $resident, 'link-detached-account', ['user_id' => $user->public_id]);
    $office = RoleAssignment::factory()->create(['user_id' => $user->id, 'area_id' => $this->rt->id]);
    $this->population->move($this->admin, $resident->fresh(), $this->household, 'spouse');
    expect(HouseholdMembership::query()->where('resident_id', $resident->id)->count())->toBe(2);
    $this->population->move($this->admin, $resident->fresh(), null);
    $this->population->updateResident($this->admin, $resident->fresh(), ['status' => 'inactive']);
    expect($office->fresh()->status)->toBe('active')->and($resident->fresh()->household_id)->toBeNull();
});

it('enforces one active membership per resident in PostgreSQL', function () {
    $resident = populationResident($this);
    expect(fn () => HouseholdMembership::query()->create(['resident_id' => $resident->id, 'household_id' => $this->otherHousehold->id, 'relationship' => 'other', 'starts_at' => now()]))->toThrow(UniqueConstraintViolationException::class);
    expect(HouseholdMembership::query()->where('resident_id', $resident->id)->whereNull('ends_at')->count())->toBe(1);
});
