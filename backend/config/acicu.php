<?php

/*
| Pengaturan bisnis ACICU. Nilai bisa diubah lewat file .env tanpa mengubah kode.
| Semua nilai pembayaran di bawah ini hanya CONTOH: ganti sebelum dipakai sungguhan.
*/

return [
    // Ongkir flat per pesanan (rupiah). Sesuaikan dengan lokasi pengiriman.
    'shipping_fee' => (int) env('ACICU_SHIPPING_FEE', 5000),

    // Slot jam pengiriman (bawaan: tiap jam, 06:00 sampai 22:00). Bisa diubah dari panel admin > Pengaturan.
    'delivery_slots' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'ACICU_DELIVERY_SLOTS',
        implode(',', array_map(fn ($h) => sprintf('%02d:00', $h), range(6, 22)))
    ))))),

    // Pesanan hanya boleh dijadwalkan sampai sekian hari ke depan.
    // Pesanan untuk hari ini harus masuk minimal sekian menit sebelum jam kirim (dapur butuh waktu menggoreng).
    'order_cutoff_minutes' => (int) env('ACICU_ORDER_CUTOFF_MINUTES', 90),

    'max_advance_days' => (int) env('ACICU_MAX_ADVANCE_DAYS', 30),

    // Batas waktu pembayaran setelah checkout (menit). Lewat batas & belum bayar => pesanan otomatis batal.
    'payment_ttl_minutes' => (int) env('ACICU_PAYMENT_TTL_MINUTES', 60),

    // Label batch pre-order yang sedang aktif (tampil di panel admin).
    'batch_label' => env('ACICU_BATCH_LABEL', 'Batch 1'),

    'payment' => [
        'bank_name' => env('ACICU_BANK_NAME', 'BCA'),
        'bank_account' => env('ACICU_BANK_ACCOUNT', '827-091-2384'),   // CONTOH
        'bank_holder' => env('ACICU_BANK_HOLDER', 'Aneka Aci Bungcu'),  // CONTOH
        // Path file QRIS di storage publik (mis. "qris/qris.png"). Kosong = frontend menampilkan placeholder.
        'qris_image' => env('ACICU_QRIS_IMAGE'),
    ],

    'contact' => [
        'whatsapp' => env('ACICU_WHATSAPP', '6282286512274'),
        'whatsapp_display' => env('ACICU_WHATSAPP_DISPLAY', '082286512274'),
        'instagram' => env('ACICU_INSTAGRAM', 'acicu.licious'),
        'tiktok' => env('ACICU_TIKTOK', 'acicu.licious'),
        'admin_hours' => env('ACICU_ADMIN_HOURS', '08:00 - 19:00 WIB'),
    ],

    // Alamat frontend (dipakai untuk tautan status pesanan di pesan WhatsApp). Ambil yang pertama dari FRONTEND_URL.
    'frontend_url' => trim(explode(',', (string) env('FRONTEND_URL', 'http://localhost:5500'))[0]),

    /*
    | Pesan WhatsApp otomatis ke pelanggan.
    |  log    = (bawaan) hanya dicatat di storage/logs/laravel.log, TIDAK ada pesan terkirim. Gratis, aman untuk uji.
    |  fonnte = kirim sungguhan lewat layanan Fonnte (butuh akun & token, cek harga di situs mereka).
    |  off    = nonaktif.
    */
    'notify' => [
        'driver' => env('ACICU_WA_DRIVER', 'log'),
        'fonnte_token' => env('ACICU_FONNTE_TOKEN'),
    ],

    /*
    | Pembayaran QRIS.
    |  manual   = (bawaan) QRIS statis (gambar) + admin konfirmasi manual.
    |  mock     = QRIS dinamis SIMULASI: kode unik per pesanan + tombol "simulasi bayar". Gratis, hanya untuk demo/kuliah.
    |  midtrans = QRIS dinamis asli lewat Midtrans (sandbox gratis untuk uji; produksi butuh akun merchant terverifikasi).
    */
    'gateway' => [
        'driver' => env('ACICU_PAYMENT_DRIVER', 'manual'),
        'midtrans_server_key' => env('ACICU_MIDTRANS_SERVER_KEY'),
        'midtrans_production' => (bool) env('ACICU_MIDTRANS_PRODUCTION', false),
    ],

    // Masa berlaku token login admin (jam). "Ingat saya" memakai remember_hours.
    'token_ttl_hours' => (int) env('ACICU_TOKEN_TTL_HOURS', 12),
    'token_remember_hours' => (int) env('ACICU_TOKEN_REMEMBER_HOURS', 24 * 7),

    // Akun admin awal (dibuat oleh seeder). Jika ACICU_ADMIN_PASSWORD kosong,
    // seeder membuat sandi acak dan menampilkannya SEKALI di terminal.
    'admin_password' => env('ACICU_ADMIN_PASSWORD'),
    'owner_email' => env('ACICU_OWNER_EMAIL', 'owner@acicu.test'),
    'admin_email' => env('ACICU_ADMIN_EMAIL', 'admin@acicu.test'),
];
