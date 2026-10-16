(function () {
  var esc = Util.esc, all = [], meta = {}, category = '';

  async function load() {
    try {
      var res = await Api.get('admin/products', { auth: true, query: { search: document.getElementById('pSearch').value.trim(), category: category } });
      all = res.data; meta = res.meta;
      render();
    } catch (e) { Util.toast(e.message, 'error'); }
  }

  function render() {
    document.getElementById('prodInfo').textContent = meta.total + ' produk ditampilkan · ' + meta.active_count + ' aktif · kuota per ' + Util.dateShort(Util.todayIso());
    var cats = [''].concat(meta.categories || []);
    document.getElementById('chips').innerHTML = cats.map(function (c) {
      return '<button class="chip' + (c === category ? ' on' : '') + '" data-c="' + esc(c) + '">' + (c || 'Semua') + '</button>';
    }).join('');
    var tb = document.getElementById('rows');
    if (!all.length) { tb.innerHTML = '<tr><td colspan="7" class="empty">Belum ada produk.</td></tr>'; return; }
    tb.innerHTML = all.map(function (p) {
      var pct = p.daily_quota ? Math.min(100, Math.round(p.reserved_today / p.daily_quota * 100)) : 100;
      return '<tr><td><img class="thumb" src="' + esc(p.image_url || '') + '" alt="" onerror="this.style.visibility=\'hidden\'"></td>' +
        '<td><b>' + esc(p.name) + '</b><span class="sm">' + esc(p.short_description || '') + '</span></td><td><span class="cat">' + esc(p.category) + '</span></td>' +
        '<td class="num">' + Util.rupiah(p.price) + '</td>' +
        '<td class="quota"><div class="nums"><span>' + p.reserved_today + ' terpesan</span><span>/ ' + p.daily_quota + '</span></div><div class="bar"><i class="' + (pct >= 90 ? 'low' : '') + '" style="width:' + pct + '%"></i></div>' +
        '<div class="stock-edit"><input type="number" min="0" value="' + p.daily_quota + '" data-q="' + p.id + '" aria-label="Kuota per hari"><button class="btn sm" data-act="quota" data-id="' + p.id + '">Simpan</button></div></td>' +
        '<td><span class="pill ' + p.status + '">' + { tersedia: 'Tersedia', habis: 'Habis', nonaktif: 'Nonaktif' }[p.status] + '</span>' +
        '<label class="switch" style="margin-top:8px" title="Tampilkan di menu"><input type="checkbox" data-act="active" data-id="' + p.id + '"' + (p.is_active ? ' checked' : '') + '><span></span></label></td>' +
        '<td><div class="row-actions"><button class="btn round" data-act="edit" data-id="' + p.id + '" title="Edit"><i class="fa-solid fa-pen"></i></button><button class="btn round danger" data-act="del" data-id="' + p.id + '" title="Hapus"><i class="fa-solid fa-trash"></i></button></div></td></tr>';
    }).join('');
  }

  function close() { document.getElementById('modalHost').innerHTML = ''; }
  function modal(html) {
    var host = document.getElementById('modalHost');
    host.innerHTML = '<div class="modal" role="dialog" aria-modal="true"><div class="box">' + html + '</div></div>';
    var m = host.firstChild;
    m.addEventListener('click', function (e) { if (e.target === m || e.target.closest('[data-close]')) close(); });
    return m;
  }

  function form(p) {
    p = p || {};
    var cats = (meta.categories || ['Aci Kering']).map(function (c) { return '<option' + (p.category === c ? ' selected' : '') + '>' + esc(c) + '</option>'; }).join('');
    var m = modal('<h3>' + (p.id ? 'Edit Produk' : 'Tambah Produk') + ' <button data-close aria-label="Tutup">×</button></h3>' +
      '<form id="pf" novalidate><div class="alert hidden" id="pfErr"></div>' +
      '<div class="field"><label>Nama produk</label><input name="name" maxlength="120" required value="' + esc(p.name || '') + '"></div>' +
      '<div class="field"><label>Kategori</label><select name="category">' + cats + '</select></div>' +
      '<div class="field"><label>Deskripsi singkat</label><input name="short_description" maxlength="255" value="' + esc(p.short_description || '') + '"></div>' +
      '<div class="field"><label>Catatan bawaan (level pedas, dll.)</label><input name="default_note" maxlength="255" value="' + esc(p.default_note || '') + '"></div>' +
      '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px"><div class="field"><label>Harga (Rp)</label><input name="price" type="number" min="0" step="500" required value="' + (p.price != null ? p.price : '') + '"></div>' +
      '<div class="field"><label>Kuota per hari</label><input name="daily_quota" type="number" min="0" required value="' + (p.daily_quota != null ? p.daily_quota : '') + '"></div></div>' +
      '<div class="field"><label>Foto (JPG/PNG/WEBP, maks 2 MB)</label><input name="image" type="file" accept="image/png,image/jpeg,image/webp"></div>' +
      '<label class="check"><input type="checkbox" name="is_active"' + (p.id && !p.is_active ? '' : ' checked') + '> Aktif (tampil di menu)</label>' +
      '<div class="actions"><button type="button" class="btn" data-close>Batal</button><button class="btn primary" type="submit">Simpan</button></div></form>');
    var f = m.querySelector('#pf');
    f.addEventListener('submit', async function (e) {
      e.preventDefault();
      var err = m.querySelector('#pfErr'); err.classList.add('hidden');
      var fd = new FormData();
      ['name', 'category', 'short_description', 'default_note', 'price', 'daily_quota'].forEach(function (k) { fd.append(k, f[k].value); });
      fd.append('is_active', f.is_active.checked ? '1' : '0');
      if (f.image.files[0]) fd.append('image', f.image.files[0]);
      var btn = f.querySelector('[type=submit]'); btn.disabled = true;
      try {
        await Api.post('admin/products' + (p.id ? '/' + p.id : ''), { auth: true, form: fd });
        Util.toast('Produk disimpan.', 'success'); close(); load();
      } catch (ex) { err.textContent = ex.message; err.classList.remove('hidden'); btn.disabled = false; }
    });
  }

  function confirmDelete(p) {
    var m = modal('<h3>Hapus Produk <button data-close aria-label="Tutup">×</button></h3><p>Hapus <b>' + esc(p.name) + '</b>? Riwayat pesanan lama tetap tersimpan.</p>' +
      '<div class="actions"><button class="btn" data-close>Batal</button><button class="btn danger" id="okDel">Hapus</button></div>');
    m.querySelector('#okDel').addEventListener('click', async function () {
      this.disabled = true;
      try { await Api.del('admin/products/' + p.id, { auth: true }); Util.toast('Produk dihapus.', 'success'); close(); load(); }
      catch (e) { Util.toast(e.message, 'error'); this.disabled = false; }
    });
  }

  AdminShell.init('produk').then(function (user) {
    if (!user) return;
    document.getElementById('pSearch').addEventListener('input', Util.debounce(load, 300));
    document.getElementById('chips').addEventListener('click', function (e) {
      var b = e.target.closest('[data-c]'); if (!b) return; category = b.dataset.c; load();
    });
    document.getElementById('addBtn').addEventListener('click', function () { form(); });
    document.getElementById('rows').addEventListener('change', async function (e) {
      var t = e.target.closest('[data-act="active"]'); if (!t) return;
      try { await Api.patch('admin/products/' + t.dataset.id + '/stock', { auth: true, json: { is_active: t.checked } }); Util.toast(t.checked ? 'Produk ditampilkan di menu.' : 'Produk disembunyikan dari menu.', 'success'); load(); }
      catch (x) { Util.toast(x.message, 'error'); t.checked = !t.checked; }
    });
    document.getElementById('rows').addEventListener('click', async function (e) {
      var b = e.target.closest('[data-act]'); if (!b) return;
      if (b.dataset.act === 'quota') {
        var inp = document.querySelector('[data-q="' + b.dataset.id + '"]');
        try { await Api.patch('admin/products/' + b.dataset.id + '/stock', { auth: true, json: { daily_quota: Number(inp.value) } }); Util.toast('Kuota diperbarui. Landing page ikut berubah.', 'success'); load(); }
        catch (x) { Util.toast(x.message, 'error'); }
        return;
      }
      if (b.dataset.act === 'active') return;
      var p = all.filter(function (x) { return x.id === Number(b.dataset.id); })[0];
      if (!p) return;
      if (b.dataset.act === 'edit') form(p); else confirmDelete(p);
    });
    load();
    var auto = function () { if (!document.hidden && !document.getElementById('modalHost').firstChild && !(document.activeElement && document.activeElement.matches('[data-q]'))) load(); };
    setInterval(auto, 30000); // kuota terpesan ikut bergerak saat ada pesanan masuk
    document.addEventListener('visibilitychange', auto);
  });
})();
