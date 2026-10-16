<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    /** Membuat akun owner & admin. Akun yang sudah ada tidak diubah. */
    public function run(): void
    {
        $accounts = [
            ['name' => 'Owner Bungcu', 'username' => 'owner', 'email' => config('acicu.owner_email'), 'role' => User::ROLE_OWNER],
            ['name' => 'Admin Dapur', 'username' => 'admin', 'email' => config('acicu.admin_email'), 'role' => User::ROLE_ADMIN],
        ];

        foreach ($accounts as $account) {
            if (User::query()->where('email', $account['email'])->exists()) {
                $this->command?->line("Akun {$account['email']} sudah ada, dilewati.");

                continue;
            }

            $fromEnv = (string) config('acicu.admin_password');
            $password = $fromEnv !== '' ? $fromEnv : Str::password(14, true, true, false);

            User::query()->create($account + ['password' => $password, 'is_active' => true]);

            $this->command?->info("Akun {$account['role']} dibuat: {$account['username']} / {$account['email']}"
                .($fromEnv === '' ? "  |  password: {$password}   (catat sekarang, tidak ditampilkan lagi)" : '  |  password dari ACICU_ADMIN_PASSWORD'));
        }
    }
}
