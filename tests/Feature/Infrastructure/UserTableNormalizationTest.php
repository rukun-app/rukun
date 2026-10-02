<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Community\Models\RoleAssignment;

uses(DatabaseMigrations::class);

function userTableNormalizationMigration(): Migration
{
    return require database_path('migrations/2026_10_02_000001_normalize_core_users_table.php');
}

it('leaves a normalized database intact through rollback and reapplication', function () {
    $user = User::factory()->create();
    $assignment = RoleAssignment::factory()->create(['user_id' => $user->id, 'assigned_by' => $user->id]);
    $migration = userTableNormalizationMigration();
    $migration->down();
    $migration->up();

    expect(Schema::connection('rukun')->hasTable('users'))->toBeFalse()
        ->and($user->fresh()->public_id)->toBe($user->public_id)
        ->and($assignment->fresh()->user_id)->toBe($user->id)
        ->and(User::factory()->create()->id)->toBeGreaterThan($user->id);
});

it('renames a populated legacy identity table without breaking sequences or foreign keys', function () {
    $user = User::factory()->create();
    $assignment = RoleAssignment::factory()->create(['user_id' => $user->id, 'assigned_by' => $user->id]);
    $schema = Schema::connection('rukun');
    $coreTable = config('database.connections.core.prefix').'users';
    $schema->rename($coreTable, 'users');

    try {
        userTableNormalizationMigration()->up();
    } finally {
        if ($schema->hasTable('users') && ! $schema->hasTable($coreTable)) {
            $schema->rename('users', $coreTable);
        }
    }

    expect($schema->hasTable('users'))->toBeFalse()
        ->and($user->fresh()->public_id)->toBe($user->public_id)
        ->and($assignment->fresh()->user_id)->toBe($user->id);
    $newUser = User::factory()->create();
    expect($newUser->id)->toBeGreaterThan($user->id);
    $assignment->update(['user_id' => $newUser->id]);
    expect($assignment->fresh()->user_id)->toBe($newUser->id);
    $target = DB::connection('rukun')->selectOne("SELECT confrelid::regclass::text AS target FROM pg_constraint WHERE conrelid = 'role_assignments'::regclass AND conname = 'role_assignments_user_id_fkey'");
    expect($target->target)->toBe($coreTable);
});

it('refuses ambiguous dual identity tables without changing either dataset', function () {
    $user = User::factory()->create();
    DB::connection('rukun')->statement('CREATE TABLE users (id bigint PRIMARY KEY, name text)');
    DB::connection('rukun')->table('users')->insert(['id' => $user->id, 'name' => 'Different legacy identity']);

    try {
        expect(fn () => userTableNormalizationMigration()->up())->toThrow(RuntimeException::class, 'Reconcile their identities');
        expect($user->fresh()->public_id)->toBe($user->public_id)
            ->and(DB::connection('rukun')->table('users')->value('name'))->toBe('Different legacy identity');
    } finally {
        Schema::connection('rukun')->drop('users');
    }
});
