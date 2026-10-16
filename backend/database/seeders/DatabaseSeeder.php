<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Hanya data dasar: akun admin + menu. Tidak ada pesanan, jadi dashboard mulai dari nol.
     * Untuk data contoh (demo/presentasi): php artisan db:seed --class=DemoOrderSeeder
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            ProductSeeder::class,
        ]);
    }
}
