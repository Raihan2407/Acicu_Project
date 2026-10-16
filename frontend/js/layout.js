/*
 * Kerangka halaman pelanggan: navbar, laci keranjang, footer.
 * Dipakai oleh index.html (landing) dan checkout.html.
 */
(function () {
  var DEFAULT_CONFIG = {
    shipping_fee: 5000,
    delivery_slots: ['11:30', '15:30'],
    max_advance_days: 30,
    payment: { bank_name: 'BCA', bank_account: '-', bank_holder: '-', qris_url: null },
    contact: { whatsapp: '6282286512274', whatsapp_display: '082286512274', instagram: 'acicu.licious', tiktok: 'acicu.licious', admin_hours: '08:00 - 19:00 WIB' },
  };

  var configPromise = null;
  var lastConfigJson = '';

  function getConfig() {
    if (!configPromise) {
      configPromise = Api.get('config').then(function (res) {
        lastConfigJson = JSON.stringify(res.data);
        return res.data;
      }).catch(function () { return DEFAULT_CONFIG; });
    }
    return configPromise;
  }

  /** Ambil ulang pengaturan dari server (ongkir, slot jam, rekening, buka/tutup). Memicu event bila ada yang berubah. */
  function refreshConfig() {
    return Api.get('config').then(function (res) {
      var json = JSON.stringify(res.data);
      configPromise = Promise.resolve(res.data);
      if (json !== lastConfigJson) {
        lastConfigJson = json;
        window.dispatchEvent(new CustomEvent('config:change', { detail: res.data }));
      }
      return res.data;
    }).catch(function () { return null; });
  }

  /** Slot jam yang masih bisa dipesan pada tanggal tertentu (memakai jam server). */
  function openSlots(cfg, dateIso) {
    var cutoff = cfg.order_cutoff_minutes != null ? cfg.order_cutoff_minutes : 90;
    var nowMin = Util.minutesOfDay();
    var today = Util.todayIso();
    return (cfg.delivery_slots || []).filter(function (slot) {
      if (!dateIso || dateIso > today) return true;
      if (dateIso < today) return false;
      var p = slot.split(':');
      return (Number(p[0]) * 60 + Number(p[1]) - cutoff) > nowMin;
    });
  }

  /** Tanggal terdekat yang masih punya slot kosong: hari ini bila ada, kalau tidak besok. */
  function nextOrderDate(cfg) {
    var today = Util.todayIso();
    return openSlots(cfg, today).length ? today : Util.addDays(today, 1);
  }

  function navLinks(landing) {
    var items = [['home', 'Home'], ['menu', 'Menu'], ['sep'], ['about', 'About Us'], ['contact', 'Contact']];
    return items.map(function (it) {
      if (it[0] === 'sep') return '<div class="separator"></div>';
      return landing
        ? '<a data-target="' + it[0] + '" class="nav-link' + (it[0] === 'home' ? ' active' : '') + '" href="#' + it[0] + '">' + it[1] + '</a>'
        : '<a class="nav-link" href="index.html#' + it[0] + '">' + it[1] + '</a>';
    }).join('');
  }

  var CART_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.5 3.5h2.2l2.1 11.2a1.5 1.5 0 0 0 1.5 1.2h8.6a1.5 1.5 0 0 0 1.5-1.1l1.6-6.3H6"/><circle cx="9.5" cy="20" r="1.4"/><circle cx="17" cy="20" r="1.4"/></svg>';

  function renderHeader(landing) {
    var host = document.getElementById('siteHeader');
    if (!host) return;
    host.innerHTML =
      '<header class="navbar" id="navbar">' +
        '<a class="nav-left" href="index.html">' +
          '<img src="assets/img/logo.jpg" alt="Logo ACICU" class="logo" />' +
          '<div class="logo-text"><h1>ACICU</h1><p>Aneka Aci Bungcu</p></div>' +
        '</a>' +
        '<nav class="nav-menu" id="navMenu">' + navLinks(landing) + '</nav>' +
        '<div class="nav-right">' +
          '<button class="cart-btn" id="cartBtn" type="button" aria-label="Buka keranjang" aria-expanded="false">' +
            CART_SVG + '<span class="cart-badge" id="cartBadge" hidden>0</span>' +
          '</button>' +
          '<button class="hamburger" id="hamburger" type="button" aria-label="Menu"><span></span><span></span><span></span></button>' +
        '</div>' +
      '</header>';
  }

  function renderDrawer() {
    var el = document.createElement('aside');
    el.className = 'cart-drawer';
    el.id = 'cartDrawer';
    el.setAttribute('aria-label', 'Keranjang');
    el.innerHTML =
      '<div class="cart-head"><strong>Keranjang Pesanan</strong>' +
        '<button type="button" class="cart-close" id="cartClose" aria-label="Tutup keranjang"><i class="fa-solid fa-xmark"></i></button></div>' +
      '<div class="cart-list" id="cartList"></div>' +
      '<div class="cart-foot">' +
        '<div class="cart-sub"><span>Subtotal Pesanan:</span><strong id="cartSubtotal">Rp 0</strong></div>' +
        '<p class="cart-note">*Pengiriman fresh drop dijadwalkan sesuai kuota batch pre-order hari ini.</p>' +
        '<a class="btn-pink cart-checkout" id="cartCheckout" href="checkout.html">Lanjut Checkout <i class="fa-solid fa-arrow-right"></i></a>' +
        '<button type="button" class="btn-wa-dark" id="cartWa"><i class="fa-regular fa-message"></i> Kirim Format Order ke WhatsApp</button>' +
      '</div>';
    document.body.appendChild(el);
  }

  function rp(n) { return Util.rupiah(n); }

  function drawCart() {
    var items = Cart.items();
    var count = Cart.count();

    var badge = document.getElementById('cartBadge');
    if (badge) { badge.textContent = count > 99 ? '99+' : count; badge.hidden = count === 0; }

    var list = document.getElementById('cartList');
    if (!list) return;

    if (!items.length) {
      list.innerHTML = '<div class="cart-empty"><i class="fa-solid fa-bag-shopping"></i><p>Keranjang masih kosong.</p><span>Pilih menu favoritmu di bagian Menu.</span></div>';
    } else {
      list.innerHTML = items.map(function (i) {
        return '<div class="cart-item" data-id="' + i.product_id + '">' +
          '<img src="' + Util.esc(i.image_url || 'assets/img/logo.jpg') + '" alt="">' +
          '<div class="cart-item-info">' +
            '<div class="cart-item-name">' + Util.esc(i.short_name || i.name) + '</div>' +
            '<div class="cart-item-price">' + rp(i.price) + ' / porsi</div>' +
            '<div class="cart-item-total">' + rp(i.price * i.qty) + '</div>' +
          '</div>' +
          '<button type="button" class="cart-del" data-act="del" aria-label="Hapus"><i class="fa-regular fa-trash-can"></i></button>' +
          '<div class="qty"><button type="button" data-act="dec" aria-label="Kurangi">-</button><span>' + i.qty + '</span><button type="button" data-act="inc" aria-label="Tambah">+</button></div>' +
        '</div>';
      }).join('');
    }

    document.getElementById('cartSubtotal').textContent = rp(Cart.subtotal());
    var checkout = document.getElementById('cartCheckout');
    var wa = document.getElementById('cartWa');
    checkout.classList.toggle('disabled', !items.length);
    checkout.setAttribute('aria-disabled', String(!items.length));
    wa.disabled = !items.length;
  }

  function setOpen(open) {
    var drawer = document.getElementById('cartDrawer');
    var btn = document.getElementById('cartBtn');
    drawer.classList.toggle('open', open);
    btn.setAttribute('aria-expanded', String(open));
  }

  function bindDrawer() {
    var drawer = document.getElementById('cartDrawer');

    document.getElementById('cartBtn').addEventListener('click', function (e) {
      e.stopPropagation();
      setOpen(!drawer.classList.contains('open'));
    });
    document.getElementById('cartClose').addEventListener('click', function () { setOpen(false); });
    drawer.addEventListener('click', function (e) { e.stopPropagation(); });
    document.addEventListener('click', function (e) {
      if (!e.target.closest('#navMenu') && !e.target.closest('#hamburger')) { var m = document.getElementById('navMenu'); if (m) m.classList.remove('active'); }
      setOpen(false);
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });

    document.getElementById('cartList').addEventListener('click', function (e) {
      var btn = e.target.closest('button[data-act]');
      if (!btn) return;
      var row = btn.closest('.cart-item');
      var id = Number(row.dataset.id);
      var item = Cart.items().find(function (i) { return i.product_id === id; });
      if (!item) return;
      if (btn.dataset.act === 'del') Cart.remove(id);
      if (btn.dataset.act === 'inc') Cart.setQty(id, item.qty + 1);
      if (btn.dataset.act === 'dec') item.qty > 1 ? Cart.setQty(id, item.qty - 1) : Cart.remove(id);
    });

    document.getElementById('cartCheckout').addEventListener('click', function (e) {
      if (!Cart.items().length || document.documentElement.classList.contains('store-closed')) e.preventDefault();
    });

    document.getElementById('cartWa').addEventListener('click', function () {
      getConfig().then(function (cfg) {
        var lines = Cart.items().map(function (i) { return '- ' + i.qty + 'x ' + (i.name || i.short_name) + ' (' + rp(i.price * i.qty) + ')'; });
        var text = 'Halo ACICU, saya mau pre-order:\n' + lines.join('\n') + '\nSubtotal: ' + rp(Cart.subtotal()) + '\n\nMohon info slot pengiriman. Terima kasih!';
        window.open(Util.waLink(cfg.contact.whatsapp, text), '_blank', 'noopener');
      });
    });

    window.addEventListener('cart:change', drawCart);
    window.addEventListener('storage', function (e) { if (e.key === 'acicu_cart_v1') drawCart(); });
    drawCart();
  }

  function bindScrollShadow() {
    var nav = document.getElementById('navbar');
    var onScroll = function () { nav.classList.toggle('scrolled', window.scrollY > 8); };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  /** Banner + penguncian tombol saat tim menutup pre-order dari panel admin. */
  function applyStoreState(cfg) {
    var banner = document.getElementById('closedBanner');
    var open = cfg.store_open !== false;
    document.documentElement.classList.toggle('store-closed', !open);
    if (!open) {
      if (!banner) {
        banner = document.createElement('div');
        banner.id = 'closedBanner';
        banner.className = 'closed-banner';
        banner.setAttribute('role', 'status');
        var header = document.getElementById('siteHeader');
        header.parentNode.insertBefore(banner, header.nextSibling);
      }
      banner.innerHTML = '<i class="fa-solid fa-store-slash"></i>' + Util.esc(cfg.closed_message || 'Pre-order sedang ditutup.');
    } else if (banner) {
      banner.remove();
    }
  }

  function bindHamburger() {
    var burger = document.getElementById('hamburger');
    var menu = document.getElementById('navMenu');
    burger.addEventListener('click', function (e) {
      e.stopPropagation();
      menu.classList.toggle('active');
    });
    menu.addEventListener('click', function () { menu.classList.remove('active'); });
  }

  function renderFooter() {
    var host = document.getElementById('siteFooter');
    if (!host) return;
    getConfig().then(function (cfg) {
      var c = cfg.contact;
      host.innerHTML =
        '<footer class="simple-footer">' +
          '<div class="sf-left"><div class="sf-brand"><img src="assets/img/logo.jpg" alt=""><strong>ACICU</strong></div>' +
            '<p>Aneka Aci Bungcu khas jajanan kenyal, gurih, dan lezat.<br>Sistem drop pre-order segar langsung ke meja Anda.</p></div>' +
          '<div class="sf-center"><span class="sf-title">PESAN &amp; KONTAK KAMI</span>' +
            '<a class="sf-wa" href="' + Util.waLink(c.whatsapp) + '" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> WhatsApp: ' + Util.esc(c.whatsapp_display) + '</a>' +
            '<div class="sf-social"><span><i class="fa-brands fa-instagram"></i> @' + Util.esc(c.instagram) + '</span><span><i class="fa-brands fa-tiktok"></i> TikTok @' + Util.esc(c.tiktok) + '</span></div></div>' +
          '<div class="sf-right"><span class="sf-pill"><i class="fa-solid fa-circle-check"></i> Pre-Order Freshly Made</span>' +
            '<p>&copy; ' + new Date().getFullYear() + ' Aneka Aci Bungcu (ACICU). All rights reserved.</p></div>' +
        '</footer>';
    });
  }

  window.Layout = {
    getConfig: getConfig,
    refreshConfig: refreshConfig,
    openSlots: openSlots,
    nextOrderDate: nextOrderDate,
    openCart: function () { setOpen(true); },
    init: function (opts) {
      opts = opts || {};
      renderHeader(!!opts.landing);
      renderDrawer();
      bindDrawer();
      bindHamburger();
      bindScrollShadow();
      if (opts.footer) renderFooter();

      // pengaturan toko selalu mengikuti server (diubah tim lewat panel admin)
      getConfig().then(applyStoreState);
      window.addEventListener('config:change', function (e) { applyStoreState(e.detail); });
      setInterval(function () { if (!document.hidden) refreshConfig(); }, 30000);
      document.addEventListener('visibilitychange', function () { if (!document.hidden) refreshConfig(); });
    },
  };
})();
