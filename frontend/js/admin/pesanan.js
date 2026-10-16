(function () {
  var state = { page: 1, per_page: 10 };
  var esc = Util.esc;
  var current = [];

  function q() {
    return { search: val('fSearch'), date: val('fDate'), status: val('fStatus') };
  }
  function val(id) { return document.getElementById(id).value.trim(); }

  function payBadge(o) {
    if (o.payment_status === 'lunas') return '<span class="sm">Lunas · ' + esc(o.payment_method || '') + '</span>';
    if (o.payment_status === 'menunggu') return '<span class="sm">Klaim bayar ' + Util.dateTimeShort(o.customer_paid_at) + '</span>';
    return '';
  }

  function actions(o) {
    var b = '<button class="btn sm" data-act="detail" data-id="' + o.id + '">Detail</button>';
    if (o.status === 'menunggu_konfirmasi' || o.status === 'menunggu_pembayaran')
      b += '<button class="btn sm green" data-act="confirm" data-id="' + o.id + '">Konfirmasi Bayar</button>';
    if (o.status === 'proses_dapur')
      b += '<button class="btn sm primary" data-act="done" data-id="' + o.id + '">Tandai Selesai</button>';
    return '<div class="row-actions">' + b + '</div>';
  }

  function renderRows(list) {
    var tb = document.getElementById('rows');
    if (!list.length) { tb.innerHTML = '<tr><td colspan="7" class="empty">Tidak ada pesanan yang cocok.</td></tr>'; return; }
    tb.innerHTML = list.map(function (o) {
      return '<tr class="' + (o.status === 'menunggu_konfirmasi' ? 'pending' : '') + '"><td><b>' + esc(o.po_number) + '</b><span class="sm">' + Util.dateTimeShort(o.ordered_at) + '</span></td>' +
        '<td>' + esc(o.customer_name) + '<a class="wa" style="margin-top:4px" target="_blank" rel="noopener" href="' + esc(o.whatsapp_link) + '"><i class="fa-brands fa-whatsapp"></i> ' + esc(o.whatsapp) + '</a></td>' +
        '<td>' + esc(o.items_summary) + '<span class="sm">' + o.portions + ' porsi</span></td>' +
        '<td class="num">' + Util.rupiah(o.net_total) + '</td>' +
        '<td style="white-space:nowrap">' + Util.dateShort(o.delivery_date) + '<span class="sm">' + esc(o.delivery_time) + '</span></td>' +
        '<td><span class="pill ' + o.status + '">' + esc(o.status_label) + '</span>' + payBadge(o) + '</td>' +
        '<td>' + actions(o) + '</td></tr>';
    }).join('');
  }

  function renderPager(m) {
    var el = document.getElementById('pager');
    if (!m.total) { el.innerHTML = ''; return; }
    var pages = '';
    var from = Math.max(1, m.current_page - 2), to = Math.min(m.last_page, from + 4);
    from = Math.max(1, to - 4);
    for (var p = from; p <= to; p++) pages += '<button data-p="' + p + '" class="' + (p === m.current_page ? 'on' : '') + '">' + p + '</button>';
    el.innerHTML = '<span>Menampilkan ' + m.from + '–' + m.to + ' dari ' + m.total + '</span><div class="pages">' +
      '<button data-p="' + (m.current_page - 1) + '"' + (m.current_page <= 1 ? ' disabled' : '') + '>‹</button>' + pages +
      '<button data-p="' + (m.current_page + 1) + '"' + (m.current_page >= m.last_page ? ' disabled' : '') + '>›</button></div>';
  }

  async function load() {
    try {
      var res = await Api.get('admin/orders', { auth: true, query: Object.assign({ page: state.page, per_page: state.per_page }, q()) });
      current = res.data;
      renderRows(current); renderPager(res.meta);
      document.getElementById('queueInfo').textContent = res.meta.queue_total + ' total antrean · ' + res.meta.pending_count + ' menunggu konfirmasi pembayaran · diperbarui ' + Util.clockWib();
      AdminShell.setPending(res.meta.pending_count);
    } catch (e) { Util.toast(e.message, 'error'); }
  }

  function modal(html) {
    var host = document.getElementById('modalHost');
    host.innerHTML = '<div class="modal" role="dialog" aria-modal="true"><div class="box">' + html + '</div></div>';
    var m = host.firstChild;
    m.addEventListener('click', function (e) { if (e.target === m || e.target.closest('[data-close]')) close(); });
    return m;
  }
  function close() { document.getElementById('modalHost').innerHTML = ''; }

  async function showDetail(id) {
    var o;
    try { o = (await Api.get('admin/orders/' + id, { auth: true })).data; } catch (e) { Util.toast(e.message, 'error'); return; }
    var items = o.items.map(function (i) { return '<li>' + i.qty + '× ' + esc(i.product_name) + (i.note ? '<br><small style="color:#8A7B82">' + esc(i.note) + '</small>' : '') + '</li>'; }).join('');
    var m = modal('<h3>' + esc(o.po_number) + ' <button data-close aria-label="Tutup">×</button></h3>' +
      '<dl class="kv"><dt>Pelanggan</dt><dd>' + esc(o.customer_name) + '</dd><dt>WhatsApp</dt><dd><a target="_blank" rel="noopener" href="' + esc(o.whatsapp_link) + '">' + esc(o.whatsapp) + '</a></dd>' +
      '<dt>Alamat</dt><dd>' + esc(o.address) + '</dd><dt>Kirim</dt><dd>' + Util.dateLong(o.delivery_date) + ', ' + esc(o.delivery_time) + '</dd>' +
      '<dt>Catatan</dt><dd>' + esc(o.notes || '-') + '</dd><dt>Status</dt><dd><span class="pill ' + o.status + '">' + esc(o.status_label) + '</span></dd>' +
      (o.rejected_reason ? '<dt>Alasan ditolak</dt><dd>' + esc(o.rejected_reason) + '</dd>' : '') +
      (o.validated_by ? '<dt>Dikonfirmasi oleh</dt><dd>' + esc(o.validated_by) + '</dd>' : '') + '</dl>' +
      '<ul class="items-list">' + items + '</ul>' +
      '<dl class="kv"><dt>Subtotal</dt><dd>' + Util.rupiah(o.subtotal) + '</dd><dt>Ongkir</dt><dd>' + Util.rupiah(o.shipping_fee) + '</dd><dt>Total bayar</dt><dd><b>' + Util.rupiah(o.total) + '</b></dd></dl>' +
      '<div class="actions">' +
      (o.status === 'menunggu_konfirmasi' || o.status === 'menunggu_pembayaran' ? '<button class="btn danger" data-act="reject">Tolak / Batalkan</button><button class="btn green" data-act="confirm">Konfirmasi Bayar</button>' : '') +
      (o.status === 'proses_dapur' ? '<button class="btn primary" data-act="done">Tandai Selesai</button>' : '') +
      '</div>');
    m.addEventListener('click', function (e) {
      var b = e.target.closest('[data-act]'); if (!b) return;
      if (b.dataset.act === 'confirm') confirmPay(o.id, o.total);
      if (b.dataset.act === 'reject') rejectPay(o.id);
      if (b.dataset.act === 'done') setDone(o.id);
    });
  }

  function confirmPay(id, total) {
    var m = modal('<h3>Konfirmasi Pembayaran <button data-close aria-label="Tutup">×</button></h3>' +
      '<p>Pastikan uang <b>' + Util.rupiah(total) + '</b> sudah masuk di mutasi rekening/QRIS sebelum melanjutkan.</p>' +
      '<div class="field"><label for="pm">Metode pembayaran</label><select id="pm"><option>QRIS</option><option>Transfer BCA</option><option>Transfer Bank Lain</option></select></div>' +
      '<div class="actions"><button class="btn" data-close>Batal</button><button class="btn green" id="okPay">Uang sudah masuk</button></div>');
    m.querySelector('#okPay').addEventListener('click', async function () {
      this.disabled = true;
      try { await Api.post('admin/orders/' + id + '/validate', { auth: true, json: { payment_method: m.querySelector('#pm').value } }); Util.toast('Pembayaran dikonfirmasi. Pesanan masuk dapur.', 'success'); close(); load(); }
      catch (e) { Util.toast(e.message, 'error'); this.disabled = false; }
    });
  }

  function rejectPay(id) {
    var m = modal('<h3>Tolak / Batalkan Pesanan <button data-close aria-label="Tutup">×</button></h3>' +
      '<p>Pesanan dibatalkan dan kuota dikembalikan.</p><div class="field"><label for="rs">Alasan (opsional)</label><input id="rs" maxlength="200" placeholder="mis. pembayaran tidak ditemukan"></div>' +
      '<div class="actions"><button class="btn" data-close>Kembali</button><button class="btn danger" id="okRej">Batalkan pesanan</button></div>');
    m.querySelector('#okRej').addEventListener('click', async function () {
      this.disabled = true;
      try { await Api.post('admin/orders/' + id + '/reject', { auth: true, json: { reason: m.querySelector('#rs').value.trim() } }); Util.toast('Pesanan dibatalkan.', 'success'); close(); load(); }
      catch (e) { Util.toast(e.message, 'error'); this.disabled = false; }
    });
  }

  async function setDone(id) {
    try { await Api.patch('admin/orders/' + id + '/status', { auth: true, json: { status: 'selesai' } }); Util.toast('Pesanan ditandai selesai.', 'success'); close(); load(); }
    catch (e) { Util.toast(e.message, 'error'); }
  }

  AdminShell.init('pesanan').then(function (user) {
    if (!user) return;
    var params = new URLSearchParams(location.search);
    if (params.get('search')) document.getElementById('fSearch').value = params.get('search');
    var reload = function () { state.page = 1; load(); };
    document.getElementById('fSearch').addEventListener('input', Util.debounce(reload, 350));
    document.getElementById('fDate').addEventListener('change', reload);
    document.getElementById('fStatus').addEventListener('change', reload);
    document.getElementById('fReset').addEventListener('click', function () {
      ['fSearch', 'fDate', 'fStatus'].forEach(function (i) { document.getElementById(i).value = ''; }); reload();
    });
    document.getElementById('pager').addEventListener('click', function (e) {
      var b = e.target.closest('button[data-p]'); if (!b || b.disabled) return;
      state.page = Number(b.dataset.p); load();
    });
    document.getElementById('rows').addEventListener('click', function (e) {
      var b = e.target.closest('[data-act]'); if (!b) return;
      var id = Number(b.dataset.id), o = current.filter(function (x) { return x.id === id; })[0];
      if (b.dataset.act === 'detail') showDetail(id);
      if (b.dataset.act === 'confirm') confirmPay(id, o ? o.total : 0);
      if (b.dataset.act === 'done') setDone(id);
    });
    document.getElementById('exportBtn').addEventListener('click', async function () {
      this.disabled = true;
      try { var r = await Api.get('admin/orders/export', { auth: true, blob: true, query: q() }); Api.saveBlob(r.blob, r.filename || 'data-pesanan-acicu.xlsx'); }
      catch (e) { Util.toast(e.message, 'error'); }
      this.disabled = false;
    });
    load();
    // data pesanan diperbarui otomatis; ditunda saat ada jendela dialog terbuka atau tab tidak aktif
    var auto = function () { if (!document.hidden && !document.getElementById('modalHost').firstChild) load(); };
    setInterval(auto, 15000);
    document.addEventListener('visibilitychange', auto);
  });
})();
