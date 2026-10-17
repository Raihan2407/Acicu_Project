/* Halaman pembayaran: tampilkan nominal, QRIS/rekening, hitung mundur, lalu "Saya Sudah Bayar". */
(function () {
  Layout.init({ footer: true });

  var token = new URLSearchParams(location.search).get('t');
  var stateCard = document.getElementById('stateCard');
  var payView = document.getElementById('payView');
  var order = null;
  var timer = null;
  var proofFile = null;
  var requireProof = false;

  function showState(icon, title, html, bad) {
    payView.hidden = true;
    stateCard.hidden = false;
    stateCard.className = 'card state-card' + (bad ? ' bad' : '');
    stateCard.innerHTML = '<i class="' + icon + '"></i><h2>' + title + '</h2><p>' + html + '</p>' +
      (bad ? '<a class="btn-pink" href="index.html#menu">Buat Pesanan Baru</a>' : '');
  }

  function render() {
    stateCard.hidden = true;
    payView.hidden = false;

    document.getElementById('poNo').textContent = order.po_number;
    document.getElementById('payAmount').textContent = Util.rupiah(order.total);
    document.getElementById('sumSubtotal').textContent = Util.rupiah(order.subtotal);
    document.getElementById('sumShipping').textContent = Util.rupiah(order.shipping_fee);
    document.getElementById('sumTotal').textContent = Util.rupiah(order.total);

    var portions = order.items.reduce(function (s, i) { return s + i.qty; }, 0);
    document.getElementById('portionChip').textContent = portions + ' Porsi';
    document.getElementById('sumItems').innerHTML = order.items.map(function (i) {
      return '<div class="sum-item">' +
        '<div class="sum-img"><img src="' + Util.esc(i.image_url || 'assets/img/logo.jpg') + '" alt=""><em>' + i.qty + 'x</em></div>' +
        '<div><div class="sum-name">' + Util.esc(i.name) + '</div>' + (i.note ? '<div class="sum-note">' + Util.esc(i.note) + '</div>' : '') + '</div>' +
        '<div class="sum-price">' + Util.rupiah(i.subtotal) + '</div></div>';
    }).join('');

    var today = Util.todayIso();
    var dayText = order.delivery_date === today ? 'Hari ini' : Util.dateLong(order.delivery_date);
    document.getElementById('deliverInfo').textContent = 'Slot pengantaran: ' + dayText + ', ' + order.delivery_time + ' WIB';
  }

  function tick() {
    var left = Math.floor((new Date(order.payment_deadline).getTime() - Util.now().getTime()) / 1000);
    var box = document.getElementById('countdownBox');
    if (left <= 0) {
      clearInterval(timer);
      load(); // server membatalkan pesanan yang kedaluwarsa
      return;
    }
    document.getElementById('countdown').textContent = Util.pad(Math.floor(left / 60)) + ':' + Util.pad(left % 60);
    box.classList.toggle('low', left <= 300);
  }

  function load() {
    return Api.get('orders/' + encodeURIComponent(token)).then(function (res) {
      order = res.data;

      if (order.status === 'dibatalkan') {
        showState('fa-regular fa-clock', 'Waktu pembayaran habis',
          'Pesanan <b>' + Util.esc(order.po_number) + '</b> dibatalkan karena belum dibayar dan kuotanya sudah dilepas. Silakan buat pesanan baru.', true);
        return;
      }
      if (order.status !== 'menunggu_pembayaran') {
        // sudah ditandai dibayar / diproses: lanjut ke halaman status
        location.replace('pesanan.html?t=' + encodeURIComponent(token));
        return;
      }
      render();
      clearInterval(timer);
      tick();
      timer = setInterval(tick, 1000);
    }).catch(function (err) {
      if (err.status === 404) showState('fa-regular fa-face-frown', 'Pesanan tidak ditemukan', 'Periksa kembali tautan pembayaran Anda.', true);
      else showState('fa-solid fa-plug-circle-exclamation', 'Gagal memuat', Util.esc(err.message), false);
    });
  }

  if (!token) {
    showState('fa-regular fa-face-frown', 'Pesanan tidak ditemukan', 'Tautan pembayaran tidak lengkap.', true);
    return;
  }

  Layout.getConfig().then(function (cfg) {
    var p = cfg.payment;
    requireProof = !!cfg.require_proof;
    document.getElementById('proofOpt').textContent = requireProof ? 'Wajib diunggah.' : 'Opsional.';
    document.getElementById('bankName').textContent = p.bank_name;
    document.getElementById('bankName2').textContent = p.bank_name;
    document.getElementById('bankAccount').textContent = p.bank_account;
    document.getElementById('bankHolder').textContent = p.bank_holder;
    if (p.qris_url) document.getElementById('qrisImg').src = p.qris_url;
    document.getElementById('adminHours').textContent = 'Admin ACICU siap merespon setiap hari pukul ' + cfg.contact.admin_hours + '.';

    document.getElementById('waBtn').addEventListener('click', function (e) {
      e.preventDefault();
      var text = 'Halo Admin ACICU, saya ingin menanyakan pembayaran pesanan ' + (order ? order.po_number : '') + '.';
      window.open(Util.waLink(cfg.contact.whatsapp, text), '_blank', 'noopener');
    });
  });

  /* ---------- bukti pembayaran ---------- */
  var proofInput = document.getElementById('proofInput');
  var proofDrop = document.getElementById('proofDrop');
  function showProofError(msg) {
    var el = document.getElementById('proofErr');
    el.textContent = msg || ''; el.hidden = !msg;
    if (msg) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
  function setProof(file) {
    showProofError('');
    if (!file) { proofFile = null; proofInput.value = ''; document.getElementById('proofPick').hidden = true; proofDrop.hidden = false; return; }
    if (!/^image\/(png|jpe?g|webp)$/.test(file.type)) { showProofError('Format harus JPG, PNG, atau WEBP.'); return; }
    if (file.size > 2 * 1024 * 1024) { showProofError('Ukuran bukti maksimal 2 MB.'); return; }
    proofFile = file;
    var url = URL.createObjectURL(file);
    document.getElementById('proofThumb').src = url;
    document.getElementById('proofName').textContent = file.name;
    document.getElementById('proofSize').textContent = (file.size / 1024 < 1024 ? Math.round(file.size / 1024) + ' KB' : (file.size / 1048576).toFixed(1) + ' MB');
    document.getElementById('proofPick').hidden = false;
    proofDrop.hidden = true;
  }
  proofDrop.addEventListener('click', function () { proofInput.click(); });
  proofInput.addEventListener('change', function () { setProof(proofInput.files[0]); });
  document.getElementById('proofRemove').addEventListener('click', function () { setProof(null); });
  ['dragenter', 'dragover'].forEach(function (ev) { proofDrop.addEventListener(ev, function (e) { e.preventDefault(); proofDrop.classList.add('over'); }); });
  ['dragleave', 'drop'].forEach(function (ev) { proofDrop.addEventListener(ev, function (e) { e.preventDefault(); proofDrop.classList.remove('over'); }); });
  proofDrop.addEventListener('drop', function (e) { if (e.dataTransfer.files[0]) setProof(e.dataTransfer.files[0]); });

  document.getElementById('copyBtn').addEventListener('click', function () {
    var btn = this;
    var number = document.getElementById('bankAccount').textContent.replace(/\D+/g, '');
    var done = function () {
      btn.classList.add('done');
      btn.innerHTML = '<i class="fa-solid fa-check"></i> Tersalin';
      setTimeout(function () { btn.classList.remove('done'); btn.innerHTML = '<i class="fa-regular fa-copy"></i> Salin'; }, 1800);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(number).then(done, function () {});
    else { var t = document.createElement('textarea'); t.value = number; document.body.appendChild(t); t.select(); try { document.execCommand('copy'); done(); } catch (e) {} t.remove(); }
  });

  document.getElementById('paidBtn').addEventListener('click', function () {
    var btn = this;
    if (requireProof && !proofFile) { showProofError('Anda belum mengunggah bukti pembayaran.'); Util.toast('Anda belum mengunggah bukti pembayaran.', 'error'); return; }
    if (!window.confirm('Pastikan pembayaran sebesar ' + Util.rupiah(order.total) + ' sudah berhasil. Lanjutkan?')) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Memproses...';
    var opts = {};
    if (proofFile) { var fd = new FormData(); fd.append('proof', proofFile); opts.form = fd; }
    Api.post('orders/' + encodeURIComponent(token) + '/paid', opts)
      .then(function () { location.href = 'pesanan.html?t=' + encodeURIComponent(token); })
      .catch(function (err) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-check"></i> Saya Sudah Bayar';
        if (err.errors && err.errors.proof) showProofError(err.errors.proof[0]); else Util.toast(err.message, 'error');
        load();
      });
  });

  load();
  // cek otomatis: kalau admin sudah mengonfirmasi / pesanan kedaluwarsa, halaman langsung menyesuaikan
  setInterval(function () { if (!document.hidden && !document.getElementById('paidBtn').disabled) load().catch(function () {}); }, 15000);
})();
