<?php

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\DataTransfer\Enums\TransferStatus;
use Modules\DataTransfer\Jobs\ProcessDataTransfer;
use Modules\DataTransfer\Models\DataTransfer;
use Modules\Files\Services\FileStorageService;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
    Queue::fake();
    $this->seed(RbacSeeder::class);
});

function processTransfer(DataTransfer $transfer): void
{
    app()->call([new ProcessDataTransfer($transfer->public_id), 'handle']);
}

function xlsxUpload(array $rows): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'test-xlsx-').'.xlsx';
    $writer = new XlsxWriter;
    $writer->openToFile($path);
    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }
    $writer->close();

    return new UploadedFile($path, 'users.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

it('exports a registered data type asynchronously to a private CSV file', function () {
    $admin = User::factory()->create();
    $admin->givePermissionTo('data-transfers.create');
    User::factory()->create(['email' => 'exported@example.com']);
    Sanctum::actingAs($admin);

    $response = $this->withHeader('Idempotency-Key', 'export-users-'.fake()->uuid())
        ->postJson('/api/data-transfers/exports', ['type' => 'identity.users'])
        ->assertAccepted()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.direction', 'export');
    $transfer = DataTransfer::query()->where('public_id', $response->json('data.id'))->firstOrFail();
    Queue::assertPushed(ProcessDataTransfer::class, fn ($job) => $job->transferId === $transfer->public_id);

    processTransfer($transfer);

    $transfer->refresh()->load('outputFile');
    expect($transfer->status)->toBe(TransferStatus::Completed)
        ->and($transfer->outputFile)->not->toBeNull()
        ->and(Storage::disk('local')->get($transfer->outputFile->path))->toContain('exported@example.com');
    $this->getJson('/api/data-transfers/'.$transfer->public_id)
        ->assertOk()
        ->assertJsonPath('data.output_file_id', $transfer->outputFile->public_id);
});

it('imports valid rows and reports invalid CSV rows without failing the batch', function () {
    $admin = User::factory()->create();
    $admin->givePermissionTo('data-transfers.create');
    Sanctum::actingAs($admin);
    $csv = "name,email,status,locale\nImported User,imported@example.com,active,id\nMissing Email,,active,en\n";
    $input = app(FileStorageService::class)->store(
        UploadedFile::fake()->createWithContent('users.csv', $csv),
        $admin,
    );

    $response = $this->withHeader('Idempotency-Key', 'import-users-'.fake()->uuid())
        ->postJson('/api/data-transfers/imports', ['type' => 'identity.users', 'file_id' => $input->public_id])
        ->assertAccepted();
    $transfer = DataTransfer::query()->where('public_id', $response->json('data.id'))->firstOrFail();

    processTransfer($transfer);

    expect($transfer->refresh()->status)->toBe(TransferStatus::Completed)
        ->and($transfer->processed_rows)->toBe(2)
        ->and($transfer->successful_rows)->toBe(1)
        ->and($transfer->failed_rows)->toBe(1)
        ->and($transfer->error_summary)->toHaveCount(1)
        ->and(User::query()->where('email', 'imported@example.com')->value('locale'))->toBe('id');
});

it('exports and imports XLSX files using streaming readers and writers', function () {
    $admin = User::factory()->create();
    $admin->givePermissionTo('data-transfers.create');
    User::factory()->create(['email' => 'xlsx-export@example.com']);
    Sanctum::actingAs($admin);

    $response = $this->withHeader('Idempotency-Key', 'export-xlsx-'.fake()->uuid())
        ->postJson('/api/data-transfers/exports', ['type' => 'identity.users', 'format' => 'xlsx'])
        ->assertAccepted()
        ->assertJsonPath('data.format', 'xlsx');
    $export = DataTransfer::query()->where('public_id', $response->json('data.id'))->firstOrFail();
    processTransfer($export);
    $export->refresh()->load('outputFile');

    expect($export->status)->toBe(TransferStatus::Completed)
        ->and($export->outputFile->extension)->toBe('xlsx')
        ->and($export->outputFile->mime_type)->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $content = Storage::disk('local')->get($export->outputFile->path);
    $path = tempnam(sys_get_temp_dir(), 'read-xlsx-').'.xlsx';
    file_put_contents($path, $content);
    $reader = new XlsxReader;
    $reader->open($path);
    $values = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $values[] = $row->toArray();
        }
        break;
    }
    $reader->close();
    unlink($path);
    expect(collect($values)->flatten()->contains('xlsx-export@example.com'))->toBeTrue();

    $input = app(FileStorageService::class)->store(xlsxUpload([
        ['name', 'email', 'status', 'locale'],
        ['XLSX Imported', 'xlsx-imported@example.com', 'active', 'id'],
    ]), $admin);
    $importResponse = $this->withHeader('Idempotency-Key', 'import-xlsx-'.fake()->uuid())
        ->postJson('/api/data-transfers/imports', ['type' => 'identity.users', 'file_id' => $input->public_id])
        ->assertAccepted()
        ->assertJsonPath('data.format', 'xlsx');
    $import = DataTransfer::query()->where('public_id', $importResponse->json('data.id'))->firstOrFail();
    processTransfer($import);

    expect($import->refresh()->status)->toBe(TransferStatus::Completed)
        ->and($import->processed_rows)->toBe(1)
        ->and($import->successful_rows)->toBe(1)
        ->and(User::query()->where('email', 'xlsx-imported@example.com')->value('locale'))->toBe('id');
});

it('lists handler metadata and protects transfer ownership and cancellation', function () {
    $owner = User::factory()->create();
    $owner->givePermissionTo('data-transfers.create');
    Sanctum::actingAs($owner);

    $this->getJson('/api/data-transfers/types')->assertOk()
        ->assertJsonPath('data.types.0.key', 'identity.users')
        ->assertJsonPath('data.types.0.import', true)
        ->assertJsonPath('data.types.0.export', true);
    $this->getJson('/api/data-transfers/types')->assertJsonPath('data.types.0.formats', ['csv', 'xlsx']);
    $response = $this->withHeader('Idempotency-Key', 'cancel-export-'.fake()->uuid())
        ->postJson('/api/data-transfers/exports', ['type' => 'identity.users'])->assertAccepted();
    $id = $response->json('data.id');

    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/data-transfers/'.$id)->assertNotFound();

    Sanctum::actingAs($owner);
    $this->postJson('/api/data-transfers/'.$id.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->postJson('/api/data-transfers/'.$id.'/cancel')->assertStatus(409)->assertJsonPath('code', 'data_transfer.cannot_cancel');
});

it('requires permission and an idempotency key to start data transfers', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $this->withHeader('Idempotency-Key', 'export-denied-1')->postJson('/api/data-transfers/exports', ['type' => 'identity.users'])->assertForbidden();

    $user->givePermissionTo('data-transfers.create');
    $this->withHeader('Idempotency-Key', '')->postJson('/api/data-transfers/exports', ['type' => 'identity.users'])
        ->assertUnprocessable()->assertJsonValidationErrorFor('key');
});

it('prunes expired transfers and their generated export files', function () {
    config()->set('runtime-settings.data_transfer_retention_days', 30);
    cache()->forget('setting:data_transfers.retention_days');
    $owner = User::factory()->create();
    $output = app(FileStorageService::class)->store(UploadedFile::fake()->createWithContent('old.csv', "email\nold@example.com\n"), $owner);
    $transfer = DataTransfer::query()->create([
        'public_id' => fake()->uuid(),
        'user_id' => $owner->id,
        'type' => 'identity.users',
        'direction' => 'export',
        'status' => TransferStatus::Completed,
        'output_file_id' => $output->id,
        'finished_at' => now()->subDays(40),
        'created_at' => now()->subDays(40),
        'updated_at' => now()->subDays(40),
    ]);

    expect(Artisan::call('data-transfers:prune'))->toBe(0)
        ->and(DataTransfer::query()->whereKey($transfer->id)->exists())->toBeFalse()
        ->and($output->fresh()->trashed())->toBeTrue();
});
