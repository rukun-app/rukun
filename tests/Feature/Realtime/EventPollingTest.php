<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Laravel\Sanctum\Sanctum;
use Modules\Realtime\Models\UserEvent;
use Modules\Realtime\Support\EventCursor;

uses(RefreshDatabase::class);

function realtimeEvent(User $user, string $type = 'system.updated', array $payload = []): UserEvent
{
    return UserEvent::query()->create([
        'user_id' => $user->id,
        'type' => $type,
        'schema_version' => 1,
        'payload' => $payload,
        'expires_at' => now()->addDay(),
    ]);
}

it('polls only owned events in order and advances an opaque cursor', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $first = realtimeEvent($user, payload: ['sequence' => 1]);
    realtimeEvent($other, payload: ['sequence' => 99]);
    $second = realtimeEvent($user, payload: ['sequence' => 2]);
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/events?limit=1')->assertOk()
        ->assertJsonCount(1, 'data.events')
        ->assertJsonPath('data.events.0.id', $first->id)
        ->assertJsonPath('data.events.0.payload.sequence', 1);

    $cursor = urlencode($response->json('data.next_cursor'));
    $this->getJson("/api/events?cursor={$cursor}")->assertOk()
        ->assertJsonCount(1, 'data.events')
        ->assertJsonPath('data.events.0.id', $second->id);
});

it('filters event types without exposing another user events', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    realtimeEvent($user, 'notification.created');
    realtimeEvent($user, 'file.processed');
    realtimeEvent($other, 'notification.created');
    Sanctum::actingAs($user);

    $this->getJson('/api/events?types[]=notification.created')->assertOk()
        ->assertJsonCount(1, 'data.events')
        ->assertJsonPath('data.events.0.type', 'notification.created');
});

it('rejects invalid and expired cursors with stable API errors', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/events?cursor=invalid')->assertUnprocessable()
        ->assertJsonPath('code', 'validation.failed');

    $event = realtimeEvent($user);
    $cursor = urlencode(EventCursor::encode($event->id, now()->subSecond()));
    $this->getJson("/api/events?cursor={$cursor}")->assertStatus(409)
        ->assertJsonPath('code', 'realtime.cursor_expired');
});

it('returns the current boundary cursor and requires authentication', function () {
    $user = User::factory()->create();
    $event = realtimeEvent($user);

    $this->getJson('/api/events/cursor')->assertUnauthorized()
        ->assertJsonPath('code', 'auth.unauthenticated');

    Sanctum::actingAs($user);
    $cursor = $this->getJson('/api/events/cursor')->assertOk()->json('data.cursor');
    expect(EventCursor::decode($cursor)['id'])->toBe($event->id);
});

it('authorizes only the matching private user channel', function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    Broadcast::purge();
    require base_path('routes/channels.php');
    $user = User::factory()->create();
    $other = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-users.{$user->id}"])
        ->assertOk()->assertJsonStructure(['auth']);
    $this->postJson('/api/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-users.{$other->id}"])
        ->assertForbidden();
});

it('prunes only expired durable events', function () {
    $user = User::factory()->create();
    $expired = realtimeEvent($user);
    $expired->update(['expires_at' => now()->subMinute()]);
    $active = realtimeEvent($user);

    $this->artisan('realtime:prune-events')->assertSuccessful();

    $this->assertDatabaseMissing('user_events', ['id' => $expired->id]);
    $this->assertDatabaseHas('user_events', ['id' => $active->id]);
});
