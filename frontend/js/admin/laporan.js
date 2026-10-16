(function () {
  var esc = Util.esc, state = { page: 1, per_page: 25 };

  function range() { return { from: document.getElementById('rFrom').value, to: document.getElementById('rTo').value }; }

  function setRange(kind) {
    var to = Util.todayIso(), from;
    if (kind === 'month') from = to.slice(0, 8) + '01';
    else from = Util.addDays(to, -(Number(kind) - 1));
    document.getElementById('rFrom').value = from;
    document.getElementById('rTo').value = to;
    state.page = 1; load();
  }

  function stat(icon, label, value) {
    return '<div class="card stat"><div class="label"><i class="fa-solid ' + icon + '"></i>' + label + '</div><div class="value">' + value + '</div></div>';
  }

  function pager(m) {
    var el = document.getElementById('pager');
    if (!m.total) { el.innerHTML = ''; return; }
    var from = Math.max(1, m.current_page - 2), to = Math.min(m.last_page, from + 4); from = Math.max(1, to - 4);
    var pages = '';
    for (var p = from; p <= to; p++) pages += '<button data-p="' + p + '" class="' + (p === m.current_page ? 'on' : '') + '">' + p + '</button>';
    el.innerHTML = '<span>Baris per halaman <select id="pp">' + [10, 25, 50, 100].map(function (n) { return '<option' + (n === state.per_page ? ' selected' : '') + '>' + n + '</option>'; }).join('') +
      '</select> · ' + m.from + '–' + m.to + ' dari ' + m.total + '</span><div class="pages"><button data-p="' + (m.current_page - 1) + '"' + (m.current_page <= 1 ? ' disabled' : '') + '>‹</button>' + pages +
      '<button data-p="' + (m.current_page + 1) + '"' + (m.current_page >= m.last_page ? ' disabled' : '') + '>›</button></div>';
  }

  async function load() {
    try {
      var r = range();
      var res = await Api.get('admin/reports', { auth: true, query: { from: r.from, to: r.to, page: state.page, per_page: state.per_page } });
      var d = res.data;
      document.getElementById('rFrom').value = d.period.from; document.getElementById('rTo').value = d.period.to;
      document.getElementById('cards').innerHTML = stat('fa-receipt', 'Total Pesanan', d.summary.orders) + stat('fa-bowl-food', 'Total Porsi', d.summary.portions) + stat('fa-wallet', 'Pendapatan Bersih', Util.rupiah(d.summary.revenue));
      var tb = document.getElementById('rows');
      tb.innerHTML = d.transactions.length ? d.transactions.map(function (t) {
        return '<tr><td><b>' + esc(t.transaction_id) + '</b><span class="sm">' + esc(t.po_number) + '</span></td><td>' + Util.dateLong(t.ordered_at) + '<span class="sm">' + Util.timeWib(t.ordered_at) + '</span></td>' +
          '<td>' + esc(t.customer_name) + '<span class="sm">' + esc(t.whatsapp_masked) + '</span></td><td>' + esc(t.items_summary) + '</td><td class="num">' + t.portions + '</td>' +
          '<td>' + esc(t.payment_method) + '</td><td class="num">' + Util.rupiah(t.net_total) + '</td><td><span class="pill lunas">Lunas</span></td></tr>';
      }).join('') : '<tr><td colspan="8" class="empty">Tidak ada transaksi lunas pada periode ini.</td></tr>';
      pager(res.meta);
    } catch (e) { Util.toast(e.message, 'error'); }
  }

  AdminShell.init('laporan', { ownerOnly: true }).then(function (user) {
    if (!user) return;
    document.querySelectorAll('[data-range]').forEach(function (b) { b.addEventListener('click', function () { setRange(b.dataset.range); }); });
    ['rFrom', 'rTo'].forEach(function (id) { document.getElementById(id).addEventListener('change', function () { state.page = 1; load(); }); });
    document.getElementById('pager').addEventListener('click', function (e) {
      var b = e.target.closest('button[data-p]'); if (!b || b.disabled) return; state.page = Number(b.dataset.p); load();
    });
    document.getElementById('pager').addEventListener('change', function (e) {
      if (e.target.id === 'pp') { state.per_page = Number(e.target.value); state.page = 1; load(); }
    });
    document.getElementById('exportBtn').addEventListener('click', async function () {
      this.disabled = true;
      try { var r = await Api.get('admin/reports/export', { auth: true, blob: true, query: range() }); Api.saveBlob(r.blob, r.filename || 'laporan-penjualan-acicu.xlsx'); }
      catch (e) { Util.toast(e.message, 'error'); }
      this.disabled = false;
    });
    setRange(7);
  });
})();
