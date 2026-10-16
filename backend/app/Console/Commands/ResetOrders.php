<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetOrders extends Command
{
    protected $signature = 'acicu:reset-orders {--force : Tanpa konfirmasi}';

    protected $description = 'Hapus SEMUA pesanan (dan log aktivitas) untuk memulai dari nol. Akun, menu, dan pengaturan tidak disentuh.';

    public function handle(): int
    {
        $count = Order::query()->count();

        if (! $this->option('force') && ! $this->confirm("Hapus {$count} pesanan beserta riwayat aktivitas? Tindakan ini tidak bisa dibatalkan.")) {
            $this->info('Dibatalkan.');

            return self::SUCCESS;
        }

        DB::transaction(function () {
            OrderItem::query()->delete();
            Order::query()->delete();
            ActivityLog::query()->delete();
        });

        $this->info("Selesai: {$count} pesanan dihapus. Dashboard kembali kosong.");

        return self::SUCCESS;
    }
}
