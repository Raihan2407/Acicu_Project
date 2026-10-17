/* Kerangka bersama panel admin: cek login, sidebar, topbar, logout. */
(function () {
  var user = null;

  function redirectToLogin() {
    var here = location.pathname.split('/').pop();
    if (here === 'login.html') return;
    location.replace('login.html');
  }

  function renderShell(active) {
    var isOwner = user.can_view_reports;
    var items = [
      ['index.html', 'fa-chart-pie', 'Dashboard', 'dashboard'],
      ['pesanan.html', 'fa-receipt', 'Data Pesanan', 'pesanan'],
      ['produk.html', 'fa-ice-cream', 'Data Produk', 'produk'],
    ];
    if (isOwner) {
      items.push(['laporan.html', 'fa-file-invoice-dollar', 'Laporan Penjualan', 'laporan']);
      items.push(['pengaturan.html', 'fa-gear', 'Pengaturan', 'pengaturan']);
    }

    var menu = items.map(function (it) {
      var badge = it[3] === 'pesanan' ? '<span class="badge' + (user.pending_count ? '' : ' hidden') + '" id="pendingBadge">' + user.pending_count + '</span>' : '';
      return '<a href="' + it[0] + '" class="' + (it[3] === active ? 'active' : '') + '"><i class="fa-solid ' + it[1] + '"></i>' + it[2] + badge + '</a>';
    }).join('');

    var initial = (user.name || '?').trim().charAt(0).toUpperCase();
    document.getElementById('app-sidebar').innerHTML =
      '<a class="brand" href="index.html"><img src="../assets/img/logo.jpg" alt=""><span><b>ACICU</b><small>Panel Admin</small></span></a>' +
      '<nav class="menu">' + menu + '</nav>' +
      '<div class="side-foot"><div class="user-chip"><div class="avatar"><i class="fa-solid fa-user"></i></div>' +
      '<div class="who"><b>' + Util.esc(user.name) + '</b><small>' + Util.esc(user.role_label) + '</small></div></div>' +
      '<button type="button" class="logout" id="logoutBtn"><i class="fa-solid fa-right-from-bracket"></i>Keluar</button></div>';

    document.getElementById('app-topbar').innerHTML =
      '<button class="burger" id="burger" aria-label="Menu"><i class="fa-solid fa-bars"></i></button>' +
      '<span class="crumb">ACICU</span><form class="search" id="topSearch" role="search"><i class="fa-solid fa-magnifying-glass"></i>' +
      '<input type="search" id="topSearchInput" placeholder="Cari pesanan, produk, atau laporan…" autocomplete="off"></form>' +
      '<button type="button" class="bell" id="bellBtn" aria-pressed="true" title="Notifikasi pesanan"><i class="fa-solid fa-bell"></i></button>';
    setupAlerts();
    setInterval(refreshBadge, 15000);

    document.getElementById('burger').addEventListener('click', function () {
      document.getElementById('app-sidebar').classList.toggle('open');
    });
    document.getElementById('topSearch').addEventListener('submit', function (e) {
      e.preventDefault();
      var q = document.getElementById('topSearchInput').value.trim();
      location.href = 'pesanan.html' + (q ? '?search=' + encodeURIComponent(q) : '');
    });
    document.getElementById('logoutBtn').addEventListener('click', logout);
  }

  /* ---------- notifikasi pesanan masuk (bunyi, judul tab, notifikasi browser) ---------- */
  var baseTitle = document.title, soundOn = true, audioCtx = null;
  function store(k, v) { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; } }

  function beep() {
    try {
      audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
      [880, 1175].forEach(function (f, i) {
        var o = audioCtx.createOscillator(), g = audioCtx.createGain(), t = audioCtx.currentTime + i * 0.18;
        o.type = 'sine'; o.frequency.value = f;
        g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(0.25, t + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, t + 0.3);
        o.connect(g); g.connect(audioCtx.destination); o.start(t); o.stop(t + 0.32);
      });
    } catch (e) {}
  }

  function setupAlerts() {
    soundOn = store('acicu_alert') !== 'off';
    var btn = document.getElementById('bellBtn');
    function paint() { btn.setAttribute('aria-pressed', soundOn ? 'true' : 'false'); btn.classList.toggle('off', !soundOn); btn.querySelector('i').className = 'fa-solid ' + (soundOn ? 'fa-bell' : 'fa-bell-slash'); }
    paint();
    btn.addEventListener('click', function () {
      soundOn = !soundOn; store('acicu_alert', soundOn ? 'on' : 'off'); paint();
      if (soundOn) { beep(); if (window.Notification && Notification.permission === 'default') Notification.requestPermission(); }
    });
    // browser memblokir bunyi sebelum ada interaksi; aktifkan saat klik pertama
    document.addEventListener('click', function once() { try { audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)(); } catch (e) {} document.removeEventListener('click', once); });
    titleCount(user.pending_count);
  }

  function titleCount(n) { document.title = (n ? '(' + n + ') ' : '') + baseTitle; }

  function alertNew(n) {
    if (soundOn) beep();
    Util.toast(n === 1 ? 'Ada 1 pesanan baru menunggu konfirmasi.' : 'Ada ' + n + ' pesanan baru menunggu konfirmasi.', 'success');
    if (soundOn && window.Notification && Notification.permission === 'granted' && document.hidden) {
      try { new Notification('ACICU: pesanan baru', { body: n + ' pesanan menunggu konfirmasi pembayaran.' }); } catch (e) {}
    }
  }

  /** Perbarui badge "Data Pesanan" tanpa memuat ulang halaman. */
  async function refreshBadge() {
    if (document.hidden) return;
    try {
      var u = (await Api.get('admin/me', { auth: true })).data;
      var prev = user.pending_count || 0;
      user.pending_count = u.pending_count;
      setPending(u.pending_count);
      if (u.pending_count > prev) {
        alertNew(u.pending_count - prev);
        document.dispatchEvent(new CustomEvent('acicu:new-orders', { detail: { count: u.pending_count - prev } }));
      }
    } catch (e) { /* 401 ditangani Api */ }
  }

  async function logout() {
    try { await Api.post('admin/logout', { auth: true }); } catch (e) {}
    Api.setToken(null);
    redirectToLogin();
  }

  function setPending(n) {
    var b = document.getElementById('pendingBadge');
    if (!b) return;
    b.textContent = n;
    b.classList.toggle('hidden', !n);
    titleCount(n);
  }

  /** Dipanggil tiap halaman admin. Mengembalikan data user atau null (sudah dialihkan ke login). */
  async function init(active, opts) {
    opts = opts || {};
    if (!Api.getToken()) { redirectToLogin(); return null; }
    try {
      user = (await Api.get('admin/me', { auth: true })).data;
    } catch (e) {
      if (e.status !== 401) Util.toast(e.message, 'error');
      return null;
    }
    if (opts.ownerOnly && !user.can_view_reports) {
      location.replace('index.html');
      return null;
    }
    renderShell(active);
    return user;
  }

  window.AdminShell = { init: init, redirectToLogin: redirectToLogin, setPending: setPending, user: function () { return user; } };
})();
