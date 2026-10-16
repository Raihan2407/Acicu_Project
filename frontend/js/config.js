/*
 * Pengaturan frontend.
 * API_BASE = alamat backend Laravel (folder /backend).
 *
 * Otomatis: memakai host yang sama dengan halaman ini pada port 8000
 * (hasil `php artisan serve`). Jika backend ada di alamat lain, ubah nilai API_BASE di bawah,
 * contoh: 'http://acicu-api.test/api'
 */
window.ACICU_CONFIG = {
  API_BASE: (function () {
    var host = (location.hostname && location.protocol.indexOf('http') === 0) ? location.hostname : 'localhost';
    return 'http://' + host + ':8000/api';
  })(),
};
