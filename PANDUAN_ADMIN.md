# Panduan Admin ACICU (untuk tim)

Buka `http://localhost:5500/admin/login.html`. Akun **Owner** bisa semuanya; akun **Admin** hanya Dashboard, Data Pesanan, Data Produk.

## 1. Rutinitas harian
1. **Data Pesanan**: pesanan baru berstatus *Menunggu Konfirmasi* (baris kuning, badge hijau di menu). Halaman menyegarkan sendiri tiap 15 detik.
2. Cek uang masuk di mutasi BCA/QRIS. Cocok → **Konfirmasi Bayar** (pesanan masuk dapur). Tidak ada → **Tolak / Batalkan** (kuota kembali).
3. Setelah dikirim → **Tandai Selesai**.
4. **Dashboard** menampilkan ringkasan hari ini, tren 7 hari (toggle Porsi/Nominal), dan 3 aktivitas terbaru.

## 2. Mengatur stok
**Data Produk**: ubah angka kuota lalu **Simpan**. Kuota adalah porsi per hari kirim. Saklar di kolom Status menyembunyikan/menampilkan menu. Landing page ikut berubah dalam ±15 detik. Tombol pensil untuk edit lengkap (nama, harga, foto), tempat sampah untuk hapus.

## 3. Pengaturan (Owner)
- **Toko & Pengiriman**: buka/tutup pre-order, pesan saat tutup, label batch, jam kirim (tambah/hapus; tombol "Isi tiap jam 06:00-22:00"), ongkir, batas pesan sebelum jam kirim, maksimal hari di muka, batas waktu bayar.
- **Pembayaran & Kontak**: rekening, QRIS (unggah gambar), WhatsApp, Instagram, TikTok, jam layanan. **Ganti data contoh sebelum dipakai asli.**
- **Akun Tim**: tambah akun, aktif/nonaktifkan, reset sandi, ganti sandi sendiri.
Klik **Simpan Pengaturan** agar berlaku.

## 4. Laporan & ekspor
Owner: **Laporan Penjualan** pilih periode lalu **Unduh Excel**. Data Pesanan juga punya Unduh Excel (mengikuti filter).

## 5. Mengosongkan data uji
Sebelum rilis asli: `cd backend` lalu `php artisan acicu:reset-orders` (hanya menghapus pesanan & log; produk, akun, pengaturan aman). Data demo opsional: `php artisan db:seed --class=DemoOrderSeeder`.

## 6. Lupa sandi
Admin minta Owner reset di Pengaturan → Akun Tim. Jika Owner lupa: `php artisan tinker` lalu `User::where('username','owner')->first()->update(['password'=>'SandiBaru123abc']);`. Jangan membagikan sandi lewat chat grup.

## 7. QRIS dinamis & WhatsApp otomatis
Diatur di `backend/.env`, lalu restart `php artisan serve`. Pada `.env` yang sudah ada, tambahkan barisnya sendiri.
- **Demo gratis:** `ACICU_PAYMENT_DRIVER=mock` memberi kode QR unik per pesanan dan tombol "Simulasi bayar" di halaman pembayaran. Ini simulasi, bukan pembayaran sungguhan. Pesanan langsung lunas lalu masuk dapur.
- **Pembayaran asli:** `ACICU_PAYMENT_DRIVER=midtrans`, isi `ACICU_MIDTRANS_SERVER_KEY`, dan daftarkan alamat `https://DOMAIN-ANDA/api/payments/midtrans/notification` sebagai Notification URL di dashboard Midtrans. Webhook harus bisa diakses internet. Akun produksi butuh verifikasi usaha dan ada biaya per transaksi, cek di situs Midtrans.
- **Kembali ke QRIS statis:** `ACICU_PAYMENT_DRIVER=manual`.
- **WhatsApp:** `ACICU_WA_DRIVER=log` (bawaan) hanya mencatat pesan di `backend/storage/logs/laravel.log`. Untuk kirim sungguhan: `ACICU_WA_DRIVER=fonnte` dan `ACICU_FONNTE_TOKEN=...` (akun Fonnte, cek harga dan aturannya di situs mereka).
- Uji kirim: Pengaturan → Pembayaran & Kontak → **Kirim pesan uji**.
- Setelah memperbarui kode, jalankan `php artisan migrate` (menambah kolom QR di tabel `orders`, data lama aman).
