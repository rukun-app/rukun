<?php

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Files\Jobs\DeleteStoredFile;
use Modules\Files\Models\Attachment;
use Modules\Files\Models\StoredFile;
use Modules\Files\Services\FileStorageService;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
    $this->seed(RbacSeeder::class);
});

it('uploads a private file without exposing storage internals', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->post('/api/files', [
        'file' => UploadedFile::fake()->create('report.pdf', 12, 'application/pdf'),
        'display_name' => 'Quarterly Report.pdf',
        'metadata' => ['category' => 'report'],
    ], ['Accept' => 'application/json'])->assertCreated()
        ->assertJsonPath('data.display_name', 'Quarterly Report.pdf')
        ->assertJsonMissingPath('data.path')
        ->assertJsonMissingPath('data.disk')
        ->assertJsonMissingPath('data.checksum')
        ->assertJsonMissingPath('data.owner_id');

    $file = StoredFile::query()->where('public_id', $response->json('data.id'))->firstOrFail();
    Storage::disk('local')->assertExists($file->path);
    expect($file->visibility)->toBe('private')
        ->and($file->checksum)->toHaveLength(64);
    $this->assertDatabaseHas('audit_events', ['event' => 'file.uploaded', 'subject_id' => $file->id]);
});

it('validates upload size and server detected mime type', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->post('/api/files', [
        'file' => UploadedFile::fake()->create('script.html', 1, 'text/html'),
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');

    $this->post('/api/files', [
        'file' => UploadedFile::fake()->create('large.pdf', 11 * 1024, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');

    $this->post('/api/files', [
        'file' => UploadedFile::fake()->create('report.pdf', 1, 'application/pdf'),
        'metadata' => ['nested' => ['not' => 'allowed']],
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('metadata.nested');
});

it('lists only owned files with cursor pagination and filters', function () {
    $owner = User::factory()->create();
    StoredFile::factory()->count(3)->for($owner, 'owner')->create(['display_name' => 'Invoice document']);
    StoredFile::factory()->for(User::factory(), 'owner')->create(['display_name' => 'Invoice hidden']);
    Sanctum::actingAs($owner);

    $this->getJson('/api/files?search=invoice&mime_type=text/plain&per_page=2')->assertOk()
        ->assertJsonCount(2, 'data.data')
        ->assertJsonMissingPath('data.current_page');
});

it('hides files from other users and allows explicitly authorized administrators', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $file = StoredFile::factory()->for($owner, 'owner')->create();

    Sanctum::actingAs($other);
    $this->getJson("/api/files/{$file->public_id}")->assertNotFound();
    $this->get("/api/files/{$file->public_id}/download", ['Accept' => 'application/json'])->assertNotFound();

    Sanctum::actingAs($admin);
    $this->getJson("/api/files/{$file->public_id}")->assertOk()->assertJsonPath('data.id', $file->public_id);
});

it('updates metadata and downloads stored content', function () {
    $owner = User::factory()->create();
    $file = StoredFile::factory()->for($owner, 'owner')->create([
        'path' => 'files/document.txt',
        'display_name' => 'document.txt',
    ]);
    Storage::disk('local')->put($file->path, 'document');
    Sanctum::actingAs($owner);

    $this->patchJson("/api/files/{$file->public_id}", [
        'display_name' => 'renamed.txt',
        'metadata' => ['category' => 'notes'],
    ])->assertOk()->assertJsonPath('data.display_name', 'renamed.txt');

    $this->get("/api/files/{$file->public_id}/download")->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename=renamed.txt');
    $this->assertDatabaseHas('audit_events', ['event' => 'file.updated', 'subject_id' => $file->id]);
});

it('soft deletes and queues idempotent physical cleanup', function () {
    Queue::fake();
    $owner = User::factory()->create();
    $file = StoredFile::factory()->for($owner, 'owner')->create(['path' => 'files/remove.txt']);
    Storage::disk('local')->put($file->path, 'remove');
    Sanctum::actingAs($owner);

    $this->deleteJson("/api/files/{$file->public_id}")->assertOk();
    $this->assertSoftDeleted('files', ['id' => $file->id]);
    Queue::assertPushed(DeleteStoredFile::class, fn (DeleteStoredFile $job) => $job->publicId === $file->public_id && $job->queue === 'low');

    (new DeleteStoredFile($file->public_id, 'local', $file->path))->handle();
    Storage::disk('local')->assertMissing($file->path);
    $this->assertDatabaseMissing('files', ['id' => $file->id]);
    (new DeleteStoredFile($file->public_id, 'local', $file->path))->handle();
});

it('removes the stored object when database persistence fails', function () {
    $missingOwner = new User;
    $missingOwner->id = PHP_INT_MAX;
    $upload = UploadedFile::fake()->create('orphan.pdf', 1, 'application/pdf');

    expect(fn () => app(FileStorageService::class)->store($upload, $missingOwner))->toThrow(QueryException::class);
    expect(Storage::disk('local')->allFiles('files'))->toBeEmpty();
});

it('rejects deletion while a file is attached', function () {
    $owner = User::factory()->create();
    $file = StoredFile::factory()->for($owner, 'owner')->create();
    Attachment::query()->create([
        'file_id' => $file->id,
        'attachable_type' => User::class,
        'attachable_id' => $owner->id,
        'collection' => 'documents',
        'created_by' => $owner->id,
    ]);
    Sanctum::actingAs($owner);

    $this->deleteJson("/api/files/{$file->public_id}")->assertUnprocessable()
        ->assertJsonPath('message', 'Attached files must be detached before deletion.');
});
