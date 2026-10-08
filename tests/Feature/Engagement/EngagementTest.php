<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentType;
use Modules\Billing\Models\Receipt;
use Modules\Billing\Models\Tariff;
use Modules\Billing\Services\BillingReport;
use Modules\Billing\Services\BillingService;
use Modules\Community\Models\Area;
use Modules\Community\Models\Household;
use Modules\Community\Models\RoleAssignment;
use Modules\Community\Services\PopulationService;
use Modules\Engagement\Models\CommunityEvent;
use Modules\Engagement\Models\Participant;
use Modules\Engagement\Models\Team;
use Modules\Engagement\Services\EngagementService;
use Modules\Files\Models\StoredFile;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(DatabaseMigrations::class);

beforeEach(function () {
    Cache::store('redis')->getStore()->setPrefix('engagement-test:'.Str::uuid().':');
    config()->set('broadcasting.default', 'null');
    $this->seed(DatabaseSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');

    $this->rw = Area::factory()->create();
    $this->rt = Area::factory()->rt()->create(['parent_id' => $this->rw->id]);
    $this->otherRt = Area::factory()->rt()->create(['parent_id' => $this->rw->id]);

    $this->home = Household::factory()->create(['area_id' => $this->rt->id]);
    $this->otherHome = Household::factory()->create(['area_id' => $this->otherRt->id]);

    $this->officer = User::factory()->create();
    RoleAssignment::factory()->create([
        'user_id' => $this->officer->id,
        'role_id' => Role::findByName('ketua-rt', 'web')->id,
        'scope_type' => 'rt',
        'area_id' => $this->rt->id,
    ]);

    $this->otherOfficer = User::factory()->create();
    RoleAssignment::factory()->create([
        'user_id' => $this->otherOfficer->id,
        'role_id' => Role::findByName('ketua-rt', 'web')->id,
        'scope_type' => 'rt',
        'area_id' => $this->otherRt->id,
    ]);

    $this->citizen = User::factory()->create();
    $resident = app(PopulationService::class)->createResident($this->admin, [
        'name' => 'Warga Test',
        'household_id' => $this->home->public_id,
        'relationship' => 'head',
    ]);
    app(PopulationService::class)->account($this->admin, $resident, 'eng-link-1', ['user_id' => $this->citizen->public_id]);
});

it('creates a patrol team and manages members', function () {
    $teamId = $this->actingAs($this->officer)
        ->postJson('/api/engagement/teams', ['area_id' => $this->rt->public_id, 'name' => 'Regu A'], ['Idempotency-Key' => 'team-create-1'])
        ->assertCreated()->json('data.public_id');

    // Other RT officer cannot see this team
    $this->actingAs($this->otherOfficer)
        ->getJson('/api/engagement/teams')
        ->assertOk()
        ->assertJsonMissing(['public_id' => $teamId]);

    // Add citizen as member
    $this->actingAs($this->officer)
        ->postJson('/api/engagement/teams/'.$teamId.'/members', [
            'user_id' => $this->citizen->public_id,
            'household_id' => $this->home->public_id,
        ], ['Idempotency-Key' => 'member-add-1'])
        ->assertOk();

    $this->actingAs($this->officer)
        ->getJson('/api/engagement/teams/'.$teamId.'/members')
        ->assertOk()
        ->assertJsonPath('data.data.0.user_id', $this->citizen->public_id);

    // Remove member
    $this->actingAs($this->officer)
        ->postJson('/api/engagement/teams/'.$teamId.'/members', [
            'user_id' => $this->citizen->public_id,
            'household_id' => $this->home->public_id,
            'remove' => true,
        ], ['Idempotency-Key' => 'member-remove-1'])
        ->assertOk();

    expect(DB::connection('rukun')->table('engagement_team_members')->count())->toBe(0);
});

it('enforces cross-RT isolation on teams', function () {
    $this->actingAs($this->otherOfficer)
        ->postJson('/api/engagement/teams', ['area_id' => $this->rt->public_id, 'name' => 'Regu X'], ['Idempotency-Key' => 'team-cross-rt'])
        ->assertForbidden();
});

it('sets and reads patrol policy', function () {
    $type = PaymentType::query()->create([
        'area_id' => $this->rt->id,
        'code' => 'DENDA_RONDA',
        'name' => 'Denda Ronda',
        'collection_policy' => 'can_accumulate',
        'fund_classification' => 'operational',
    ]);
    Tariff::query()->create(['payment_type_id' => $type->id, 'amount' => 50000, 'starts_at' => now()->subYear()->toDateString()]);

    $this->actingAs($this->officer)
        ->postJson('/api/engagement/patrol-policy', [
            'area_id' => $this->rt->public_id,
            'payment_type_id' => $type->public_id,
            'due_days' => 7,
        ], ['Idempotency-Key' => 'policy-set-1'])
        ->assertOk()
        ->assertJsonPath('data.area_id', $this->rt->public_id);

    $this->actingAs($this->officer)
        ->getJson('/api/engagement/patrol-policy/'.$this->rt->public_id)
        ->assertOk()
        ->assertJsonPath('data.due_days', 7);

    // Other RT officer cannot set policy for this RT
    $this->actingAs($this->otherOfficer)
        ->postJson('/api/engagement/patrol-policy', [
            'area_id' => $this->rt->public_id,
            'due_days' => 3,
        ], ['Idempotency-Key' => 'policy-cross-rt'])
        ->assertForbidden();
});

it('creates patrol event, scopes visibility, and cancels it', function () {
    $teamId = $this->actingAs($this->officer)
        ->postJson('/api/engagement/teams', ['area_id' => $this->rt->public_id, 'name' => 'Regu B'], ['Idempotency-Key' => 'team-patrol-1'])
        ->assertCreated()->json('data.public_id');

    $this->actingAs($this->officer)
        ->postJson('/api/engagement/teams/'.$teamId.'/members', [
            'user_id' => $this->citizen->public_id,
            'household_id' => $this->home->public_id,
        ], ['Idempotency-Key' => 'patrol-member-1'])
        ->assertOk();

    $eventId = $this->actingAs($this->officer)
        ->postJson('/api/engagement/events', [
            'kind' => 'patrol',
            'area_id' => $this->rt->public_id,
            'team_id' => $teamId,
            'title' => 'Ronda Malam Minggu',
            'starts_at' => now()->addDay()->toISOString(),
            'ends_at' => now()->addDay()->addHours(3)->toISOString(),
        ], ['Idempotency-Key' => 'patrol-event-1'])
        ->assertCreated()->json('data.public_id');

    $this->actingAs($this->citizen)->getJson('/api/engagement/events/'.$eventId)
        ->assertOk()->assertJsonPath('data.kind', 'patrol');

    $this->actingAs($this->otherOfficer)->getJson('/api/engagement/events/'.$eventId)
        ->assertNotFound();

    $this->actingAs($this->officer)
        ->postJson('/api/engagement/events/'.$eventId.'/cancel', [
            'version' => 1,
            'reason' => 'Acara dibatalkan',
        ], ['Idempotency-Key' => 'patrol-cancel-1'])
        ->assertOk()->assertJsonPath('data.status', 'cancelled');
});

it('creates activity event and allows citizen to join and withdraw', function () {
    $eventId = $this->actingAs($this->officer)
        ->postJson('/api/engagement/events', [
            'kind' => 'activity',
            'area_id' => $this->rt->public_id,
            'title' => 'Kerja Bakti RT',
            'starts_at' => now()->addDays(2)->toISOString(),
            'ends_at' => now()->addDays(2)->addHours(4)->toISOString(),
        ], ['Idempotency-Key' => 'activity-event-1'])
        ->assertCreated()->json('data.public_id');

    $participantId = $this->actingAs($this->citizen)
        ->postJson('/api/engagement/events/'.$eventId.'/join', ['household_id' => $this->home->public_id], ['Idempotency-Key' => 'activity-join-1'])
        ->assertCreated()->json('data.public_id');

    $this->actingAs($this->citizen)->getJson('/api/engagement/events/'.$eventId.'/participants')
        ->assertOk()->assertJsonPath('data.data.0.public_id', $participantId);

    $this->actingAs($this->citizen)
        ->postJson('/api/engagement/events/'.$eventId.'/participants/'.$participantId.'/actions', [
            'action' => 'withdraw', 'version' => 1,
        ], ['Idempotency-Key' => 'activity-withdraw-1'])
        ->assertOk();

    $this->actingAs($this->officer)
        ->getJson('/api/engagement/events/'.$eventId.'/participants/'.$participantId.'/history')
        ->assertOk()->assertJsonCount(2, 'data.data');
});

it('handles patrol leave request and approval with invoice', function () {
    $type = PaymentType::query()->create([
        'area_id' => $this->rt->id,
        'code' => 'DENDA_IZIN_RONDA',
        'name' => 'Denda Izin Ronda',
        'collection_policy' => 'can_accumulate',
        'fund_classification' => 'operational',
    ]);
    Tariff::query()->create(['payment_type_id' => $type->id, 'amount' => 25000, 'starts_at' => now()->subYear()->toDateString()]);

    $this->actingAs($this->officer)
        ->postJson('/api/engagement/patrol-policy', [
            'area_id' => $this->rt->public_id,
            'payment_type_id' => $type->public_id,
            'due_days' => 7,
        ], ['Idempotency-Key' => 'policy-leave-test'])->assertOk();

    $teamId = $this->actingAs($this->officer)
        ->postJson('/api/engagement/teams', ['area_id' => $this->rt->public_id, 'name' => 'Regu C'], ['Idempotency-Key' => 'team-leave-1'])
        ->assertCreated()->json('data.public_id');

    $this->actingAs($this->officer)
        ->postJson('/api/engagement/teams/'.$teamId.'/members', [
            'user_id' => $this->citizen->public_id,
            'household_id' => $this->home->public_id,
        ], ['Idempotency-Key' => 'leave-member-1'])->assertOk();

    $eventId = $this->actingAs($this->officer)
        ->postJson('/api/engagement/events', [
            'kind' => 'patrol',
            'area_id' => $this->rt->public_id,
            'team_id' => $teamId,
            'title' => 'Ronda Izin Test',
            'starts_at' => now()->addDays(3)->toISOString(),
            'ends_at' => now()->addDays(3)->addHours(3)->toISOString(),
        ], ['Idempotency-Key' => 'patrol-leave-event'])
        ->assertCreated()->json('data.public_id');

    $participantId = $this->actingAs($this->officer)
        ->getJson('/api/engagement/events/'.$eventId.'/participants')
        ->assertOk()->json('data.data.0.public_id');

    $this->actingAs($this->citizen)
        ->postJson('/api/engagement/events/'.$eventId.'/participants/'.$participantId.'/actions', [
            'action' => 'leave', 'version' => 1, 'note' => 'Ada keperluan keluarga',
        ], ['Idempotency-Key' => 'leave-request-1'])->assertOk();

    $this->actingAs($this->officer)
        ->postJson('/api/engagement/events/'.$eventId.'/participants/'.$participantId.'/actions', [
            'action' => 'approve_leave', 'version' => 2, 'waive' => false,
        ], ['Idempotency-Key' => 'leave-approve-1'])->assertOk();

    $participant = Participant::query()->where('public_id', $participantId)->firstOrFail();
    expect($participant->invoice_id)->not->toBeNull()
        ->and($participant->leave_status)->toBe('approved')
        ->and($participant->attendance)->toBe('excused');
});

it('records incident during active event and scopes by role', function () {
    $teamId = $this->actingAs($this->officer)
        ->postJson('/api/engagement/teams', ['area_id' => $this->rt->public_id, 'name' => 'Regu D'], ['Idempotency-Key' => 'team-incident-1'])
        ->assertCreated()->json('data.public_id');

    $this->actingAs($this->officer)
        ->postJson('/api/engagement/teams/'.$teamId.'/members', [
            'user_id' => $this->citizen->public_id,
            'household_id' => $this->home->public_id,
        ], ['Idempotency-Key' => 'incident-member-1'])->assertOk();

    $team = Team::query()->where('public_id', $teamId)->firstOrFail();
    $event = CommunityEvent::query()->create([
        'kind' => 'patrol',
        'area_id' => $this->rt->id,
        'team_id' => $team->id,
        'title' => 'Ronda Aktif',
        'starts_at' => now()->subMinutes(30),
        'ends_at' => now()->addHours(2),
        'due_days' => 7,
        'created_by' => $this->officer->id,
    ]);

    DB::connection('rukun')->table('engagement_participants')->insert([
        'public_id' => (string) Str::uuid(),
        'event_id' => $event->id,
        'user_id' => $this->citizen->id,
        'household_id' => $this->home->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $incidentId = $this->actingAs($this->officer)
        ->postJson('/api/engagement/events/'.$event->public_id.'/incidents', [
            'description' => 'Orang mencurigakan di blok B',
        ], ['Idempotency-Key' => 'incident-create-1'])
        ->assertCreated()->json('data.public_id');

    $this->actingAs($this->officer)
        ->getJson('/api/engagement/events/'.$event->public_id.'/incidents')
        ->assertOk()->assertJsonPath('data.data.0.public_id', $incidentId);

    // Other RT officer cannot see this event at all
    $this->actingAs($this->otherOfficer)
        ->getJson('/api/engagement/events/'.$event->public_id.'/incidents')
        ->assertNotFound();
});

it('prevents warga from other RT seeing or joining an event', function () {
    $outsider = User::factory()->create();
    $outsideResident = app(PopulationService::class)->createResident($this->admin, [
        'name' => 'Warga Luar',
        'household_id' => $this->otherHome->public_id,
        'relationship' => 'head',
    ]);
    app(PopulationService::class)->account($this->admin, $outsideResident, 'eng-link-out', ['user_id' => $outsider->public_id]);

    $eventId = $this->actingAs($this->officer)
        ->postJson('/api/engagement/events', [
            'kind' => 'activity',
            'area_id' => $this->rt->public_id,
            'title' => 'Kegiatan RT',
            'starts_at' => now()->addDays(2)->toISOString(),
            'ends_at' => now()->addDays(2)->addHours(2)->toISOString(),
        ], ['Idempotency-Key' => 'activity-scope-1'])
        ->assertCreated()->json('data.public_id');

    $this->actingAs($outsider)->getJson('/api/engagement/events/'.$eventId)->assertNotFound();

    $this->actingAs($outsider)
        ->postJson('/api/engagement/events/'.$eventId.'/join', [
            'household_id' => $this->otherHome->public_id,
        ], ['Idempotency-Key' => 'join-outsider-1'])
        ->assertNotFound();
});

function engagementPatrol($test, int $fee = 0, string $suffix = 'main'): array
{
    $service = app(EngagementService::class);
    if ($fee) {
        $type = PaymentType::query()->create(['area_id' => $test->rt->id, 'code' => 'RONDA_'.$suffix, 'name' => 'Izin ronda', 'collection_policy' => 'can_accumulate', 'fund_classification' => 'operational']);
        Tariff::query()->create(['payment_type_id' => $type->id, 'amount' => $fee, 'starts_at' => today()->subYear()->toDateString()]);
        $service->policy($test->officer, 'policy-test-'.$suffix, ['area_id' => $test->rt->public_id, 'payment_type_id' => $type->public_id, 'due_days' => 7]);
    }
    $result = $service->team($test->officer, 'team-test-'.$suffix, ['area_id' => $test->rt->public_id, 'name' => 'Team '.$suffix]);
    $team = Team::query()->where('public_id', $result['public_id'])->firstOrFail();
    $service->teamMember($test->officer, $team, 'member-test-'.$suffix, ['user_id' => $test->citizen->public_id, 'household_id' => $test->home->public_id]);
    $result = $service->createEvent($test->officer, 'event-test-'.$suffix, ['kind' => 'patrol', 'area_id' => $test->rt->public_id, 'team_id' => $team->public_id, 'title' => 'Ronda '.$suffix, 'starts_at' => now()->addDays(2)->toISOString(), 'ends_at' => now()->addDays(2)->addHours(2)->toISOString()]);
    $event = CommunityEvent::query()->where('public_id', $result['public_id'])->firstOrFail();

    return [$event, Participant::query()->where('event_id', $event->id)->firstOrFail(), $team];
}

it('protects direct team and policy reads and paginates without leaking numeric IDs', function () {
    [$event,$participant,$team] = engagementPatrol($this);
    foreach ([$this->otherOfficer, $this->citizen] as $user) {
        $this->actingAs($user)->getJson('/api/engagement/teams/'.$team->public_id.'/members')->assertNotFound();
        $this->getJson('/api/engagement/patrol-policy/'.$this->rt->public_id)->assertNotFound();
    }
    $second = User::factory()->create();
    $resident = app(PopulationService::class)->createResident($this->admin, ['name' => 'Keluarga', 'household_id' => $this->home->public_id, 'relationship' => 'child']);
    app(PopulationService::class)->account($this->admin, $resident, 'family-test-link', ['user_id' => $second->public_id]);
    $this->actingAs($this->officer)->postJson('/api/engagement/teams/'.$team->public_id.'/members', ['user_id' => $second->public_id, 'household_id' => $this->home->public_id], ['Idempotency-Key' => 'member-second-test'])->assertOk();
    $page = $this->getJson('/api/engagement/teams/'.$team->public_id.'/members?per_page=1')->assertOk()->assertJsonCount(1, 'data.data')->assertJsonMissingPath('data.data.0.id');
    $this->getJson('/api/engagement/teams/'.$team->public_id.'/members?per_page=1&cursor='.urlencode($page->json('data.next_cursor')))->assertOk()->assertJsonCount(1, 'data.data');
    expect(Participant::query()->where('event_id', $event->id)->count())->toBe(1);
    $this->getJson('/api/engagement/patrol-policy/'.$this->rt->public_id)->assertJsonPath('data.payment_type_id', null);
    $this->actingAs($second)->getJson('/api/engagement/events/'.$event->public_id.'/participants')->assertJsonCount(0, 'data.data');
});

it('requires leave and waiver reasons and prevents an excused attendance shortcut', function () {
    [$event,$participant] = engagementPatrol($this, 25000);
    $path = '/api/engagement/events/'.$event->public_id.'/participants/'.$participant->public_id.'/actions';
    $this->actingAs($this->citizen)->postJson($path, ['action' => 'leave', 'version' => 1], ['Idempotency-Key' => 'missing-reason-test'])->assertUnprocessable();
    $this->actingAs($this->officer)->postJson($path, ['action' => 'leave', 'version' => 1, 'note' => 'Warga meminta izin melalui telepon'], ['Idempotency-Key' => 'phone-leave-test'])->assertOk();
    $this->postJson($path, ['action' => 'approve_leave', 'version' => 2, 'waive' => true], ['Idempotency-Key' => 'missing-waiver-reason'])->assertUnprocessable();
    $this->postJson($path, ['action' => 'reject_leave', 'version' => 2], ['Idempotency-Key' => 'missing-reject-reason'])->assertUnprocessable();
    $this->postJson($path, ['action' => 'attendance', 'version' => 2, 'attendance' => 'excused'], ['Idempotency-Key' => 'bypass-leave-fee'])->assertUnprocessable();
    $response = $this->postJson($path, ['action' => 'approve_leave', 'version' => 2, 'waive' => true, 'note' => 'Keadaan darurat, dibebaskan'], ['Idempotency-Key' => 'waive-with-reason'])->assertOk();
    $response->assertJsonPath('data.invoice_id', null)->assertJsonPath('data.waived', true)->assertJsonPath('data.leave_recorded_by', $this->officer->public_id)->assertJsonPath('data.leave_review_note', 'Keadaan darurat, dibebaskan');
    expect(Invoice::query()->count())->toBe(0);
});

it('bills approved leave once and derives partial combined payment and reversal balances from Billing', function () {
    [$event,$participant] = engagementPatrol($this, 25000);
    $path = '/api/engagement/events/'.$event->public_id.'/participants/'.$participant->public_id.'/actions';
    $this->actingAs($this->citizen)->postJson($path, ['action' => 'leave', 'version' => 1, 'note' => 'Acara keluarga'], ['Idempotency-Key' => 'bill-leave-request'])->assertOk();
    expect(Invoice::query()->count())->toBe(0);
    $this->actingAs($this->officer)->postJson($path, ['action' => 'approve_leave', 'version' => 2, 'amount' => 1], ['Idempotency-Key' => 'tampered-fee'])->assertUnprocessable();
    $approved = ['action' => 'approve_leave', 'version' => 2];
    $invoiceId = $this->postJson($path, $approved, ['Idempotency-Key' => 'bill-leave-approve'])->assertOk()->assertJsonPath('data.billing.amount', 25000)->json('data.invoice_id');
    $this->postJson($path, $approved, ['Idempotency-Key' => 'bill-leave-approve'])->assertOk()->assertJsonPath('data.invoice_id', $invoiceId);
    expect(Invoice::query()->count())->toBe(1);
    $service = app(EngagementService::class);
    $type = PaymentType::query()->create(['area_id' => $this->rt->id, 'code' => 'ACTIVITY', 'name' => 'Iuran kegiatan', 'collection_policy' => 'can_accumulate', 'fund_classification' => 'operational']);
    Tariff::query()->create(['payment_type_id' => $type->id, 'amount' => 10000, 'starts_at' => today()->subYear()->toDateString()]);
    $result = $service->createEvent($this->officer, 'paid-activity-test', ['kind' => 'activity', 'area_id' => $this->rt->public_id, 'title' => 'Kegiatan', 'starts_at' => now()->addDays(5)->toISOString(), 'ends_at' => now()->addDays(5)->addHour()->toISOString(), 'payment_type_id' => $type->public_id]);
    $activity = CommunityEvent::query()->where('public_id', $result['public_id'])->firstOrFail();
    $result = $service->participate($this->citizen, $activity, 'paid-activity-join', ['household_id' => $this->home->public_id]);
    $joined = Participant::query()->where('public_id', $result['public_id'])->firstOrFail();
    $service->action($this->officer, $joined, 'activity-charge-test', ['action' => 'charge', 'version' => 1]);
    $second = Invoice::query()->findOrFail($joined->fresh()->invoice_id);
    $billing = app(BillingService::class);
    $firstCash = $billing->cash($this->admin, 'leave-first-payment', ['household_id' => $this->home->public_id, 'amount' => 10000, 'paid_on' => today()->toDateString(), 'allocations' => [['invoice_id' => $invoiceId, 'amount' => 10000]]]);
    $list = '/api/engagement/events/'.$event->public_id.'/participants';
    $this->actingAs($this->citizen)->getJson($list)->assertJsonPath('data.data.0.billing.status', 'partially_paid')->assertJsonPath('data.data.0.billing.outstanding_amount', 15000);
    $billing->cash($this->admin, 'leave-combined-payment', ['household_id' => $this->home->public_id, 'amount' => 25000, 'paid_on' => today()->toDateString(), 'allocations' => [['invoice_id' => $invoiceId, 'amount' => 15000], ['invoice_id' => $second->public_id, 'amount' => 10000]]]);
    $this->getJson($list)->assertJsonPath('data.data.0.billing.status', 'paid')->assertJsonPath('data.data.0.billing.paid_amount', 25000);
    expect(app(BillingReport::class)->paid($second))->toBe(10000);
    $receipt = Receipt::query()->where('public_id', $firstCash['public_id'])->firstOrFail();
    $billing->reverseReceipt($this->admin, 'leave-reverse-payment', $receipt, ['posted_on' => today()->toDateString(), 'reason' => 'Koreksi penerimaan']);
    $this->getJson($list)->assertJsonPath('data.data.0.billing.paid_amount', 15000)->assertJsonPath('data.data.0.billing.outstanding_amount', 10000);
    $this->actingAs($this->officer)->postJson('/api/engagement/events/'.$event->public_id.'/cancel', ['version' => 1, 'reason' => 'Batal'], ['Idempotency-Key' => 'cancel-billed-patrol'])->assertConflict();
});

it('keeps the default leave free and rejects premature, stale and repeated attendance', function () {
    [$event,$participant] = engagementPatrol($this);
    $path = '/api/engagement/events/'.$event->public_id.'/participants/'.$participant->public_id.'/actions';
    $data = ['action' => 'attendance', 'version' => 1, 'attendance' => 'present'];
    $this->actingAs($this->officer)->postJson($path, $data, ['Idempotency-Key' => 'premature-attendance'])->assertConflict();
    $this->travelTo($event->starts_at->addMinute());
    $this->actingAs($this->citizen)->postJson($path, $data, ['Idempotency-Key' => 'self-attendance-test'])->assertForbidden();
    $this->actingAs($this->officer)->postJson($path, $data, ['Idempotency-Key' => 'valid-attendance-test'])->assertOk()->assertJsonPath('data.attendance', 'present');
    $this->postJson($path, $data, ['Idempotency-Key' => 'valid-attendance-test'])->assertOk();
    $this->postJson($path, $data, ['Idempotency-Key' => 'stale-attendance-test'])->assertConflict();
    $this->postJson($path, [...$data, 'version' => 2, 'attendance' => 'absent'], ['Idempotency-Key' => 'overwrite-attendance'])->assertConflict();
    expect($event->fee_amount)->toBe(0)->and(DB::connection('rukun')->table('engagement_history')->where('participant_id', $participant->id)->count())->toBe(2);
    expect(fn () => DB::connection('rukun')->table('engagement_history')->where('participant_id', $participant->id)->update(['action' => 'rewrite']))->toThrow(QueryException::class);
});

it('isolates private incident evidence and rechecks revoked officer access', function () {
    [$event,$participant] = engagementPatrol($this);
    Storage::fake('local');
    $file = StoredFile::factory()->create(['owner_id' => $this->citizen->id, 'mime_type' => 'application/pdf']);
    Storage::disk('local')->put($file->path, '%PDF-1.4 Evidence');
    $this->travelTo($event->starts_at->addMinute());
    $this->actingAs($this->citizen)->postJson('/api/engagement/events/'.$event->public_id.'/incidents', ['description' => 'Bukti insiden privat', 'attachments' => [$file->public_id]], ['Idempotency-Key' => 'incident-evidence-test'])->assertCreated();
    $this->otherOfficer->givePermissionTo(['files.view-any', 'files.download-any']);
    $this->actingAs($this->otherOfficer)->getJson('/api/files/'.$file->public_id.'/download')->assertNotFound();
    $this->actingAs($this->officer)->getJson('/api/files/'.$file->public_id.'/download')->assertOk();
    RoleAssignment::query()->where('user_id', $this->officer->id)->update(['status' => 'revoked']);
    $this->getJson('/api/files/'.$file->public_id)->assertNotFound();
    $this->actingAs($this->citizen)->deleteJson('/api/files/'.$file->public_id)->assertNotFound();
    $this->postJson('/api/engagement/events/'.$event->public_id.'/incidents', ['description' => 'Ulang', 'attachments' => [$file->public_id]], ['Idempotency-Key' => 'reuse-incident-file'])->assertConflict();
    $notices = DB::connection('core')->table('notifications')->get()->toJson();
    expect($notices)->not->toContain('Bukti insiden privat');
});

it('rolls back approval when Billing is closed and preserves pending leave', function () {
    [$event,$participant] = engagementPatrol($this, 25000);
    $service = app(EngagementService::class);
    $service->action($this->citizen, $participant, 'closed-leave-request', ['action' => 'leave', 'version' => 1, 'note' => 'Tidak hadir']);
    app(BillingService::class)->close($this->admin, 'close-period-test', ['area_id' => $this->rt->public_id, 'period' => today()->format('Y-m')]);
    $path = '/api/engagement/events/'.$event->public_id.'/participants/'.$participant->public_id.'/actions';
    $this->actingAs($this->officer)->postJson($path, ['action' => 'approve_leave', 'version' => 2], ['Idempotency-Key' => 'closed-leave-approve'])->assertConflict();
    expect($participant->fresh()->leave_status)->toBe('pending')->and($participant->fresh()->version)->toBe(2)->and(Invoice::query()->count())->toBe(0);
});

it('supports RW activities while restricting enrollment and prevents overlapping patrols', function () {
    [$event,$participant,$team] = engagementPatrol($this);
    $this->actingAs($this->officer)->postJson('/api/engagement/events', ['kind' => 'patrol', 'area_id' => $this->rt->public_id, 'team_id' => $team->public_id, 'title' => 'Overlap', 'starts_at' => $event->starts_at->toISOString(), 'ends_at' => $event->ends_at->toISOString()], ['Idempotency-Key' => 'overlap-patrol-test'])->assertConflict();
    expect(CommunityEvent::query()->count())->toBe(1);
    $rwEventId = $this->actingAs($this->admin)->postJson('/api/engagement/events', ['kind' => 'activity', 'area_id' => $this->rw->public_id, 'title' => 'Kegiatan RW', 'starts_at' => now()->addDays(4)->toISOString(), 'ends_at' => now()->addDays(4)->addHour()->toISOString()], ['Idempotency-Key' => 'rw-activity-test'])->assertCreated()->json('data.public_id');
    $this->actingAs($this->otherOfficer)->getJson('/api/engagement/events/'.$rwEventId)->assertOk();
    $this->actingAs($this->citizen)->postJson('/api/engagement/events/'.$rwEventId.'/participants', ['household_id' => $this->home->public_id], ['Idempotency-Key' => 'unauthorized-enroll'])->assertForbidden();
    $this->postJson('/api/engagement/events/'.$rwEventId.'/join', ['household_id' => $this->home->public_id], ['Idempotency-Key' => 'rw-activity-join'])->assertCreated();
    $this->postJson('/api/engagement/events/'.$event->public_id.'/participants/'.$participant->public_id.'/actions', ['action' => 'leave', 'version' => 1, 'note' => 'Izin'], ['Idempotency-Key' => 'own-leave-test'])->assertOk();
    $this->citizen->assignRole('super-admin');
    $this->postJson('/api/engagement/events/'.$event->public_id.'/participants/'.$participant->public_id.'/actions', ['action' => 'approve_leave', 'version' => 2], ['Idempotency-Key' => 'self-review-test'])->assertForbidden();
});

it('serializes concurrent leave approvals into one invoice and one history version', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires pcntl in dev-php85.');
    }
    [$event,$participant] = engagementPatrol($this, 25000);
    app(EngagementService::class)->action($this->citizen, $participant, 'race-leave-request', ['action' => 'leave', 'version' => 1, 'note' => 'Keperluan keluarga']);
    $actorId = $this->officer->id;
    $participantId = $participant->id;
    DB::disconnect('core');
    DB::disconnect('rukun');
    $children = [];
    foreach ([1, 2] as $number) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork approval test.');
        }
        if ($pid === 0) {
            DB::purge('core');
            DB::purge('rukun');
            try {
                app(EngagementService::class)->action(User::query()->findOrFail($actorId), Participant::query()->findOrFail($participantId), 'race-approve-'.$number, ['action' => 'approve_leave', 'version' => 2]);
                exit(0);
            } catch (HttpException $e) {
                exit($e->getStatusCode() === 409 ? 10 : 20);
            } catch (Throwable $e) {
                fwrite(STDERR, $e->getMessage().PHP_EOL);
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
    expect($codes)->toBe([0, 10])->and(Invoice::query()->count())->toBe(1)->and($participant->fresh()->version)->toBe(3)
        ->and(DB::connection('rukun')->table('engagement_history')->where('participant_id', $participantId)->count())->toBe(3);
});

it('applies zero fee by default and retains a scheduled tariff snapshot after policy changes', function () {
    [$event,$participant] = engagementPatrol($this, 25000);
    $service = app(EngagementService::class);
    $service->policy($this->officer, 'disable-patrol-fees', ['area_id' => $this->rt->public_id, 'payment_type_id' => null, 'due_days' => 14]);
    $service->action($this->citizen, $participant, 'snapshot-leave-request', ['action' => 'leave', 'version' => 1, 'note' => 'Izin keluarga']);
    $service->action($this->officer, $participant, 'snapshot-leave-approve', ['action' => 'approve_leave', 'version' => 2]);
    expect(Invoice::query()->findOrFail($participant->fresh()->invoice_id)->amount)->toBe(25000);
    $this->travel(4)->days();
    [$freeEvent,$freeParticipant] = engagementPatrol($this, 0, 'free');
    expect($freeEvent->fee_amount)->toBe(0);
    $service->action($this->citizen, $freeParticipant, 'free-leave-request', ['action' => 'leave', 'version' => 1, 'note' => 'Izin warga']);
    $service->action($this->officer, $freeParticipant, 'free-leave-approve', ['action' => 'approve_leave', 'version' => 2]);
    expect($freeParticipant->fresh()->attendance)->toBe('excused')->and($freeParticipant->fresh()->invoice_id)->toBeNull()->and(Invoice::query()->count())->toBe(1);
});

it('rechecks household membership before issuing debt and rejects replay after role revocation', function () {
    [$event,$participant] = engagementPatrol($this, 25000);
    $service = app(EngagementService::class);
    $service->action($this->citizen, $participant, 'membership-leave-request', ['action' => 'leave', 'version' => 1, 'note' => 'Izin']);
    DB::connection('rukun')->table('household_memberships')->where('household_id', $this->home->id)->update(['ends_at' => now()]);
    $path = '/api/engagement/events/'.$event->public_id.'/participants/'.$participant->public_id.'/actions';
    $this->actingAs($this->officer)->postJson($path, ['action' => 'approve_leave', 'version' => 2], ['Idempotency-Key' => 'moved-leave-approve'])->assertConflict();
    expect($participant->fresh()->leave_status)->toBe('pending')->and(Invoice::query()->count())->toBe(0);
    $data = ['action' => 'reject_leave', 'version' => 2, 'note' => 'Data keanggotaan berubah'];
    $this->postJson($path, $data, ['Idempotency-Key' => 'moved-leave-reject'])->assertOk();
    RoleAssignment::query()->where('user_id', $this->officer->id)->update(['status' => 'revoked']);
    $this->postJson($path, $data, ['Idempotency-Key' => 'moved-leave-reject'])->assertNotFound();
});

it('lets a patrol coordinator approve fixed fees without granting general financial control', function () {
    [$event,$participant] = engagementPatrol($this, 25000);
    $coordinator = User::factory()->create();
    RoleAssignment::factory()->create(['user_id' => $coordinator->id, 'role_id' => Role::findByName('koordinator-ronda', 'web')->id, 'scope_type' => 'rt', 'area_id' => $this->rt->id]);
    $path = '/api/engagement/events/'.$event->public_id.'/participants/'.$participant->public_id.'/actions';
    $this->actingAs($coordinator)->postJson('/api/engagement/patrol-policy', ['area_id' => $this->rt->public_id, 'due_days' => 7], ['Idempotency-Key' => 'coordinator-policy-denied'])->assertForbidden();
    $this->postJson($path, ['action' => 'leave', 'version' => 1, 'note' => 'Dicatat atas permintaan warga'], ['Idempotency-Key' => 'coordinator-leave'])->assertOk();
    $this->postJson($path, ['action' => 'approve_leave', 'version' => 2], ['Idempotency-Key' => 'coordinator-approve'])->assertOk()->assertJsonPath('data.billing.amount', 25000);
    $this->postJson('/api/billing/payment-types', ['area_id' => $this->rt->public_id, 'code' => 'FORBIDDEN', 'name' => 'Tidak boleh', 'collection_policy' => 'can_accumulate', 'fund_classification' => 'operational'], ['Idempotency-Key' => 'coordinator-billing-denied'])->assertForbidden();
    $this->actingAs($this->admin)->postJson('/api/billing/invoices/generate', ['area_id' => $this->rt->public_id, 'payment_type_id' => PaymentType::query()->findOrFail($event->payment_type_id)->public_id, 'period' => today()->format('Y-m'), 'subject' => 'engagement:'.$participant->public_id, 'due_date' => today()->addWeek()->toDateString()], ['Idempotency-Key' => 'reserved-subject-denied'])->assertUnprocessable();
});
