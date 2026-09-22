<?php

namespace Core\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BootstrapAdminCommand extends Command
{
    protected $signature = 'identity:bootstrap-admin {email} {--name=Administrator}';

    protected $description = 'Create the first verified administrator and assign the super-admin role';

    public function handle(): int
    {
        if (User::role('super-admin')->exists()) {
            $this->components->error('A super-admin already exists.');

            return self::FAILURE;
        }

        $input = ['email' => Str::lower($this->argument('email')), 'name' => $this->option('name'), 'password' => $this->secret('Password (minimum 8 characters)')];
        $validated = Validator::make($input, ['email' => ['required', 'email', 'unique:users,email'], 'name' => ['required', 'string', 'max:100'], 'password' => ['required', 'string', 'min:8']])->validate();
        $user = User::query()->create(['name' => $validated['name'], 'email' => $validated['email'], 'password' => Hash::make($validated['password'])]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->assignRole('super-admin');
        $this->components->info("Super administrator {$user->email} created.");

        return self::SUCCESS;
    }
}
