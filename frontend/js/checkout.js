/* Halaman checkout: isi data penerima -> buat pesanan lewat API -> buka halaman pembayaran. */
(function () {
  Layout.init({ footer: true });

  var form = document.getElementById('checkoutForm');
  var emptyState = document.getElementById('emptyState');
  var alertBox = document.getElementById('formAlert');
  var submitBtn = document.getElementById('submitBtn');
  var config = null;
  var leaving = false; // true setelah pesanan terkirim: jangan render ulang ringkasan

  function rp(n) { return Util.rupiah(n); }

  /* ---------- ringkasan ---------- */
  function renderSummary() {
    if (leaving) return;
    var items = Cart.items();
    if (!items.length) {
      form.hidden = true;
      emptyState.hidden = false;
      return;
    }
    emptyState.hidden = true;
    form.hidden = false;

    document.getElementById('menuCount').textContent = items.length + ' Menu';
    document.getElementById('sumItems').innerHTML = items.map(function (i) {
      return '<div class="sum-item" data-id="' + i.product_id + '">' +
        '<div class="sum-img"><img src="' + Util.esc(i.image_url || 'assets/img/logo.jpg') + '" alt=""><em>' + i.qty + 'x</em></div>' +
        '<div><div class="sum-name">' + Util.esc(i.name) + '</div>' +
          (i.default_note ? '<div class="sum-note">' + Util.esc(i.default_note) + '</div>' : '') + '</div>' +
        '<div class="sum-price">' + rp(i.price * i.qty) + '</div>' +
        '<div class="sum-warn" hidden></div>' +
      '</div>';
    }).join('');

    var subtotal = Cart.subtotal();
    var shipping = config ? config.shipping_fee : 0;
    document.getElementById('sumSubtotal').textContent = rp(subtotal);
    document.getElementById('sumShipping').textContent = rp(shipping);
    document.getElementById('sumTotal').textContent = rp(subtotal + shipping);
  }

  /* ---------- cek kuota untuk tanggal terpilih ---------- */
  function checkAvailability() {
    var date = document.getElementById('delivery_date').value;
    if (!date) return;
    Api.get('products', { query: { date: date } }).then(function (res) {
      var byId = {};
      (res.data || []).forEach(function (p) { byId[p.id] = p; });

      var ok = true;
      Cart.items().forEach(function (i) {
        var row = document.querySelector('.sum-item[data-id="' + i.product_id + '"] .sum-warn');
        if (!row) return;
        var p = byId[i.product_id];
        var msg = '';
        if (!p) msg = 'Menu ini sudah tidak tersedia.';
        else if (p.remaining < i.qty) msg = p.remaining > 0 ? 'Kuota tanggal ini tersisa ' + p.remaining + ' porsi.' : 'Kuota tanggal ini sudah habis.';
        row.textContent = msg;
        row.hidden = !msg;
        if (msg) ok = false;
      });
      submitBtn.dataset.quotaOk = ok ? '1' : '0';
    }).catch(function () { /* diabaikan: server tetap memeriksa saat submit */ });
  }

  /* ---------- validasi form ---------- */
  function setError(name, message) {
    var field = form.querySelector('[name="' + name + '"]');
    if (!field) return false;
    var wrap = field.closest('.field');
    wrap.classList.toggle('invalid', !!message);
    wrap.querySelector('.err').textContent = message || '';
    return !!message;
  }

  function clearErrors() {
    Array.prototype.forEach.call(form.querySelectorAll('.field.invalid'), function (f) { f.classList.remove('invalid'); });
    alertBox.hidden = true;
  }

  function normalizePhone(value) {
    var d = String(value).replace(/\D+/g, '');
    if (d.indexOf('62') === 0) d = '0' + d.slice(2);
    return d;
  }

  function validate() {
    clearErrors();
    var bad = false;
    var v = function (n) { return form.elements[n].value.trim(); };

    if (v('name').length < 2) bad = setError('name', 'Nama lengkap wajib diisi.') || bad;
    if (!/^08[0-9]{8,12}$/.test(normalizePhone(v('whatsapp')))) bad = setError('whatsapp', 'Nomor WhatsApp harus diawali 08 dan berisi 10-14 angka.') || bad;
    if (v('address').length < 10) bad = setError('address', 'Alamat terlalu singkat. Tulis jalan, nomor rumah, dan patokan.') || bad;
    if (!v('delivery_date')) bad = setError('delivery_date', 'Pilih tanggal pre-order.') || bad;
    if (!v('delivery_time')) bad = setError('delivery_time', 'Pilih jam pengiriman.') || bad;
    return !bad;
  }

  function showAlert(message) {
    alertBox.textContent = message;
    alertBox.hidden = false;
    alertBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  /* ---------- kirim ---------- */
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (config && config.store_open === false) { showAlert(config.closed_message || 'Pre-order sedang ditutup.'); return; }
    if (!validate()) {
      var first = form.querySelector('.field.invalid');
      if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }

    var items = Cart.items();
    if (!items.length) return;

    var payload = {
      name: form.elements.name.value.trim(),
      whatsapp: normalizePhone(form.elements.whatsapp.value),
      address: form.elements.address.value.trim(),
      delivery_date: form.elements.delivery_date.value,
      delivery_time: form.elements.delivery_time.value,
      notes: form.elements.notes.value.trim() || null,
      items: items.map(function (i) { return { product_id: i.product_id, qty: i.qty }; }),
    };

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Memproses...';

    Api.post('orders', { json: payload })
      .then(function (res) {
        leaving = true;
        Cart.clear();
        window.location.href = 'pembayaran.html?t=' + encodeURIComponent(res.data.tracking_token);
      })
      .catch(function (err) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = 'Lanjut ke Pembayaran <i class="fa-solid fa-arrow-right"></i>';

        var errors = err.errors || {};
        var generic = [];
        Object.keys(errors).forEach(function (key) {
          var msg = errors[key][0];
          if (form.elements[key]) setError(key, msg);
          else generic.push(msg);
        });
        if (generic.length || !Object.keys(errors).length) showAlert(generic[0] || err.message);
        else {
          var first = form.querySelector('.field.invalid');
          if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
      });
  });

  // hapus pesan error saat pengguna mengetik ulang
  form.addEventListener('input', function (e) {
    var wrap = e.target.closest('.field');
    if (wrap) wrap.classList.remove('invalid');
  });
  document.getElementById('delivery_date').addEventListener('change', function () { refreshSlots(); checkAvailability(); });
  window.addEventListener('config:change', function (e) { config = e.detail; buildSlots(); renderSummary(); checkAvailability(); });

  /* ---------- slot jam & batas tanggal mengikuti jam server ---------- */
  var slotInput = document.getElementById('delivery_time');
  var slotGrid = document.getElementById('slotGrid');

  function buildSlots() {
    if (!config) return;
    var slots = config.delivery_slots || [];
    slotGrid.innerHTML = slots.length ? slots.map(function (t) {
      return '<button type="button" class="slot" role="radio" aria-checked="false" data-t="' + t + '">' + t + '</button>';
    }).join('') : '<span class="slot-empty">Belum ada jam kirim yang tersedia.</span>';
    refreshSlots();
  }

  function selectSlot(t) {
    slotInput.value = t || '';
    Array.prototype.forEach.call(slotGrid.querySelectorAll('.slot'), function (b) {
      var on = b.dataset.t === t;
      b.classList.toggle('on', on);
      b.setAttribute('aria-checked', String(on));
    });
    var field = slotGrid.closest('.field');
    if (t && field) field.classList.remove('invalid');
  }

  function refreshSlots() {
    if (!config) return;
    var date = document.getElementById('delivery_date');
    var today = Util.todayIso();
    var cutoff = config.order_cutoff_minutes != null ? config.order_cutoff_minutes : 90;

    date.min = today;
    date.max = Util.addDays(today, config.max_advance_days || 30);
    if (!date.value || date.value < today) date.value = Layout.nextOrderDate(config);

    var open = Layout.openSlots(config, date.value);
    Array.prototype.forEach.call(slotGrid.querySelectorAll('.slot'), function (b) {
      var ok = open.indexOf(b.dataset.t) > -1;
      b.disabled = !ok;
      b.title = ok ? '' : 'Sudah lewat batas pemesanan';
    });
    if (slotInput.value && open.indexOf(slotInput.value) === -1) selectSlot('');

    var hint = document.getElementById('slotHint');
    if (date.value === today) hint.textContent = '(pesan hari ini maks. ' + cutoff + ' menit sebelum jam kirim)';
    else hint.textContent = '';
    var existing = slotGrid.querySelector('.slot-note');
    if (!open.length && slotGrid.querySelector('.slot')) {
      if (!existing) { var n = document.createElement('div'); n.className = 'slot-note'; slotGrid.appendChild(n); existing = n; }
      existing.textContent = 'Semua jam untuk tanggal ini sudah tutup. Pilih tanggal lain.';
    } else if (existing) existing.remove();
  }

  slotGrid.addEventListener('click', function (e) {
    var b = e.target.closest('.slot');
    if (b && !b.disabled) selectSlot(b.dataset.t);
  });

  /* ---------- inisialisasi ---------- */
  Layout.getConfig().then(function (cfg) {
    config = cfg;

    buildSlots();
    checkAvailability();

    document.getElementById('consultWa').href = Util.waLink(cfg.contact.whatsapp, 'Halo ACICU, saya ingin konsultasi pesanan partai besar / catering.');
    renderSummary();
  });

  setInterval(function () { if (!document.hidden) { refreshSlots(); checkAvailability(); } }, 30000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { refreshSlots(); checkAvailability(); } });

  window.addEventListener('cart:change', renderSummary);
  renderSummary();
})();
