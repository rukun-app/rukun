<?php

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Notifications\Models\NotificationPreference;
use Modules\Notifications\Notifications\CoreNotification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function accountNotification(User $user): CoreNotification
{
    return new CoreNotification('account', 'notifications.test_title', 'notifications.test_message', ['name' => $user->name]);
}

it('provides an isolated localized notification inbox', function () {
    $user = User::factory()->create(['locale' => 'id']);
    $other = User::factory()->create();
    $user->notifyNow(accountNotification($user), ['database']);
    $other->notifyNow(accountNotification($other), ['database']);
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)->getJson('/api/notifications?status=unread&category=account', ['Accept-Language' => 'id'])
        ->assertOk()->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.title', 'Notifikasi akun')
        ->assertJsonPath('data.data.0.message', "Halo {$user->name}, notifikasi akun Anda telah tersedia.");
});

it('manages read state count and deletion only for the owner', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $user->notifyNow(accountNotification($user), ['database']);
    $other->notifyNow(accountNotification($other), ['database']);
    $notification = $user->notifications()->firstOrFail();
    $foreign = $other->notifications()->firstOrFail();
    Sanctum::actingAs($user);

    $this->getJson('/api/notifications/unread-count')->assertOk()->assertJsonPath('data.count', 1);
    $this->patchJson("/api/notifications/{$notification->id}/read")->assertOk()->assertJsonPath('data.read_at', fn ($value) => is_string($value));
    $this->deleteJson("/api/notifications/{$notification->id}/read")->assertOk()->assertJsonPath('data.read_at', null);
    $this->getJson("/api/notifications/{$foreign->id}")->assertNotFound();
    $this->postJson('/api/notifications/read-all')->assertOk();
    $this->deleteJson("/api/notifications/{$notification->id}")->assertOk();
    $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
});

it('returns effective preferences and protects required security channels', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/notification-preferences')->assertOk()->assertJsonCount(4, 'data')->assertJsonFragment(['category' => 'civic', 'database_enabled' => true, 'mail_enabled' => false, 'locked_channels' => ['mail']]);
    $this->putJson('/api/notification-preferences', ['preferences' => [[
        'category' => 'security',
        'database_enabled' => false,
        'mail_enabled' => true,
    ]]])->assertUnprocessable();

    $this->putJson('/api/notification-preferences', ['preferences' => [[
        'category' => 'system',
        'database_enabled' => false,
        'mail_enabled' => false,
    ]]])->assertOk();

    $this->assertDatabaseHas('notification_preferences', ['user_id' => $user->id, 'category' => 'system', 'database_enabled' => false]);
    $this->assertDatabaseHas('audit_events', ['actor_id' => $user->id, 'event' => 'notification.preferences_updated']);
});

it('selects delivery channels from user preferences and category defaults', function () {
    $user = User::factory()->create();
    $notification = accountNotification($user);
    expect($notification->via($user))->toBe(['database', 'mail'])
        ->and($notification->queue)->toBe('default');

    NotificationPreference::query()->create(['user_id' => $user->id, 'category' => 'account', 'database_enabled' => true, 'mail_enabled' => false]);
    expect($notification->via($user))->toBe(['database']);
});
