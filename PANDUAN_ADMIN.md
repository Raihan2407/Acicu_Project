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

## 7. Bukti bayar dan pemberitahuan ke owner
- Pelanggan membayar ke QRIS/rekening, boleh mengunggah bukti (JPG/PNG/WEBP, maks 2 MB), lalu menekan "Saya Sudah Bayar". Bukti wajib secara bawaan (tanpa bukti, tombol menampilkan "Anda belum mengunggah bukti pembayaran"). Bisa dijadikan opsional di Pengaturan, Pembayaran & Kontak.
- Admin membuka Data Pesanan, menekan "Validasi Bukti" (atau "Konfirmasi Bayar" bila tanpa bukti), mencocokkan nominal, lalu mengonfirmasi atau menolak.
- Owner tahu ada pembayaran baru lewat: (1) panel admin yang terbuka: badge merah di Data Pesanan, bunyi, angka di judul tab, dan ikon lonceng untuk mematikan bunyi; (2) pelanggan menekan "Kabari Admin Sekarang" di halaman status, yang membuka WhatsApp dengan pesan siap kirim; (3) opsional Telegram: buat bot lewat @BotFather, lalu isi `ACICU_OWNER_NOTIFY=telegram`, `ACICU_TELEGRAM_BOT_TOKEN`, `ACICU_TELEGRAM_CHAT_ID` di `backend/.env` dan restart server.
- Tidak ada pesan WhatsApp otomatis, jadi tidak ada risiko nomor diblokir.
- Setelah memperbarui kode: `php artisan migrate` (menambah kolom bukti) dan `php artisan storage:link`.

