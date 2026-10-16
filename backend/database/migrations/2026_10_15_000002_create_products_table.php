<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');                       // nama lengkap (admin, checkout, struk)
            $table->string('short_name');                 // nama singkat (landing, keranjang)
            $table->string('slug')->unique();
            $table->string('category', 50)->default('Aci Kering')->index();
            $table->text('description')->nullable();      // deskripsi panjang (landing)
            $table->string('short_description')->nullable(); // deskripsi pendek (admin)
            $table->string('default_note')->nullable();   // catatan varian di ringkasan pesanan
            $table->unsignedInteger('price');
            $table->unsignedInteger('daily_quota')->default(0); // kuota porsi per hari
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
