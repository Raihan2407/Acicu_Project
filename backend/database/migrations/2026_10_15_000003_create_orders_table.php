<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number', 20)->unique();
            // Token acak untuk halaman status pesanan pelanggan (nomor PO berurutan mudah ditebak).
            $table->string('tracking_token', 40)->unique();

            $table->string('customer_name', 100);
            $table->string('whatsapp', 20);
            $table->text('address');
            $table->date('delivery_date')->index();
            $table->string('delivery_time', 5);
            $table->text('notes')->nullable();

            $table->unsignedInteger('subtotal');
            $table->unsignedInteger('shipping_fee')->default(0);
            $table->unsignedInteger('discount')->default(0);
            $table->unsignedInteger('total');

            // Alur pembayaran (tanpa unggah bukti):
            //  1. checkout            -> status menunggu_pembayaran, payment_status belum_bayar (ada batas waktu)
            //  2. pelanggan klik "Saya Sudah Bayar" -> menunggu_konfirmasi, payment_status menunggu
            //  3. admin cek mutasi & konfirmasi     -> proses_dapur, payment_status lunas
            $table->string('payment_method', 50)->nullable();
            $table->string('payment_status', 20)->default('belum_bayar')->index(); // belum_bayar|menunggu|lunas|ditolak
            $table->string('status', 30)->default('menunggu_pembayaran')->index();
            $table->timestamp('payment_deadline')->nullable();   // lewat batas ini & belum bayar => otomatis batal
            $table->timestamp('customer_paid_at')->nullable();   // saat pelanggan menekan "Saya Sudah Bayar"
            $table->string('rejected_reason')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->index('created_at');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');       // snapshot nama saat dipesan
            $table->unsignedInteger('qty');
            $table->unsignedInteger('unit_price'); // snapshot harga saat dipesan
            $table->unsignedInteger('subtotal');
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
