/* Halaman status pesanan: tracking 3 tahap, rincian, ringkasan biaya. Diperbarui otomatis tiap 20 detik. */
(function () {
  var token = new URLSearchParams(location.search).get('t');
  var stateCard = document.getElementById('stateCard');
  var view = document.getElementById('view');
  var config = null;
  var poll = null;

  function showState(icon, title, html, bad) {
    view.hidden = true;
    stateCard.hidden = false;
    stateCard.className = 'card state-card' + (bad ? ' bad' : '');
    stateCard.innerHTML = '<i class="' + icon + '"></i><h2>' + title + '</h2><p>' + html + '</p><a class="btn-pink" href="index.html">Kembali ke Menu Utama</a>';
  }

  function render(o) {
    stateCard.hidden = true;
    view.hidden = false;

    // ----- judul sesuai status -----
    var icon = document.getElementById('heroIcon');
    var title = document.getElementById('heroTitle');
    var text = document.getElementById('heroText');
    icon.className = 'st-check';
    icon.innerHTML = '<i class="fa-solid fa-check"></i>';

    if (o.status === 'dibatalkan') {
      icon.classList.add('bad'); icon.innerHTML = '<i class="fa-solid fa-xmark"></i>';
      title.textContent = 'Pesanan Dibatalkan';
      text.textContent = o.rejected_reason ? o.rejected_reason + '. Silakan buat pesanan baru atau hubungi admin.' : 'Pesanan ini dibatalkan. Silakan buat pesanan baru atau hubungi admin.';
    } else if (o.status === 'menunggu_pembayaran') {
      icon.classList.add('warn'); icon.innerHTML = '<i class="fa-regular fa-clock"></i>';
      title.textContent = 'Pesananmu Belum Dibayar';
      text.innerHTML = 'Selesaikan pembayaran agar pesanan masuk ke dapur. <a href="pembayaran.html?t=' + encodeURIComponent(o.tracking_token) + '" style="color:#b3114b;font-weight:800">Buka halaman pembayaran</a>';
    } else if (o.status === 'selesai') {
      title.textContent = 'Pesananmu Sudah Selesai!';
      text.textContent = 'Terima kasih sudah memesan di ACICU. Selamat menikmati cemilan kenyal gurihnya!';
    } else if (o.status === 'proses_dapur') {
      title.textContent = 'Pembayaran Terkonfirmasi!';
      text.textContent = 'Dapur kami sedang memproses pesananmu dengan bahan segar pilihan. Tunggu pesanan sampai ya!';
    } else {
      title.textContent = 'Yeeay! Pre-Order Kamu Berhasil Dikirim!';
      text.textContent = 'Terima kasih sudah memesan cemilan kenyal gurih ACICU. Admin akan segera mengecek pembayaranmu, lalu dapur memproses pesananmu!';
    }

    document.getElementById('poNo').textContent = o.po_number;

    // ----- tracking -----
    var stage = { menunggu_pembayaran: 0, menunggu_konfirmasi: 1, proses_dapur: 2, selesai: 3, dibatalkan: 0 }[o.status] || 0;
    var steps = document.querySelectorAll('#steps .step');
    Array.prototype.forEach.call(steps, function (el, i) {
      var n = i + 1;
      el.classList.toggle('done', n < stage || (stage === 3 && n === 3));
      el.classList.toggle('current', n === stage && stage !== 3);
    });
    document.getElementById('steps').style.setProperty('--progress', ((Math.max(stage, 1) - 1) / 2 * 66.6) + '%');
    document.getElementById('t1').textContent = Util.timeWib(o.customer_paid_at || o.ordered_at);
    document.getElementById('t2').textContent = 'Estimasi ' + o.kitchen_eta;
    document.getElementById('t3').textContent = 'Tiba ~' + o.delivery_time;

    // ----- tile info -----
    document.getElementById('orderedAt').textContent = Util.dateLong(o.ordered_at) + ', ' + Util.timeWib(o.ordered_at);
    document.getElementById('receiver').textContent = o.customer_name;
    document.getElementById('receiverWa').textContent = o.whatsapp_masked;
    document.getElementById('slot').textContent = (o.delivery_date === Util.todayIso() ? 'Hari ini' : Util.dateLong(o.delivery_date)) + ', ' + o.delivery_time + ' WIB';

    // ----- rincian -----
    var portions = o.items.reduce(function (s, i) { return s + i.qty; }, 0);
    document.getElementById('portions').textContent = portions + ' Porsi Total';
    document.getElementById('subLabel').textContent = 'Subtotal Cemilan (' + portions + 'x)';
    document.getElementById('items').innerHTML = o.items.map(function (i) {
      return '<div class="it">' +
        '<div class="pic"><img src="' + Util.esc(i.image_url || 'assets/img/logo.jpg') + '" alt=""><em>' + i.qty + 'x</em></div>' +
        '<div><div class="nm">' + Util.esc(i.name) + '</div>' + (i.note ? '<div class="nt">' + Util.esc(i.note) + '</div>' : '') +
        '<div class="pp">' + Util.rupiah(i.unit_price) + ' / porsi</div></div>' +
        '<div class="lt">' + Util.rupiah(i.subtotal) + '</div></div>';
    }).join('');

    // ----- status pembayaran -----
    var pay = {
      belum_bayar: ['Belum dibayar', 'Menunggu pembayaran', ''],
      menunggu: ['Menunggu dikonfirmasi admin', 'Pembayaran sedang dicek', ''],
      lunas: ['Pembayaran diterima', 'Lunas', 'ok'],
      ditolak: ['Pembayaran tidak ditemukan', 'Ditolak', 'bad'],
    }[o.payment_status] || ['-', '-', ''];
    document.getElementById('payText').textContent = pay[0];
    var badge = document.getElementById('payBadge');
    badge.textContent = pay[1];
    badge.className = 'badge ' + pay[2];

    var pr = document.getElementById('proofRow');
    pr.hidden = !o.proof_url;
    if (o.proof_url) document.getElementById('proofLink').href = o.proof_url;

    // ----- biaya -----
    document.getElementById('subtotal').textContent = Util.rupiah(o.subtotal);
    document.getElementById('shipping').textContent = Util.rupiah(o.shipping_fee);
    document.getElementById('discount').textContent = '-' + Util.rupiah(o.discount);
    document.getElementById('total').textContent = Util.rupiah(o.total);

    var waBtn = document.getElementById('waBtn');
    var confirming = o.status === 'menunggu_konfirmasi';
    waBtn.innerHTML = confirming ? '<i class="fa-brands fa-whatsapp"></i> Kabari Admin Sekarang' : '<i class="fa-regular fa-message"></i> Hubungi Admin via WhatsApp';
    waBtn.onclick = function (e) {
      e.preventDefault();
      var number = config ? config.contact.whatsapp : '6282286512274';
      var msg = confirming
        ? 'Halo Admin ACICU, saya sudah membayar pesanan ' + o.po_number + ' atas nama ' + o.customer_name + ' sebesar ' + Util.rupiah(o.total) + '. Mohon dicek ya.'
        : 'Halo Admin ACICU, saya ingin menanyakan pesanan ' + o.po_number + ' atas nama ' + o.customer_name + '.';
      window.open(Util.waLink(number, msg), '_blank', 'noopener');
    };

    // berhenti memperbarui bila sudah final
    if (o.status === 'selesai' || o.status === 'dibatalkan') clearInterval(poll);
  }

  function load() {
    return Api.get('orders/' + encodeURIComponent(token)).then(function (res) { render(res.data); }).catch(function (err) {
      if (err.status === 404) showState('fa-regular fa-face-frown', 'Pesanan tidak ditemukan', 'Periksa kembali tautan status pesanan Anda.', true);
      else if (view.hidden) showState('fa-solid fa-plug-circle-exclamation', 'Gagal memuat', Util.esc(err.message), false);
    });
  }

  if (!token) {
    showState('fa-regular fa-face-frown', 'Pesanan tidak ditemukan', 'Tautan status pesanan tidak lengkap.', true);
    return;
  }

  Api.get('config').then(function (res) {
    config = res.data;
    document.getElementById('adminHours').textContent = 'Admin ACICU siap merespon pertanyaan pesananmu setiap hari pukul ' + config.contact.admin_hours + '.';
  }).catch(function () {});

  load();
  poll = setInterval(load, 20000);
})();
