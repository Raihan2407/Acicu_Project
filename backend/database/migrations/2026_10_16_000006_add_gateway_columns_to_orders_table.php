<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Kolom untuk QRIS dinamis: satu kode QR per pesanan + referensi transaksi di penyedia pembayaran. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_gateway', 20)->nullable()->after('payment_method');
            $table->string('payment_ref', 80)->nullable()->unique()->after('payment_gateway');
            $table->text('qr_string')->nullable()->after('payment_ref');
            $table->string('qr_url', 500)->nullable()->after('qr_string');
            $table->timestamp('qr_expires_at')->nullable()->after('qr_url');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['payment_ref']);
            $table->dropColumn(['payment_gateway', 'payment_ref', 'qr_string', 'qr_url', 'qr_expires_at']);
        });
    }
};
