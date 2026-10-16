/* Fungsi bantu umum: format rupiah/tanggal, escape HTML, toast, dll. */
(function () {
  var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
  var MONTHS_LONG = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
  var DAYS_LONG = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

  function pad(n) { return String(n).padStart(2, '0'); }

  function rupiah(n) {
    n = Number(n) || 0;
    return 'Rp' + Math.round(n).toLocaleString('id-ID');
  }

  function rupiahCompact(n) {
    n = Number(n) || 0;
    if (n >= 1000000) return 'Rp' + (Math.round(n / 100000) / 10).toString().replace('.', ',') + 'jt';
    if (n >= 1000) return 'Rp' + Math.round(n / 1000) + 'k';
    return 'Rp' + n;
  }

  /** "2026-10-15" -> Date lokal (tanpa geser zona waktu) */
  function parseDate(str) {
    var p = String(str).split('-');
    return new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2]));
  }

  function isoDate(d) {
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  }

  /* ---------- jam server & zona waktu (selalu WIB / Asia/Jakarta) ---------- */
  var clockOffset = 0; // selisih (ms) jam server - jam perangkat
  var TZ = 'Asia/Jakarta';
  var fmt = new Intl.DateTimeFormat('en-GB', { timeZone: TZ, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', weekday: 'short' });
  var DOW = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 };

  function syncClock(serverMs) {
    serverMs = Number(serverMs);
    if (serverMs > 0) clockOffset = serverMs - Date.now();
  }
  /** Date yang sudah dikoreksi dengan jam server */
  function now() { return new Date(Date.now() + clockOffset); }

  /** Pecah Date menjadi bagian-bagian waktu WIB */
  function wib(d) {
    var o = {};
    fmt.formatToParts(d).forEach(function (p) { o[p.type] = p.value; });
    return { y: +o.year, mo: +o.month, d: +o.day, h: +o.hour, i: +o.minute, s: +o.second, dow: DOW[o.weekday] };
  }
  function toDate(v) { return v instanceof Date ? v : new Date(v); }

  function todayIso() { var w = wib(now()); return w.y + '-' + pad(w.mo) + '-' + pad(w.d); }

  function addDays(iso, n) {
    var d = parseDate(iso);
    d.setDate(d.getDate() + n);
    return isoDate(d);
  }

  /** "2026-10-15" (tanggal saja) atau waktu lengkap -> bagian tanggal WIB */
  function dparts(v) {
    if (typeof v === 'string' && v.length === 10) { var p = v.split('-'); return { y: +p[0], mo: +p[1], d: +p[2] }; }
    return wib(toDate(v));
  }

  /** 07 Mei 2025 */
  function dateLong(v) { var w = dparts(v); return pad(w.d) + ' ' + MONTHS[w.mo - 1] + ' ' + w.y; }

  /** 24 Okt */
  function dateShort(v) { var w = dparts(v); return pad(w.d) + ' ' + MONTHS[w.mo - 1]; }

  /** 10:14 WIB */
  function timeWib(v) { var w = wib(toDate(v)); return pad(w.h) + ':' + pad(w.i) + ' WIB'; }

  /** 24 Okt, 10:14 WIB */
  function dateTimeShort(v) { return dateShort(v) + ', ' + timeWib(v); }

  /** Kamis, 15 Oktober 2026 */
  function dateFull(d) {
    var w = wib(d || now());
    return DAYS_LONG[w.dow] + ', ' + w.d + ' ' + MONTHS_LONG[w.mo - 1] + ' ' + w.y;
  }

  /** 18:25:07 WIB (jam hidup) */
  function clockWib(d) { var w = wib(d || now()); return pad(w.h) + ':' + pad(w.i) + ':' + pad(w.s) + ' WIB'; }

  /** Menit sejak tengah malam WIB (untuk menonaktifkan slot jam yang sudah lewat) */
  function minutesOfDay() { var w = wib(now()); return w.h * 60 + w.i; }

  /** Label status pesanan: satu sumber agar sama di semua halaman */
  var STATUS_LABEL = { menunggu_pembayaran: 'Menunggu Pembayaran', menunggu_konfirmasi: 'Menunggu Konfirmasi', proses_dapur: 'Proses Dapur', selesai: 'Selesai', dibatalkan: 'Dibatalkan' };

  function esc(value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function debounce(fn, ms) {
    var t;
    return function () {
      var args = arguments, ctx = this;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(ctx, args); }, ms);
    };
  }

  function toast(message, type) {
    var host = document.getElementById('toastHost');
    if (!host) {
      host = document.createElement('div');
      host.id = 'toastHost';
      host.className = 'toast-host';
      host.setAttribute('role', 'status');
      host.setAttribute('aria-live', 'polite');
      document.body.appendChild(host);
    }
    var el = document.createElement('div');
    el.className = 'toast toast-' + (type || 'info');
    el.textContent = message;
    host.appendChild(el);
    requestAnimationFrame(function () { el.classList.add('show'); });
    setTimeout(function () {
      el.classList.remove('show');
      setTimeout(function () { el.remove(); }, 300);
    }, 3500);
  }

  function waLink(number, text) {
    return 'https://wa.me/' + number + (text ? '?text=' + encodeURIComponent(text) : '');
  }

  window.Util = {
    rupiah: rupiah, rupiahCompact: rupiahCompact, parseDate: parseDate, isoDate: isoDate, todayIso: todayIso,
    addDays: addDays, dateLong: dateLong, dateShort: dateShort, timeWib: timeWib, dateTimeShort: dateTimeShort,
    dateFull: dateFull, clockWib: clockWib, minutesOfDay: minutesOfDay, syncClock: syncClock, now: now, STATUS_LABEL: STATUS_LABEL, esc: esc, debounce: debounce, toast: toast, waLink: waLink, pad: pad,
  };
})();
