# Dokumentasi API ACICU

## API di proyek ini untuk apa?
API (REST, format JSON) adalah **jembatan antara frontend dan database**. Frontend (HTML/CSS/JS di folder `frontend/`) tidak pernah menyentuh MySQL; semua data lewat API Laravel di `backend/`.

| Yang butuh API | Untuk apa |
|---|---|
| Landing page | Ambil menu + sisa kuota, jam buka/tutup, kontak, rekening |
| Checkout | Buat pesanan (kuota dikunci di server agar tidak oversell) |
| Pembayaran / Status | Tampilkan tagihan, hitung mundur, status pesanan |
| Panel admin | Login, dashboard, kelola pesanan/produk/stok, laporan, pengaturan, akun |

Karena frontend dan backend terpisah folder, tim frontend cukup tahu daftar endpoint ini, tanpa membaca kode PHP.

- **Base URL**: `http://localhost:8000/api`
- **Format**: kirim `Accept: application/json`. Body JSON, atau `multipart/form-data` bila ada unggah file.
- **Auth admin**: `Authorization: Bearer <token>` (Laravel Sanctum, didapat dari `POST admin/login`).
- **Waktu**: setiap respons berisi header `X-Server-Time` (epoch ms). Frontend memakainya agar jam selalu benar (WIB).
- **Error**: `{"message": "...", "errors": {"field": ["pesan"]}}` dengan kode 401 (belum login), 403 (peran tidak cukup), 404, 422 (validasi), 429 (terlalu sering).
- **Peran**: `admin` (pesanan, produk, stok) dan `owner` (semua + laporan, pengaturan, akun).

## Alur status pesanan
`menunggu_pembayaran → menunggu_konfirmasi → proses_dapur → selesai` (atau `dibatalkan`).
Pembayaran: `belum_bayar → menunggu → lunas` (atau `ditolak`). Batas bayar 60 menit (atur di Pengaturan); lewat batas pesanan batal otomatis dan kuota kembali.

---
## A. Publik (tanpa login)

### GET `/config`
Pengaturan toko untuk landing, checkout, pembayaran.
Respons `data`: `store_open`, `closed_message`, `shipping_fee`, `delivery_slots[]` (jam "HH:MM", default 06:00-22:00 tiap jam), `order_cutoff_minutes`, `max_advance_days`, `payment_ttl_minutes`, `today`, `server_time`, `batch_label`, `payment{bank_name,bank_account,bank_holder,qris_url}`, `contact{whatsapp,whatsapp_display,instagram,tiktok,admin_hours}`.

### GET `/products?date=YYYY-MM-DD`
Menu aktif + sisa kuota pada tanggal kirim itu (`daily_quota`, `reserved`, `remaining`, `available`). Tanpa `date` = hari ini.

### POST `/orders`  (maks 10x/menit)
```json
{ "name":"Siti", "whatsapp":"081234567890", "address":"Jl. Contoh No. 1, Pekanbaru",
  "delivery_date":"2026-10-17", "delivery_time":"11:00", "notes":null,
  "items":[{"product_id":1,"qty":2}] }
```
Gagal 422 bila: toko tutup, jam bukan salah satu slot, jam sudah lewat batas pesan, tanggal di luar rentang, kuota tidak cukup.
Sukses 201: `data.tracking_token` dipakai untuk halaman pembayaran/status (`pembayaran.html?t=<token>`).

### GET `/orders/{token}`
Detail pesanan untuk pelanggan (nomor WA disamarkan). Dipolling frontend.

### POST `/orders/{token}/paid`
Tombol "Saya Sudah Bayar": status jadi `menunggu_konfirmasi`. Uang belum dianggap masuk sampai admin konfirmasi. Body opsional `multipart/form-data` dengan field `proof` (jpg/jpeg/png/webp, maks 2 MB). Wajib bila pengaturan `require_proof` aktif (422 pada `proof`). Respons memuat `proof_url` dan `proof_uploaded_at`.

---
## B. Admin (Bearer token)

### Akun
| Method | Path | Keterangan |
|---|---|---|
| POST | `/admin/login` | `{login, password, remember}` → `data.token`. Maks 5x/menit |
| POST | `/admin/logout` | Cabut token |
| GET | `/admin/me` | Profil, peran, `pending_count` (badge) |
| POST | `/admin/me/password` | `{current_password, password, password_confirmation}`; sesi lain keluar |

### Dashboard
`GET /admin/dashboard` → `cards` (pesanan hari ini + persen vs kemarin, perlu diproses, menu terlaris, pendapatan 7 hari), `trend[]` 7 hari, `insight`, `recent[]` 3 aktivitas, `batch_label`.

### Produk & stok
| Method | Path | Keterangan |
|---|---|---|
| GET | `/admin/products?search=&category=` | Daftar + `reserved_today` |
| POST | `/admin/products` | Tambah (multipart, `image` opsional) |
| POST/PUT/PATCH | `/admin/products/{id}` | Ubah |
| PATCH | `/admin/products/{id}/stock` | **Cepat**: `{daily_quota?, is_active?}` |
| DELETE | `/admin/products/{id}` | Hapus (riwayat pesanan tetap) |

### Pesanan
| Method | Path | Keterangan |
|---|---|---|
| GET | `/admin/orders?search=&date=&status=&page=&per_page=` | Daftar + meta `queue_total`, `pending_count` |
| GET | `/admin/orders/export` | Unduh Excel (filter sama) |
| GET | `/admin/orders/{id}` | Detail |
| POST | `/admin/orders/{id}/validate` | Konfirmasi bayar `{payment_method}` → `proses_dapur` |
| POST | `/admin/orders/{id}/reject` | Tolak/batalkan `{reason?}`; kuota kembali |
| PATCH | `/admin/orders/{id}/status` | `{status:"selesai"}` |

### Khusus Owner
| Method | Path | Keterangan |
|---|---|---|
| GET | `/admin/reports?from=&to=&page=&per_page=` | Transaksi lunas + ringkasan |
| GET | `/admin/reports/export` | Excel laporan |
| GET | `/admin/settings` | Semua pengaturan toko |
| POST/PUT | `/admin/settings` | Ubah sebagian atau semua: `store_open, closed_message, batch_label, shipping_fee, delivery_slots (array/"06:00,07:00"), order_cutoff_minutes, max_advance_days, payment_ttl_minutes, bank_*, whatsapp, instagram, tiktok, admin_hours`, file `qris`, `remove_qris` |
| GET | `/admin/users` | Daftar akun |
| POST | `/admin/users` | `{name, username, email, role, password}` (sandi min 10, huruf+angka) |
| PUT/PATCH | `/admin/users/{id}` | Ubah nama/peran/`is_active` (tidak boleh nonaktifkan diri sendiri; minimal 1 owner aktif) |
| POST | `/admin/users/{id}/password` | Reset sandi `{password}` |

## Pemberitahuan ke owner
`GET /config` memuat `require_proof`. Daftar pesanan admin memuat `has_proof`, detail memuat `proof_url`. Pengaturan `.env`: `ACICU_REQUIRE_PROOF` (bawaan), `ACICU_OWNER_NOTIFY=off|log|telegram`, `ACICU_TELEGRAM_BOT_TOKEN`, `ACICU_TELEGRAM_CHAT_ID`. Kegagalan kirim Telegram hanya dicatat di log dan tidak menggagalkan pesanan. Panel admin memantau `GET /admin/me` (`pending_count`) tiap 15 detik untuk bunyi dan badge.

## Sinkronisasi landing ↔ admin
Semua dari satu database, jadi tidak ada data ganda. Admin ubah kuota/status produk → `GET /products` berubah, landing menyegarkan tiap ±15 detik. Admin ubah Pengaturan → `GET /config`, landing/checkout menyegarkan tiap 30 detik. Pesanan checkout → langsung muncul di Data Pesanan admin (polling 15 detik).
