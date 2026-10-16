<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengaturan toko. Urutan prioritas: nilai di tabel `settings` (diubah admin) -> config/acicu.php (.env).
 * Semua kode yang butuh ongkir, slot jam, rekening, dsb. memakai kelas ini, bukan config() langsung.
 */
final class Settings
{
    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    /** Nilai bawaan dari config/acicu.php, dalam bentuk datar (key => nilai). */
    public static function defaults(): array
    {
        $c = config('acicu');

        return [
            'store_open' => true,
            'closed_message' => 'Pre-order sedang ditutup. Silakan kembali lagi nanti.',
            'batch_label' => $c['batch_label'],
            'shipping_fee' => $c['shipping_fee'],
            'delivery_slots' => $c['delivery_slots'],
            'order_cutoff_minutes' => $c['order_cutoff_minutes'],
            'max_advance_days' => $c['max_advance_days'],
            'payment_ttl_minutes' => $c['payment_ttl_minutes'],
            'bank_name' => $c['payment']['bank_name'],
            'bank_account' => $c['payment']['bank_account'],
            'bank_holder' => $c['payment']['bank_holder'],
            'qris_image' => $c['payment']['qris_image'],
            'whatsapp' => $c['contact']['whatsapp'],
            'whatsapp_display' => $c['contact']['whatsapp_display'],
            'instagram' => $c['contact']['instagram'],
            'tiktok' => $c['contact']['tiktok'],
            'admin_hours' => $c['contact']['admin_hours'],
        ];
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $values = self::defaults();

        if (Schema::hasTable('settings')) {
            foreach (DB::table('settings')->get() as $row) {
                if (array_key_exists($row->key, $values)) {
                    $values[$row->key] = json_decode((string) $row->value, true);
                }
            }
        }

        return self::$cache = $values;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    /** Simpan sekumpulan pengaturan. Kunci yang tidak dikenal diabaikan. */
    public static function set(array $values): void
    {
        $allowed = array_keys(self::defaults());

        foreach ($values as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                continue;
            }
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => json_encode($value, JSON_UNESCAPED_UNICODE), 'updated_at' => now(), 'created_at' => now()],
            );
        }

        self::flush();
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
