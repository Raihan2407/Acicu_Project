# ACICU – Aneka Aci Bungcu (Pre-Order)

Proyek mata kuliah Pemrograman Web. Dua folder terpisah:

| Folder | Isi | Dikerjakan oleh |
|---|---|---|
| `backend/` | Laravel 13 + REST API + MySQL + Sanctum | tim backend |
| `frontend/` | HTML/CSS/JS statis (halaman pelanggan + panel admin) | tim frontend |
| `frontend/dev-mock/` | API tiruan Node.js, supaya frontend bisa dikembangkan tanpa Laravel | frontend |

## Alur pelanggan
Landing (menu & keranjang) → **Checkout** → **Pembayaran** (QRIS / transfer BCA, hitung mundur 60 menit) → tombol **Saya Sudah Bayar** → **Status Pesanan**.
Tidak ada unggah bukti. Admin mengecek mutasi/QRIS lalu menekan **Konfirmasi Bayar** di panel admin → pesanan masuk dapur.
Pesanan yang tidak dibayar sampai batas waktu otomatis dibatalkan dan kuotanya kembali.

## Menjalankan backend
Prasyarat: PHP ≥ 8.3 (ekstensi openssl, pdo_mysql, mbstring, fileinfo, zip, gd/ctype), Composer, MySQL (Laragon sudah lengkap).

```bash
cd backend
composer install
cp .env.example .env            # Windows: copy .env.example .env
php artisan key:generate
# buat database kosong bernama "acicu" di MySQL (phpMyAdmin / HeidiSQL)
php artisan migrate:fresh --seed
php artisan storage:link
php artisan serve               # http://127.0.0.1:8000
```
Sesuaikan `DB_USERNAME`/`DB_PASSWORD` di `.env` bila MySQL Anda berbeda.

**Akun admin.** Seeder membuat 2 akun: `owner` (Owner, boleh lihat Laporan) dan `admin` (Admin Dapur).
Jika `ACICU_ADMIN_PASSWORD` di `.env` kosong, kata sandi acak **dicetak sekali** di terminal saat `migrate --seed`. Catat saat itu. Ganti/atur ulang dengan `php artisan migrate:fresh --seed`.
Database awal **kosong** (tanpa pesanan) agar siap diuji. Data demo opsional: `php artisan db:seed --class=DemoOrderSeeder`. Hapus pesanan uji: `php artisan acicu:reset-orders`.

Tes otomatis (belum pernah dijalankan oleh pembuat, lihat catatan): `php artisan test`

## Menjalankan frontend
Alamat API dibaca otomatis dari `frontend/js/config.js` (host halaman, port 8000). Sajikan folder `frontend` dengan server statis apa saja:
```bash
php -S localhost:5500 -t frontend       # atau Live Server VS Code
```
Buka `http://localhost:5500/` (pelanggan) dan `http://localhost:5500/admin/login.html` (admin).
Bila memakai alamat selain `localhost`/`127.0.0.1`, tambahkan ke `FRONTEND_URL` di `backend/.env` (CORS).

Tanpa Laravel: `node frontend/dev-mock/server.js` (API tiruan di port 8000; admin `owner`/`admin`, sandi `demo12345`, hanya untuk mock).

## Waktu & data realtime
- Semua waktu memakai **jam server** (header `X-Server-Time` di setiap respons API) dan selalu ditampilkan dalam **WIB**, jadi tetap benar walau jam/zona waktu perangkat pengguna salah.
- Panel admin: jam hidup di topbar; Dashboard menyegarkan data tiap 30 dtk, Data Pesanan tiap 15 dtk, Data Produk tiap 30 dtk, badge pesanan baru tiap 30 dtk (jeda saat tab tidak aktif atau dialog terbuka).
- Pelanggan: kuota menu diperbarui tiap 60 dtk, halaman pembayaran/status otomatis menyesuaikan saat admin mengonfirmasi, hitung mundur mengikuti jam server.
- Slot jam kirim hari ini otomatis nonaktif setelah lewat batas pesan (`ACICU_ORDER_CUTOFF_MINUTES`, bawaan 90 menit sebelum jam kirim); server juga menolaknya.
- Ini polling berkala (bukan WebSocket), cukup untuk skala ini dan tanpa layanan tambahan.

## Pembayaran & pemberitahuan ke owner
QRIS statis (gambar dari Pengaturan) dan transfer manual. Pelanggan boleh mengunggah bukti transfer lalu menekan "Saya Sudah Bayar"; admin memvalidasi. Pengaturan "Wajibkan unggah bukti" ada di Pengaturan, tab Pembayaran & Kontak. Owner tahu ada pembayaran baru lewat badge dan bunyi di panel admin, tombol WhatsApp pelanggan, dan Telegram opsional (lihat `PANDUAN_ADMIN.md` bagian 7).

## Dokumentasi
- `docs/API.md`: fungsi API di proyek ini + semua endpoint. `docs/ACICU.postman_collection.json`: impor ke Postman.
- `PANDUAN_ADMIN.md`: cara mengelola pesanan, stok, pengaturan, akun.
- Jam kirim bawaan tiap jam 06:00-22:00; semua bisa diubah owner di **Pengaturan**.

## API ringkas (`/api`)
Publik: `GET config`, `GET products?date=`, `POST orders`, `GET orders/{token}`, `POST orders/{token}/paid`.
Admin (Bearer token): `POST admin/login`, `POST admin/logout`, `GET admin/me`, `GET admin/dashboard`, CRUD `admin/products`,
`GET admin/orders` (+`/export`), `POST admin/orders/{id}/validate|reject`, `PATCH admin/orders/{id}/status`, `PATCH admin/products/{id}/stock`, dan khusus owner `admin/reports`, `admin/settings`, `admin/users` (lengkap di `docs/API.md`).

## Keamanan yang sudah diterapkan
Token Sanctum berumur terbatas, login dibatasi 5x/menit, checkout 10x/menit, peran owner/admin, laporan hanya owner,
nomor WA disamarkan di laporan & halaman publik, halaman status memakai token acak (bukan nomor PO), harga dihitung ulang di server,
kuota dikunci saat transaksi (anti-oversell), log aktivitas admin, tidak ada registrasi publik.

## ⚠️ Nilai CONTOH yang wajib diganti sebelum dipakai sungguhan
- Rekening BCA `827-091-2384` a.n. Aneka Aci Bungcu → `.env`: `ACICU_BANK_*`
- Gambar QRIS masih **placeholder**. Taruh QRIS asli di `backend/storage/app/public/qris/` lalu isi `ACICU_QRIS_IMAGE=qris/qris.png` di `.env`
- Ongkir Rp5.000, jam kirim 11:30 / 15:30, label batch → `.env` / `config/acicu.php`
- Kontak WA/IG/TikTok sudah sesuai data yang diberikan.
