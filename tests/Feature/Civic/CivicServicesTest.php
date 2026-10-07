<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Civic\Models\CivicCase;
use Modules\Civic\Services\CivicService;
use Modules\Community\Models\Area;
use Modules\Community\Models\Household;
use Modules\Community\Models\RoleAssignment;
use Modules\Community\Services\PopulationService;
use Modules\Files\Models\StoredFile;
use Modules\Notifications\Models\NotificationPreference;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(DatabaseMigrations::class);

beforeEach(function () {
    Cache::store('redis')->getStore()->setPrefix('civic-test:'.Str::uuid().':');
    config()->set('broadcasting.default', 'null');
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');
    $this->rt = Area::factory()->rt()->create();
    $this->otherRt = Area::factory()->rt()->create(['parent_id' => $this->rt->parent_id]);
    $this->home = Household::factory()->create(['area_id' => $this->rt->id]);
    $this->officer = User::factory()->create();
    $this->assignment = RoleAssignment::factory()->create(['user_id' => $this->officer->id, 'role_id' => Role::findByName('ketua-rt', 'web')->id, 'scope_type' => 'rt', 'area_id' => $this->rt->id]);
    $this->otherOfficer = User::factory()->create();
    RoleAssignment::factory()->create(['user_id' => $this->otherOfficer->id, 'role_id' => Role::findByName('ketua-rt', 'web')->id, 'scope_type' => 'rt', 'area_id' => $this->otherRt->id]);
    $this->citizen = User::factory()->create();
    $this->family = User::factory()->create();
    foreach ([$this->citizen, $this->family] as $index => $user) {
        $resident = app(PopulationService::class)->createResident($this->admin, ['name' => 'Warga '.$index, 'household_id' => $this->home->public_id, 'relationship' => $index ? 'spouse' : 'head']);
        app(PopulationService::class)->account($this->admin, $resident, 'civic-link-'.$index, ['user_id' => $user->public_id]);
    }
    $this->service = app(CivicService::class);
    $this->payload = ['household_id' => $this->home->public_id, 'category' => 'Lingkungan', 'description' => 'Laporan pribadi warga'];
});

it('scopes announcements, tracks reads per user and publishes only once', function () {
    $data = ['area_id' => $this->rt->public_id, 'title' => 'Kerja bakti', 'body' => 'Minggu pagi'];
    $id = $this->actingAs($this->officer)->postJson('/api/civic/announcements', $data, ['Idempotency-Key' => 'announcement-create'])->assertCreated()->json('data.public_id');
    $path = '/api/civic/announcements/'.$id;
    $this->actingAs($this->citizen)->getJson($path)->assertNotFound();
    $this->actingAs($this->otherOfficer)->postJson($path.'/publish', [], ['Idempotency-Key' => 'publish-other'])->assertNotFound();
    $this->actingAs($this->officer)->postJson($path.'/publish', [], ['Idempotency-Key' => 'publish-first'])->assertOk()->assertJsonPath('data.status', 'published');
    $notices = DB::connection('core')->table('notifications')->count();
    $this->postJson($path.'/publish', [], ['Idempotency-Key' => 'publish-repeated'])->assertOk();
    expect(DB::connection('core')->table('notifications')->count())->toBe($notices);
    $this->actingAs($this->citizen)->getJson($path)->assertOk()->assertJsonPath('data.read_at', null)->assertJsonMissingPath('data.id');
    $this->postJson($path.'/read')->assertOk();
    $this->getJson('/api/civic/announcements?read=1')->assertJsonCount(1, 'data.data');
    $this->getJson('/api/civic/announcements?read=0')->assertJsonCount(0, 'data.data');
    $this->actingAs($this->family)->getJson($path)->assertJsonPath('data.read_at', null);
    $this->actingAs($this->citizen)->postJson($path.'/unread')->assertJsonPath('data.read_at', null);
    $this->actingAs($this->otherOfficer)->getJson($path)->assertNotFound();
    $this->actingAs($this->officer)->postJson($path.'/archive', [], ['Idempotency-Key' => 'archive-first'])->assertOk();
    $this->actingAs($this->citizen)->getJson($path)->assertNotFound();
});

it('delivers RW announcements to RT officers and members without exposing RW drafts', function () {
    $rw = Area::query()->findOrFail($this->rt->parent_id);
    $row = $this->service->announcement($this->admin, 'rw-announcement', ['area_id' => $rw->public_id, 'title' => 'Rapat RW', 'body' => 'Undangan']);
    $path = '/api/civic/announcements/'.$row->public_id;
    $this->actingAs($this->otherOfficer)->getJson($path)->assertNotFound();
    $this->service->publish($this->admin, $row, 'rw-publish');
    $this->getJson($path)->assertOk();
    $this->actingAs($this->citizen)->getJson($path)->assertOk();
    $this->postJson($path.'/archive', [], ['Idempotency-Key' => 'unauthorized-archive'])->assertForbidden();
});

it('publishes due announcements and skips authors whose authority was revoked', function () {
    $this->travelTo(now()->startOfMinute());
    $data = ['area_id' => $this->rt->public_id, 'title' => 'Terjadwal', 'body' => 'Informasi', 'publish_at' => now()->addHour()->toISOString()];
    $row = $this->service->announcement($this->officer, 'schedule-first', $data);
    $this->artisan('civic:publish-due')->assertSuccessful();
    expect($row->fresh()->status)->toBe('scheduled');
    $this->travel(61)->minutes();
    $this->artisan('civic:publish-due')->assertSuccessful();
    expect($row->fresh()->status)->toBe('published');
    $second = $this->service->announcement($this->officer, 'schedule-second', $data);
    $this->assignment->update(['status' => 'revoked']);
    $this->artisan('civic:publish-due')->assertSuccessful();
    expect($second->fresh()->status)->toBe('scheduled');
});

it('keeps reports private and rejects household impersonation and changed replay payloads', function () {
    $this->actingAs($this->citizen)->postJson('/api/civic/reports', $this->payload)->assertUnprocessable();
    $id = $this->postJson('/api/civic/reports', $this->payload, ['Idempotency-Key' => 'report-create'])->assertCreated()->assertJsonPath('data.version', 1)->json('data.public_id');
    $this->postJson('/api/civic/reports', $this->payload, ['Idempotency-Key' => 'report-create'])->assertCreated()->assertJsonPath('data.public_id', $id);
    $this->postJson('/api/civic/reports', [...$this->payload, 'description' => 'Changed'], ['Idempotency-Key' => 'report-create'])->assertConflict();
    $otherHome = Household::factory()->create(['area_id' => $this->rt->id]);
    $this->postJson('/api/civic/reports', [...$this->payload, 'household_id' => $otherHome->public_id], ['Idempotency-Key' => 'other-home-create'])->assertForbidden();
    foreach ([$this->family, $this->otherOfficer] as $user) {
        $this->actingAs($user)->getJson('/api/civic/reports/'.$id)->assertNotFound();
        $this->getJson('/api/civic/reports')->assertJsonCount(0, 'data.data');
        $this->getJson('/api/civic/reports/'.$id.'/timeline')->assertNotFound();
    }
    $this->actingAs($this->officer)->getJson('/api/civic/reports/'.$id)->assertOk();
    $this->getJson('/api/civic/letter-requests/'.$id)->assertNotFound();
});

it('enforces report lifecycle, scoped assignment, stale versions and immutable history', function () {
    $row = $this->service->createCase($this->citizen, 'report', 'case-create', $this->payload);
    $path = '/api/civic/reports/'.$row->public_id.'/actions';
    $this->actingAs($this->officer)->postJson($path, ['version' => 1, 'action' => 'assign', 'assigned_to' => $this->otherOfficer->public_id], ['Idempotency-Key' => 'bad-assignee'])->assertUnprocessable();
    $this->postJson($path, ['version' => 1, 'action' => 'assign', 'assigned_to' => $this->officer->public_id], ['Idempotency-Key' => 'good-assignee'])->assertOk()->assertJsonPath('data.version', 2);
    $this->postJson($path, ['version' => 2, 'action' => 'resolved'], ['Idempotency-Key' => 'skip-work-status'])->assertConflict();
    $data = ['version' => 2, 'action' => 'in_progress'];
    $this->postJson($path, $data, ['Idempotency-Key' => 'start-work'])->assertOk()->assertJsonPath('data.version', 3);
    $this->postJson($path, $data, ['Idempotency-Key' => 'start-work'])->assertOk()->assertJsonPath('data.version', 3);
    $this->postJson($path, $data, ['Idempotency-Key' => 'stale-work'])->assertConflict();
    $this->actingAs($this->citizen)->postJson($path, ['version' => 3, 'action' => 'resolved'], ['Idempotency-Key' => 'citizen-resolve'])->assertForbidden();
    $this->postJson($path, ['version' => 3, 'action' => 'comment'], ['Idempotency-Key' => 'empty-comment'])->assertUnprocessable();
    $this->actingAs($this->officer)->postJson($path, ['version' => 3, 'action' => 'resolved', 'note' => 'Sudah diperbaiki'], ['Idempotency-Key' => 'resolve-work'])->assertOk()->assertJsonPath('data.status', 'resolved');
    $this->postJson($path, ['version' => 4, 'action' => 'comment', 'note' => 'Too late'], ['Idempotency-Key' => 'terminal-comment'])->assertConflict();
    $this->getJson('/api/civic/reports/'.$row->public_id.'/timeline?per_page=2')->assertOk()->assertJsonCount(2, 'data.data')->assertJsonMissingPath('data.data.0.id');
    expect(DB::connection('rukun')->table('civic_timeline')->where('case_id', $row->id)->count())->toBe(4);
    expect(fn () => DB::connection('rukun')->table('civic_timeline')->where('case_id', $row->id)->update(['note' => 'Rewrite']))->toThrow(QueryException::class);
});

it('protects private attachments even from global file viewers and locks attached files', function () {
    $file = StoredFile::factory()->create(['owner_id' => $this->citizen->id, 'mime_type' => 'application/pdf']);
    $row = $this->service->createCase($this->citizen, 'report', 'private-report', [...$this->payload, 'attachments' => [$file->public_id]]);
    $this->family->givePermissionTo(['files.view-any', 'files.download-any']);
    $this->actingAs($this->family)->getJson('/api/files/'.$file->public_id)->assertNotFound();
    $this->actingAs($this->officer)->getJson('/api/files/'.$file->public_id)->assertOk();
    $this->actingAs($this->citizen)->deleteJson('/api/files/'.$file->public_id)->assertNotFound();
    $this->postJson('/api/civic/reports', [...$this->payload, 'attachments' => [$file->public_id]], ['Idempotency-Key' => 'reuse-private-file'])->assertConflict();
    $this->actingAs($this->family)->postJson('/api/civic/reports', [...$this->payload, 'attachments' => [$file->public_id]], ['Idempotency-Key' => 'borrow-private-file'])->assertNotFound();
    $this->assignment->update(['status' => 'revoked']);
    $this->actingAs($this->officer)->getJson('/api/files/'.$file->public_id)->assertNotFound();
    $this->getJson('/api/civic/reports/'.$row->public_id)->assertNotFound();
});

it('requires independent letter review and a private PDF output before approval', function () {
    $row = $this->service->createCase($this->citizen, 'letter', 'letter-create', $this->payload);
    $path = '/api/civic/letter-requests/'.$row->public_id.'/actions';
    $this->actingAs($this->officer)->postJson($path, ['version' => 1, 'action' => 'reviewing'], ['Idempotency-Key' => 'letter-review'])->assertOk();
    $this->postJson($path, ['version' => 2, 'action' => 'approved'], ['Idempotency-Key' => 'no-letter-output'])->assertUnprocessable();
    $image = StoredFile::factory()->create(['owner_id' => $this->officer->id, 'mime_type' => 'image/png']);
    $this->postJson($path, ['version' => 2, 'action' => 'approved', 'output_file_id' => $image->public_id], ['Idempotency-Key' => 'image-letter-output'])->assertUnprocessable();
    $pdf = StoredFile::factory()->create(['owner_id' => $this->officer->id, 'mime_type' => 'application/pdf']);
    $this->postJson($path, ['version' => 2, 'action' => 'approved', 'output_file_id' => $pdf->public_id], ['Idempotency-Key' => 'pdf-letter-output'])->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.documents.0.purpose', 'output');
    $this->actingAs($this->citizen)->getJson('/api/files/'.$pdf->public_id)->assertOk();
    $this->actingAs($this->family)->getJson('/api/files/'.$pdf->public_id)->assertNotFound();
    $this->citizen->assignRole('super-admin');
    $self = $this->service->createCase($this->citizen, 'letter', 'self-letter-create', $this->payload);
    $this->service->action($this->citizen, $self, 'self-letter-review', ['version' => 1, 'action' => 'reviewing']);
    $this->actingAs($this->citizen)->postJson('/api/civic/letter-requests/'.$self->public_id.'/actions', ['version' => 2, 'action' => 'rejected', 'note' => 'Review sendiri'], ['Idempotency-Key' => 'self-reject-letter'])->assertForbidden();
});

it('allows cancellation only by the submitter before processing and rechecks revoked replay authority', function () {
    $row = $this->service->createCase($this->citizen, 'report', 'cancel-create', $this->payload);
    $path = '/api/civic/reports/'.$row->public_id.'/actions';
    $data = ['version' => 1, 'action' => 'cancel'];
    $this->actingAs($this->officer)->postJson($path, $data, ['Idempotency-Key' => 'officer-cancel'])->assertForbidden();
    $this->actingAs($this->citizen)->postJson($path, $data, ['Idempotency-Key' => 'citizen-cancel'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->postJson($path, $data, ['Idempotency-Key' => 'citizen-cancel'])->assertOk();
    $this->citizen->forceFill(['must_change_password' => true])->save();
    $this->postJson($path, $data, ['Idempotency-Key' => 'citizen-cancel'])->assertForbidden();
});

it('stores generic notifications and replay events without private report text and respects inbox preferences', function () {
    NotificationPreference::query()->create(['user_id' => $this->officer->id, 'category' => 'civic', 'database_enabled' => false, 'mail_enabled' => false]);
    $row = $this->service->createCase($this->citizen, 'report', 'notice-create', $this->payload);
    $notifications = DB::connection('core')->table('notifications')->get();
    expect($notifications->where('notifiable_id', $this->officer->id))->toHaveCount(0)
        ->and($notifications->where('notifiable_id', $this->citizen->id))->toHaveCount(1)
        ->and($notifications->where('notifiable_id', $this->family->id))->toHaveCount(0)
        ->and($notifications->toJson())->not->toContain($this->payload['description']);
    $events = DB::connection('core')->table('user_events')->where('type', 'civic.updated')->get();
    expect($events->toJson())->not->toContain($this->payload['description'])->and($events->where('user_id', $this->otherOfficer->id))->toHaveCount(0);
    $this->actingAs($this->citizen)->getJson('/api/notifications')->assertOk();
});

it('serializes simultaneous case updates and commits only one next history version', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires pcntl in dev-php85.');
    }
    $row = $this->service->createCase($this->citizen, 'report', 'concurrent-create', $this->payload);
    $actorId = $this->officer->id;
    $caseId = $row->id;
    DB::disconnect('core');
    DB::disconnect('rukun');
    $children = [];
    foreach ([1, 2] as $number) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork case update test.');
        }
        if ($pid === 0) {
            DB::purge('core');
            DB::purge('rukun');
            try {
                app(CivicService::class)->action(User::query()->findOrFail($actorId), CivicCase::query()->findOrFail($caseId), 'concurrent-action-'.$number, ['version' => 1, 'action' => 'in_progress']);
                exit(0);
            } catch (HttpException $exception) {
                exit($exception->getStatusCode() === 409 ? 10 : 20);
            } catch (Throwable $exception) {
                fwrite(STDERR, $exception->getMessage().PHP_EOL);
                exit(30);
            }
        }
        $children[] = $pid;
    }
    $codes = [];
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        $codes[] = pcntl_wexitstatus($status);
    }
    DB::purge('core');
    DB::purge('rukun');
    sort($codes);
    expect($codes)->toBe([0, 10])->and($row->fresh()->version)->toBe(2)
        ->and(DB::connection('rukun')->table('civic_timeline')->where('case_id', $caseId)->count())->toBe(2)
        ->and(DB::connection('rukun')->table('civic_commands')->where('operation', 'case.action')->count())->toBe(1);
});

it('keeps civic mail disabled while allowing an inbox opt out', function () {
    $this->actingAs($this->citizen)->putJson('/api/notification-preferences', ['preferences' => [['category' => 'civic', 'database_enabled' => false, 'mail_enabled' => false]]])->assertOk();
    $this->putJson('/api/notification-preferences', ['preferences' => [['category' => 'civic', 'database_enabled' => true, 'mail_enabled' => true]]])->assertUnprocessable();
    $this->service->createCase($this->citizen, 'report', 'optout-create', $this->payload);
    expect(DB::connection('core')->table('notifications')->where('notifiable_id', $this->citizen->id)->count())->toBe(0);
});

it('prevents reusing civic files as financial proofs and vice versa', function () {
    $file = StoredFile::factory()->create(['owner_id' => $this->admin->id, 'mime_type' => 'application/pdf']);
    $this->service->announcement($this->admin, 'proof-announcement', ['area_id' => $this->rt->public_id, 'title' => 'Info', 'body' => 'Private draft', 'attachments' => [$file->public_id]]);
    $expense = ['area_id' => $this->rt->public_id, 'amount' => 10000, 'description' => 'Bukti pengeluaran', 'fund_classification' => 'operational', 'channel' => 'cash', 'proof_file_id' => $file->public_id];
    $this->actingAs($this->admin)->postJson('/api/billing/expenses', $expense, ['Idempotency-Key' => 'civic-proof-reuse'])->assertConflict();
    $proof = StoredFile::factory()->create(['owner_id' => $this->admin->id, 'mime_type' => 'application/pdf']);
    $this->postJson('/api/billing/expenses', [...$expense, 'proof_file_id' => $proof->public_id], ['Idempotency-Key' => 'fresh-expense-proof'])->assertCreated();
    $this->postJson('/api/civic/announcements', ['area_id' => $this->rt->public_id, 'title' => 'Info', 'body' => 'Announcement', 'attachments' => [$proof->public_id]], ['Idempotency-Key' => 'expense-proof-reuse'])->assertConflict();
});

it('retains submitter history after membership ends and withdraws announcement access', function () {
    $row = $this->service->createCase($this->citizen, 'report', 'history-create', $this->payload);
    $file = StoredFile::factory()->create(['owner_id' => $this->officer->id, 'mime_type' => 'application/pdf']);
    $announcement = $this->service->announcement($this->officer, 'move-announcement', ['area_id' => $this->rt->public_id, 'title' => 'Info', 'body' => 'Information', 'attachments' => [$file->public_id]]);
    $this->service->publish($this->officer, $announcement, 'move-publish');
    $this->actingAs($this->citizen)->getJson('/api/files/'.$file->public_id)->assertOk();
    DB::connection('rukun')->table('household_memberships')->where('household_id', $this->home->id)->update(['ends_at' => now()]);
    $this->getJson('/api/civic/reports/'.$row->public_id)->assertOk();
    $this->getJson('/api/civic/announcements/'.$announcement->public_id)->assertNotFound();
    $this->getJson('/api/files/'.$file->public_id)->assertNotFound();
    $this->postJson('/api/civic/reports', $this->payload, ['Idempotency-Key' => 'history-create'])->assertForbidden();
});
