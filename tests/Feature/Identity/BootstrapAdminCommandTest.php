<?php

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a verified super administrator', function () {
    $this->seed(RbacSeeder::class);

    $this->artisan('identity:bootstrap-admin', ['email' => 'admin@example.com', '--name' => 'Administrator'])
        ->expectsQuestion('Password (minimum 8 characters)', 'secure-password')
        ->assertSuccessful();

    $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
    expect($admin->hasVerifiedEmail())->toBeTrue()
        ->and($admin->hasRole('super-admin'))->toBeTrue();
});
