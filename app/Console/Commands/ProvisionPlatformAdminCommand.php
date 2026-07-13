<?php

namespace App\Console\Commands;

use App\Models\PlatformAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class ProvisionPlatformAdminCommand extends Command
{
    protected $signature = 'platform:provision-admin
                            {name : Platform administrator name}
                            {email : Platform administrator email}
                            {--password=password : Account password}';

    protected $description = 'Create a platform administrator with system-wide oversight access';

    public function handle(): int
    {
        if (PlatformAdmin::where('email', $this->argument('email'))->exists()) {
            $this->error('A platform administrator with this email already exists.');

            return self::FAILURE;
        }

        $admin = PlatformAdmin::create([
            'name' => $this->argument('name'),
            'email' => $this->argument('email'),
            'password' => Hash::make($this->option('password')),
            'is_active' => true,
        ]);

        $this->info("Platform administrator created: {$admin->email}");
        $this->line('Sign in at /platform/login');

        return self::SUCCESS;
    }
}
