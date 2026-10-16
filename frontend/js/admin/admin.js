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
      '<input type="search" id="topSearchInput" placeholder="Cari nomor PO atau nama pelanggan…" autocomplete="off"></form>' +
      '<span class="date"><span class="live-dot" title="Terhubung ke jam server"></span><span id="liveClock"></span></span>';
    tickClock();
    setInterval(tickClock, 1000);
    setInterval(refreshBadge, 30000);

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

  function tickClock() {
    var el = document.getElementById('liveClock');
    if (el) el.innerHTML = '<span class="clk-date">' + Util.dateFull() + ' · </span>' + Util.clockWib();
  }

  /** Perbarui badge "Data Pesanan" tanpa memuat ulang halaman. */
  async function refreshBadge() {
    if (document.hidden) return;
    try {
      var u = (await Api.get('admin/me', { auth: true })).data;
      user.pending_count = u.pending_count;
      setPending(u.pending_count);
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
