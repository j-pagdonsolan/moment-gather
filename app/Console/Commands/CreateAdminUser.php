<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdminUser extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'admin:create-admin';

    /**
     * The console command description.
     */
    protected $description = 'Create or promote a user to Super Admin.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Step 1: Prompt for email and validate format
        $email = $this->ask('Email address');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid email.');

            return 1;
        }

        // Step 2: Look up existing user
        $user = User::where('email', $email)->first();

        // Step 3: If user exists, promote to Super Admin
        if ($user) {
            $role = Role::where('name', 'super_admin')->firstOrFail();
            $user->roles()->syncWithoutDetaching([$role->id]);
            $this->info("User '{$user->email}' promoted to Super Admin.");

            return 0;
        }

        // Step 4: User does not exist — create new Super Admin
        $name = $this->ask('Full name');
        $password = $this->secret('Password (hidden)');
        $confirm = $this->secret('Confirm password (hidden)');

        if ($password !== $confirm) {
            $this->error('Passwords do not match.');

            return 1;
        }

        $user = User::create([
            'name'     => $name,
            'email'    => $email,
            'password' => Hash::make($password),
        ]);

        $user->update(['is_active' => true]);

        $role = Role::where('name', 'super_admin')->firstOrFail();
        $user->roles()->syncWithoutDetaching([$role->id]);

        $this->info("Super Admin '{$email}' created successfully.");

        return 0;
    }
}
