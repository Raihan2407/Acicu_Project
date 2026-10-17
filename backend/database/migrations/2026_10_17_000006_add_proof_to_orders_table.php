<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Bukti pembayaran (screenshot transfer/QRIS) yang diunggah pelanggan, untuk divalidasi admin. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('proof_path', 255)->nullable()->after('customer_paid_at');
            $table->timestamp('proof_uploaded_at')->nullable()->after('proof_path');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['proof_path', 'proof_uploaded_at']);
        });
    }
};
