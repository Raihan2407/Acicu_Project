(function () {
  var data, metric = 'portions';

  function card(icon, cls, label, value, foot) {
    return '<div class="card stat"><div class="top"><span class="label">' + label + '</span><span class="ico ' + cls + '"><i class="fa-solid ' + icon + '"></i></span></div><div class="value">' + value + '</div><div class="foot">' + foot + '</div></div>';
  }

  function renderCards() {
    var c = data.cards, ch = c.orders_today.change_percent, chTxt;
    var total7 = data.trend.reduce(function (n, d) { return n + d.orders; }, 0);
    if (ch === null) chTxt = '<span>Hari ini</span>';
    else chTxt = '<span class="tag ' + (ch >= 0 ? 'up' : 'down') + '">' + (ch >= 0 ? '▲ ' : '▼ ') + Math.abs(ch) + '%</span> dari kemarin';
    var top = c.top_product.name;
    document.getElementById('cards').innerHTML =
      card('fa-receipt', '', 'Total Pesanan', total7 + '<small>PO</small>', '7 hari terakhir') +
      card('fa-bag-shopping', 'hot', 'Pesanan Hari Ini', c.orders_today.count + '<small>PO</small>', chTxt) +
      card('fa-bowl-food', '', 'Produk Terlaris', top ? '<span style="font-size:2.6rem">' + Util.esc(top) + '</span>' : '<span style="font-size:2.6rem">-</span>', '<span>' + c.top_product.portions + ' porsi terjual</span>' + (top ? '<span class="tag fav">Favorit</span>' : '')) +
      card('fa-wallet', 'mint', 'Total Pendapatan', '<span style="font-size:3.4rem">' + Util.rupiah(c.revenue.amount) + '</span>', 'Pembayaran lunas, ' + c.revenue.period);
  }

  function renderChart() {
    var t = data.trend, W = 640, H = 260, pl = 46, pr = 16, pt = 18, pb = 34;
    var vals = t.map(function (d) { return d[metric]; });
    var max = Math.max.apply(null, vals.concat([1]));
    var step = Math.pow(10, Math.floor(Math.log10(max)));
    max = Math.ceil(max / step) * step;
    var iw = W - pl - pr, ih = H - pt - pb;
    var x = function (i) { return pl + (iw / (t.length - 1)) * i; };
    var y = function (v) { return pt + ih - (v / max) * ih; };
    var fmt = metric === 'revenue' ? Util.rupiahCompact : function (v) { return v; };
    var s = '<svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Grafik tren 7 hari">';
    for (var g = 0; g <= 4; g++) {
      var gv = max / 4 * g, gy = y(gv);
      s += '<line x1="' + pl + '" x2="' + (W - pr) + '" y1="' + gy + '" y2="' + gy + '" stroke="#F0DCE3"/>' +
        '<text x="' + (pl - 8) + '" y="' + (gy + 4) + '" text-anchor="end" font-size="11" fill="#8A7B82">' + fmt(Math.round(gv)) + '</text>';
    }
    var pts = t.map(function (d, i) { return x(i) + ',' + y(d[metric]); }).join(' ');
    var P = t.map(function (d, i) { return [x(i), y(d[metric])]; }), path = 'M' + P[0][0] + ',' + P[0][1];
    for (var k = 1; k < P.length; k++) { var cx = (P[k - 1][0] + P[k][0]) / 2; path += ' C' + cx + ',' + P[k - 1][1] + ' ' + cx + ',' + P[k][1] + ' ' + P[k][0] + ',' + P[k][1]; }
    s += '<defs><linearGradient id="gr" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#FF5C8A" stop-opacity=".35"/><stop offset="1" stop-color="#FF5C8A" stop-opacity="0"/></linearGradient></defs>';
    s += '<path d="' + path + ' L' + x(t.length - 1) + ',' + (pt + ih) + ' L' + pl + ',' + (pt + ih) + ' Z" fill="url(#gr)"/>';
    s += '<path d="' + path + '" fill="none" stroke="#FF5C8A" stroke-width="3.5" stroke-linecap="round"/>';
    t.forEach(function (d, i) {
      s += '<circle cx="' + x(i) + '" cy="' + y(d[metric]) + '" r="4.5" fill="#fff" stroke="#FF5C8A" stroke-width="2.5"><title>' + d.label + ': ' + (metric === 'revenue' ? Util.rupiah(d.revenue) : d.portions + ' porsi') + '</title></circle>' +
        '<text x="' + x(i) + '" y="' + (H - 10) + '" text-anchor="middle" font-size="12" fill="#2B1F25">' + d.label + '</text>';
    });
    document.getElementById('chart').innerHTML = s + '</svg>';
  }

  function renderInsight() {
    var i = data.insight, none = !data.trend.some(function (d) { return d.orders > 0; });
    document.getElementById('insight').innerHTML = none ? '<i class="fa-solid fa-lightbulb"></i> Belum ada pesanan dalam 7 hari terakhir.' :
      '<i class="fa-solid fa-lightbulb"></i> Pesanan paling ramai pada <b>' + Util.esc(i.peak_labels.join(', ')) + '</b><span class="avg">Rata-rata: <b>' + i.average_orders + ' PO</b> / hari</span>';
  }

  function renderRecent() {
    var el = document.getElementById('recent');
    if (!data.recent.length) { el.style.display = 'block'; el.innerHTML = '<li class="empty-state" style="display:block;background:none"><i class="fa-solid fa-inbox"></i><b>Belum ada pesanan</b></li>'; return; }
    el.style.display = '';
    el.innerHTML = data.recent.map(function (r) {
      return '<li><span class="no">#' + Util.esc(r.po_number.replace(/^PO-/, '')) + '</span><div class="txt"><b>' + Util.esc(r.title) + '</b><small>' + Util.esc(r.customer_name) + ' · ' + Util.dateTimeShort(r.ordered_at) + '</small></div><span class="pill ' + r.status + '">' + (Util.STATUS_LABEL[r.status] || r.status) + '</span></li>';
    }).join('');
  }

  async function load(first) {
    try {
      data = (await Api.get('admin/dashboard', { auth: true })).data;
    } catch (e) { if (first) Util.toast(e.message, 'error'); return; }
    document.getElementById('batchLabel').textContent = data.batch_label || '-';
    document.getElementById('eyDate').textContent = Util.dateFull();
    document.getElementById('hello').textContent = 'Halo, ' + AdminShell.user().name + '!';
    renderCards(); renderChart(); renderInsight(); renderRecent();
  }

  AdminShell.init('dashboard').then(async function (user) {
    if (!user) return;
    await load(true);
    if (!data) return;
    setInterval(function () { if (!document.hidden) load(false); }, 30000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) load(false); });
    document.getElementById('metricSeg').addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) return;
      metric = b.dataset.m;
      this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
      renderChart();
    });
  });
})();
