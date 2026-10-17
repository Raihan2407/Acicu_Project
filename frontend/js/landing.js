/* Halaman landing: menu dari API (/api/products), kuota realtime, tambah ke keranjang, navigasi aktif saat scroll. */
(function () {
  Layout.init({ landing: true });

  var dishesEl = document.getElementById('dishes');
  var stage = document.getElementById('stage');
  var state = document.getElementById('menuState');
  var products = [];
  var current = 0;
  var orderDate = null;
  var cfgCache = null;

  /* ---------- kontak & info batch dari pengaturan toko ---------- */
  function applyConfig(cfg) {
    cfgCache = cfg;
    var c = cfg.contact;
    document.getElementById('contactWa').href = Util.waLink(c.whatsapp);
    document.getElementById('footWaLink').href = Util.waLink(c.whatsapp);
    document.getElementById('footWa').textContent = c.whatsapp_display;
    document.getElementById('footIg').textContent = c.instagram;
    document.getElementById('footIgLink').href = 'https://www.instagram.com/' + encodeURIComponent(c.instagram);
    document.getElementById('footTt').textContent = c.tiktok;
    document.getElementById('footTtLink').href = 'https://www.tiktok.com/@' + encodeURIComponent(c.tiktok);
    document.getElementById('contactHours').textContent = 'Admin siap merespon setiap hari pukul ' + c.admin_hours + '.';
    var tag = document.getElementById('batchTag');
    if (cfg.store_open === false) { tag.textContent = 'Pre-order sedang ditutup'; tag.hidden = false; }
    else tag.hidden = true;
    document.getElementById('addCart').dataset.closed = cfg.store_open === false ? '1' : '';
    updateInfo();
  }
  Layout.getConfig().then(applyConfig);
  window.addEventListener('config:change', function (e) { applyConfig(e.detail); loadMenu(true); });
  document.getElementById('year').textContent = Util.now().getFullYear();

  /* ---------- slider: kiri = sebelumnya, tengah = aktif, kanan = berikutnya ---------- */
  function posOf(i) {
    var n = products.length;
    var d = (i - current + n) % n;
    if (d === 0) return 'pos-0';
    if (d === 1) return 'pos-r';
    if (d === n - 1 && n > 2) return 'pos-l';
    return 'pos-h';
  }

  function renderDishes() {
    var existing = dishesEl.children;
    if (existing.length !== products.length || products.some(function (p, i) { return existing[i].dataset.id !== String(p.id); })) {
      dishesEl.innerHTML = products.map(function (p, i) {
        return '<button type="button" class="dish" data-i="' + i + '" data-id="' + p.id + '" aria-label="' + Util.esc(p.short_name) + '">' +
          '<img src="' + Util.esc(p.image_url) + '" alt="' + Util.esc(p.short_name) + '" draggable="false"></button>';
      }).join('');
    }
    Array.prototype.forEach.call(dishesEl.children, function (el, i) {
      el.className = 'dish ' + posOf(i) + (products[i].available ? '' : ' soldout');
      el.tabIndex = el.className.indexOf('pos-0') > -1 ? -1 : 0;
    });
    var many = products.length > 1;
    document.getElementById('prevBtn').hidden = !many;
    document.getElementById('nextBtn').hidden = !many;
    document.getElementById('dots').innerHTML = many ? products.map(function (p, i) {
      return '<button type="button" data-dot="' + i + '" class="' + (i === current ? 'on' : '') + '" aria-label="' + Util.esc(p.short_name) + '"></button>';
    }).join('') : '';
  }

  function updateInfo() {
    var p = products[current];
    if (!p) return;
    document.getElementById('dishName').textContent = p.short_name;
    document.getElementById('dishDesc').textContent = p.description || p.short_description || '';
    document.getElementById('dishInfo').hidden = false;
    document.getElementById('menuPrice').textContent = Util.rupiah(p.price);
    var closed = document.getElementById('addCart').dataset.closed === '1';
    var btn = document.getElementById('addCart');
    btn.disabled = !p.available || closed;
    btn.innerHTML = closed ? 'Pre-order ditutup' : p.available ? '<i class="fa-solid fa-cart-plus"></i> Tambah' : 'Habis';
    var when = orderDate === Util.todayIso() ? 'hari ini' : 'besok';
    var stock = document.getElementById('menuStock');
    stock.textContent = p.available ? 'Sisa kuota ' + when + ': ' + p.remaining + ' porsi' : 'Kuota ' + when + ' sudah habis';
    stock.classList.toggle('low', p.available && p.remaining <= 5);
    document.getElementById('buy').hidden = false;
  }

  function go(delta) {
    if (products.length < 2) return;
    current = (current + delta + products.length) % products.length;
    renderDishes();
    updateInfo();
  }
  function goTo(i) { if (i !== current) { current = i; renderDishes(); updateInfo(); } }

  document.getElementById('prevBtn').addEventListener('click', function () { go(-1); });
  document.getElementById('nextBtn').addEventListener('click', function () { go(1); });
  dishesEl.addEventListener('click', function (e) {
    var d = e.target.closest('.dish'); if (!d) return;
    var i = Number(d.dataset.i);
    var n = products.length;
    if (i !== current) goTo(i);
  });
  document.getElementById('dots').addEventListener('click', function (e) {
    var b = e.target.closest('[data-dot]'); if (b) goTo(Number(b.dataset.dot));
  });
  document.addEventListener('keydown', function (e) {
    if (e.target.closest('input,textarea,select')) return;
    if (e.key === 'ArrowLeft') go(-1);
    if (e.key === 'ArrowRight') go(1);
  });

  // geser (swipe) di HP
  (function () {
    var startX = 0;
    stage.addEventListener('touchstart', function (e) { startX = e.touches[0].clientX; }, { passive: true });
    stage.addEventListener('touchend', function (e) {
      var diff = startX - e.changedTouches[0].clientX;
      if (diff > 50) go(1);
      if (diff < -50) go(-1);
    });
  })();

  document.getElementById('addCart').addEventListener('click', function () {
    var p = products[current];
    if (!p || !p.available || this.dataset.closed === '1') return;
    Cart.add(p, 1);
    Util.toast(p.short_name + ' ditambahkan ke keranjang', 'success');
  });

  /* ---------- muat menu & kuota (disegarkan otomatis) ---------- */
  function showState(html) {
    state.hidden = false;
    state.innerHTML = html;
    document.getElementById('buy').hidden = true;
    document.getElementById('dishInfo').hidden = true;
    dishesEl.innerHTML = '';
    var retry = state.querySelector('button');
    if (retry) retry.addEventListener('click', function () { loadMenu(); });
  }

  /** silent = true: segarkan kuota diam-diam tanpa mengubah slide yang sedang dilihat */
  function loadMenu(silent) {
    if (silent !== true) showState('Memuat menu...');
    Layout.getConfig().then(function (cfg) {
      orderDate = Layout.nextOrderDate(cfg);
      document.getElementById('menuSub').textContent = 'Pesanan ' + (orderDate === Util.todayIso() ? 'hari ini' : 'besok') + ' · ' + Util.dateLong(orderDate);
      return Api.get('products', { query: { date: orderDate } });
    }).then(function (res) {
      var fresh = (res.data || []).filter(function (p) { return p.image_url; });
      if (!fresh.length) { products = []; showState('Menu belum tersedia. Silakan kembali lagi nanti.'); return; }
      var keepId = products[current] ? products[current].id : null;
      products = fresh;
      var idx = fresh.findIndex(function (p) { return p.id === keepId; });
      current = idx > -1 ? idx : 0;
      state.hidden = true;
      renderDishes();
      updateInfo();
    }).catch(function (err) {
      if (silent === true) return;
      showState(Util.esc(err.message) + '<br><button type="button">Coba lagi</button>');
    });
  }

  /* ---------- navigasi aktif saat scroll ---------- */
  var links = Array.prototype.slice.call(document.querySelectorAll('.nav-link[data-target]'));
  var sections = links.map(function (l) { return document.getElementById(l.dataset.target); });

  function setActive(id) { links.forEach(function (l) { l.classList.toggle('active', l.dataset.target === id); }); }

  links.forEach(function (link) {
    link.addEventListener('click', function (e) {
      e.preventDefault();
      var section = document.getElementById(link.dataset.target);
      if (section) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
      setActive(link.dataset.target);
    });
  });

  function onScroll() {
    var probe = window.innerHeight * 0.4;
    var active = links.length ? links[0].dataset.target : null;
    sections.forEach(function (s, i) { if (s && s.getBoundingClientRect().top <= probe) active = links[i].dataset.target; });
    if (active) setActive(active);
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onScroll);

  loadMenu();
  setInterval(function () { if (!document.hidden) loadMenu(true); }, 15000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) loadMenu(true); });
  onScroll();
})();
