(function () {
  var esc = Util.esc, S = {}, slots = [];
  var $ = function (id) { return document.getElementById(id); };
  var TEXT = ['closed_message', 'batch_label', 'shipping_fee', 'order_cutoff_minutes', 'max_advance_days', 'payment_ttl_minutes', 'bank_name', 'bank_account', 'bank_holder', 'whatsapp', 'whatsapp_display', 'instagram', 'tiktok', 'admin_hours'];

  function renderSlots() {
    slots.sort();
    $('slotList').innerHTML = slots.map(function (t) { return '<div class="s">' + t + '<button type="button" data-rm="' + t + '" aria-label="Hapus ' + t + '">×</button></div>'; }).join('') || '<span class="sub">Belum ada jam.</span>';
  }
  function fill(d) {
    S = d; slots = (d.delivery_slots || []).slice();
    $('store_open').checked = !!d.store_open; storeLbl();
    $('require_proof').checked = !!d.require_proof;
    TEXT.forEach(function (k) { $(k).value = d[k] == null ? '' : d[k]; });
    renderSlots();
    $('qrisPrev').hidden = !d.qris_url; if (d.qris_url) $('qrisPrev').src = d.qris_url;
  }
  function storeLbl() { $('storeLbl').textContent = $('store_open').checked ? 'Pre-order dibuka' : 'Pre-order ditutup'; }

  async function save(extra) {
    var err = $('setErr'); err.classList.add('hidden');
    var fd = new FormData();
    fd.append('store_open', $('store_open').checked ? '1' : '0');
    fd.append('require_proof', $('require_proof').checked ? '1' : '0');
    TEXT.forEach(function (k) { fd.append(k, $(k).value); });
    fd.append('delivery_slots', slots.join(','));
    if ($('qris').files[0]) fd.append('qris', $('qris').files[0]);
    if (extra) Object.keys(extra).forEach(function (k) { fd.append(k, extra[k]); });
    var btn = $('saveBtn'); btn.disabled = true;
    try {
      var res = await Api.post('admin/settings', { auth: true, form: fd });
      fill(res.data); $('qris').value = '';
      Util.toast('Pengaturan tersimpan.', 'success');
    } catch (e) {
      var first = e.errors ? e.errors[Object.keys(e.errors)[0]][0] : e.message;
      err.textContent = first; err.classList.remove('hidden'); err.scrollIntoView({ block: 'center' });
    }
    btn.disabled = false;
  }

  /* ---------- akun ---------- */
  function modal(html) {
    var h = $('modalHost'); h.innerHTML = '<div class="modal"><div class="box">' + html + '</div></div>';
    var m = h.firstChild; m.addEventListener('click', function (e) { if (e.target === m || e.target.closest('[data-close]')) h.innerHTML = ''; }); return m;
  }
  var users = [];
  async function loadUsers() {
    try { users = (await Api.get('admin/users', { auth: true })).data; } catch (e) { Util.toast(e.message, 'error'); return; }
    var me = AdminShell.user().id;
    $('userRows').innerHTML = users.map(function (u) {
      return '<tr><td><b>' + esc(u.name) + '</b><span class="sm">' + esc(u.email) + '</span></td><td>' + esc(u.username) + '</td><td>' + esc(u.role_label) + '</td>' +
        '<td><span class="pill ' + (u.is_active ? 'tersedia' : 'nonaktif') + '">' + (u.is_active ? 'Aktif' : 'Nonaktif') + '</span></td>' +
        '<td><div class="row-actions"><button class="btn sm" data-u="pw" data-id="' + u.id + '">Reset sandi</button>' +
        (u.id === me ? '' : '<button class="btn sm ' + (u.is_active ? 'danger' : 'green') + '" data-u="toggle" data-id="' + u.id + '">' + (u.is_active ? 'Nonaktifkan' : 'Aktifkan') + '</button>') + '</div></td></tr>';
    }).join('');
  }
  function userForm() {
    var m = modal('<h3>Tambah Akun <button data-close>×</button></h3><div class="alert hidden" id="uErr"></div><form id="uf" novalidate>' +
      '<div class="field"><label>Nama</label><input name="name"></div><div class="field"><label>Username</label><input name="username"></div>' +
      '<div class="field"><label>Email</label><input name="email" type="email"></div>' +
      '<div class="field"><label>Peran</label><select name="role"><option value="admin">Admin</option><option value="owner">Owner</option></select></div>' +
      '<div class="field"><label>Kata sandi awal</label><input name="password" type="password" autocomplete="new-password"><span class="hint">Min. 10 karakter, huruf dan angka.</span></div>' +
      '<div class="actions"><button type="button" class="btn" data-close>Batal</button><button class="btn primary">Simpan</button></div></form>');
    m.querySelector('#uf').addEventListener('submit', async function (e) {
      e.preventDefault(); var f = e.target, body = {};
      ['name', 'username', 'email', 'role', 'password'].forEach(function (k) { body[k] = f[k].value; });
      try { await Api.post('admin/users', { auth: true, json: body }); Util.toast('Akun ditambahkan.', 'success'); $('modalHost').innerHTML = ''; loadUsers(); }
      catch (x) { var er = m.querySelector('#uErr'); er.textContent = x.errors ? x.errors[Object.keys(x.errors)[0]][0] : x.message; er.classList.remove('hidden'); }
    });
  }
  function resetPw(u) {
    var m = modal('<h3>Reset Sandi ' + esc(u.username) + ' <button data-close>×</button></h3><div class="alert hidden" id="uErr"></div>' +
      '<div class="field"><label>Sandi baru</label><input id="np" type="password" autocomplete="new-password"><span class="hint">Min. 10 karakter, huruf dan angka.</span></div>' +
      '<div class="actions"><button class="btn" data-close>Batal</button><button class="btn primary" id="okpw">Reset</button></div>');
    m.querySelector('#okpw').addEventListener('click', async function () {
      try { await Api.post('admin/users/' + u.id + '/password', { auth: true, json: { password: m.querySelector('#np').value } }); Util.toast('Sandi direset.', 'success'); $('modalHost').innerHTML = ''; }
      catch (x) { var er = m.querySelector('#uErr'); er.textContent = x.errors ? x.errors[Object.keys(x.errors)[0]][0] : x.message; er.classList.remove('hidden'); }
    });
  }

  AdminShell.init('pengaturan', { ownerOnly: true }).then(async function (user) {
    if (!user) return;
    try { fill((await Api.get('admin/settings', { auth: true })).data); } catch (e) { Util.toast(e.message, 'error'); }
    $('store_open').addEventListener('change', storeLbl);
    $('tabs').addEventListener('click', function (e) {
      var b = e.target.closest('[data-tab]'); if (!b) return;
      document.querySelectorAll('#tabs .chip').forEach(function (x) { x.classList.toggle('on', x === b); });
      var t = b.dataset.tab, form = t !== 'akun';
      document.querySelector('[data-pane="toko"]').hidden = t !== 'toko';
      document.querySelector('[data-pane="bayar"]').hidden = t !== 'bayar';
      document.querySelector('[data-pane="akun"]').hidden = form;
      $('saveBar').hidden = !form;
      if (t === 'akun') loadUsers();
    });
    $('setForm').addEventListener('submit', function (e) { e.preventDefault(); save(); });
    $('slotAdd').addEventListener('click', function () { var v = $('slotNew').value; if (v && slots.indexOf(v) < 0) { slots.push(v); renderSlots(); } $('slotNew').value = ''; });
    $('slotHourly').addEventListener('click', function () { slots = []; for (var h = 6; h <= 22; h++) slots.push(Util.pad(h) + ':00'); renderSlots(); });
    $('slotList').addEventListener('click', function (e) { var b = e.target.closest('[data-rm]'); if (!b) return; slots = slots.filter(function (t) { return t !== b.dataset.rm; }); renderSlots(); });
    $('qrisRemove').addEventListener('click', function () { if (confirm('Hapus gambar QRIS?')) save({ remove_qris: '1' }); });
    $('userAdd').addEventListener('click', userForm);
    $('userRows').addEventListener('click', async function (e) {
      var b = e.target.closest('[data-u]'); if (!b) return;
      var u = users.filter(function (x) { return x.id === Number(b.dataset.id); })[0];
      if (b.dataset.u === 'pw') return resetPw(u);
      try { await Api.patch('admin/users/' + u.id, { auth: true, json: { is_active: !u.is_active } }); Util.toast('Status akun diperbarui.', 'success'); loadUsers(); }
      catch (x) { Util.toast(x.message, 'error'); }
    });
    $('pwForm').addEventListener('submit', async function (e) {
      e.preventDefault(); var f = e.target, er = $('pwErr'); er.classList.add('hidden');
      try {
        await Api.post('admin/me/password', { auth: true, json: { current_password: f.current_password.value, password: f.password.value, password_confirmation: f.password_confirmation.value } });
        f.reset(); Util.toast('Sandi berhasil diganti.', 'success');
      } catch (x) { er.textContent = x.errors ? x.errors[Object.keys(x.errors)[0]][0] : x.message; er.classList.remove('hidden'); }
    });
  });
})();
