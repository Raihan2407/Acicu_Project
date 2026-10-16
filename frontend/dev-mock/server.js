/* API tiruan (tanpa dependensi) untuk mengembangkan/menguji frontend tanpa Laravel.
   Jalankan: node dev-mock/server.js  → http://localhost:8000
   Akun demo: owner / admin, kata sandi: demo12345  (HANYA untuk mock ini) */
const http = require('http');
const path = require('path');
const fs = require('fs');
const PORT = process.env.PORT || 8000;
const IMG_DIR = path.join(__dirname, '..', 'assets', 'img');
const iso = (d) => d.toISOString();
const day = (n) => { const d = new Date(); d.setDate(d.getDate() + n); return d.toISOString().slice(0, 10); };

const users = {
  owner: { id: 1, name: 'Owner Bungcu', username: 'owner', email: 'owner@acicu.test', role: 'owner', role_label: 'Owner' },
  admin: { id: 2, name: 'Admin Dapur', username: 'admin', email: 'admin@acicu.test', role: 'admin', role_label: 'Admin' },
};
const tokens = {};
const SET = { store_open: true, closed_message: 'Pre-order sedang ditutup. Silakan kembali lagi nanti.', batch_label: 'Batch Contoh', shipping_fee: 5000, delivery_slots: Array.from({ length: 17 }, (_, i) => String(i + 6).padStart(2, '0') + ':00'), order_cutoff_minutes: 90, max_advance_days: 30, payment_ttl_minutes: 60, bank_name: 'BCA', bank_account: '827-091-2384', bank_holder: 'Aneka Aci Bungcu', whatsapp: '6282286512274', whatsapp_display: '082286512274', instagram: 'acicu.licious', tiktok: 'acicu.licious', admin_hours: '08:00 - 19:00 WIB' };
const PASS = {};
let products = [
  { id: 1, name: 'Molachee Keju Crispy', short_name: 'Molachee', slug: 'molachee', category: 'Aci Kering', short_description: 'Aci crispy dengan taburan keju gurih', description: '', default_note: 'Pedas Level: Sedang • Bumbu Ekstra Daun Jeruk', price: 10000, daily_quota: 30, image_url: 'http://localhost:' + PORT + '/images/products/molachee.png', is_active: true },
  { id: 2, name: 'Cilukba Bungcu', short_name: 'Cilukba', slug: 'cilukba', category: 'Aci Kering', short_description: 'Aci kenyal bumbu pedas gurih', description: '', default_note: 'Pedas Level: Sedang', price: 10000, daily_quota: 40, image_url: 'http://localhost:' + PORT + '/images/products/cilukba.png', is_active: true },
  { id: 3, name: 'Ciweng Pedas Daun Jeruk', short_name: 'Ciweng', slug: 'ciweng', category: 'Aci Kering', short_description: 'Ciweng renyah wangi daun jeruk', description: '', default_note: 'Pedas Level: Pedas • Bumbu Ekstra Daun Jeruk', price: 10000, daily_quota: 35, image_url: 'http://localhost:' + PORT + '/images/products/ciweng.png', is_active: true },
];
const LABELS = { menunggu_pembayaran: 'Menunggu Pembayaran', menunggu_konfirmasi: 'Menunggu Konfirmasi', proses_dapur: 'Proses Dapur', selesai: 'Selesai', dibatalkan: 'Dibatalkan' };
let orders = [];
let seq = 823;
function mk(name, wa, items, status, pay, ago, delivery) {
  const sub = items.reduce((s, i) => s + i.qty * 10000, 0);
  const o = { id: ++seq - 0, po_number: 'PO-0' + seq, tracking_token: 'tok' + seq + 'x'.repeat(30), customer_name: name, whatsapp: wa, address: 'Jl. Contoh No. 12, Pekanbaru',
    items: items.map((i) => ({ product_name: i.name, qty: i.qty, note: 'Pedas Level: Sedang', unit_price: 10000, subtotal: i.qty * 10000, name: i.name, image_url: null })),
    subtotal: sub, shipping_fee: 5000, discount: 0, total: sub + 5000, delivery_date: delivery || day(0), delivery_time: '11:30', notes: null,
    status, payment_status: pay, payment_method: pay === 'lunas' ? 'QRIS' : null, created_at: new Date(Date.now() - ago * 3600e3),
    payment_deadline: new Date(Date.now() + 40 * 60e3), customer_paid_at: pay === 'menunggu' ? new Date(Date.now() - 600e3) : null, rejected_reason: null };
  orders.push(o); return o;
}
if (!process.env.EMPTY) {
mk('Siti Aisyah', '081234567801', [{ name: 'Molachee Keju Crispy', qty: 2 }], 'proses_dapur', 'lunas', 30);
mk('Budi Santoso', '081234567802', [{ name: 'Cilukba Bungcu', qty: 3 }, { name: 'Ciweng Pedas Daun Jeruk', qty: 1 }], 'proses_dapur', 'lunas', 26);
mk('Rina Marlina', '081234567803', [{ name: 'Ciweng Pedas Daun Jeruk', qty: 2 }], 'selesai', 'lunas', 50, day(-1));
mk('Dewi Anggraini', '081234567804', [{ name: 'Molachee Keju Crispy', qty: 1 }], 'menunggu_konfirmasi', 'menunggu', 1);
mk('Andi Pratama', '081234567805', [{ name: 'Cilukba Bungcu', qty: 2 }], 'menunggu_pembayaran', 'belum_bayar', 0.2);
for (let i = 0; i < 40; i++) mk('Pelanggan ' + (i + 1), '0812345678' + (10 + i), [{ name: products[i % 3].name, qty: 1 + (i % 4) }], 'selesai', 'lunas', 24 * (1 + (i % 6)) + 3, day(-1 - (i % 6)));
}

const money = (o) => o.subtotal - o.discount;
const portions = (o) => o.items.reduce((s, i) => s + i.qty, 0);
const summary = (o) => o.items.map((i) => i.qty + '× ' + i.product_name).join(', ');
const mask = (w) => w.slice(0, 4) + '-' + w.slice(4, 8) + '-xxxx';
const row = (o) => ({ id: o.id, po_number: o.po_number, customer_name: o.customer_name, whatsapp: o.whatsapp, whatsapp_link: 'https://wa.me/62' + o.whatsapp.slice(1),
  items: o.items, items_summary: summary(o), portions: portions(o), net_total: money(o), total: o.total, ordered_at: iso(o.created_at), delivery_date: o.delivery_date,
  delivery_time: o.delivery_time, status: o.status, status_label: LABELS[o.status], payment_status: o.payment_status, payment_method: o.payment_method,
  payment_deadline: iso(o.payment_deadline), customer_paid_at: o.customer_paid_at && iso(o.customer_paid_at) });
const detail = (o) => ({ ...row(o), address: o.address, notes: o.notes, subtotal: o.subtotal, shipping_fee: o.shipping_fee, discount: o.discount, paid_at: null, rejected_reason: o.rejected_reason, validated_by: null });
const DRIVER = process.env.PAY || 'mock';
const WA_LOG = [];
const payBlock = (o) => ({ driver: DRIVER, dynamic: DRIVER !== 'manual', qr_string: DRIVER !== 'manual' ? 'ACICU-MOCK-QRIS|' + o.po_number + '|' + o.total + '|' + o.tracking_token.slice(3, 19).toUpperCase() : null, qr_url: null, qr_expires_at: iso(o.payment_deadline), simulate: DRIVER === 'mock' });
const pub = (o) => ({ payment: payBlock(o), tracking_token: o.tracking_token, po_number: o.po_number, customer_name: o.customer_name, whatsapp_masked: mask(o.whatsapp), ordered_at: iso(o.created_at),
  delivery_date: o.delivery_date, delivery_time: o.delivery_time, kitchen_eta: '10:00', notes: o.notes, status: o.status, status_label: LABELS[o.status], payment_status: o.payment_status,
  payment_deadline: iso(o.payment_deadline), customer_paid_at: o.customer_paid_at && iso(o.customer_paid_at), rejected_reason: o.rejected_reason,
  items: o.items.map((i) => ({ ...i, image_url: (products.find((p) => p.name === i.product_name) || {}).image_url })), subtotal: o.subtotal, shipping_fee: o.shipping_fee, discount: o.discount, total: o.total });

function reserved(date) { const m = {}; orders.filter((o) => o.delivery_date === date && o.status !== 'dibatalkan').forEach((o) => o.items.forEach((i) => { const p = products.find((x) => x.name === i.product_name); if (p) m[p.id] = (m[p.id] || 0) + i.qty; })); return m; }
const adminProduct = (p) => { const r = reserved(day(0))[p.id] || 0; const rem = Math.max(0, p.daily_quota - r); return { ...p, remaining: rem, available: rem > 0, reserved_today: r, sort_order: p.id, status: !p.is_active ? 'nonaktif' : rem === 0 ? 'habis' : 'tersedia' }; };

function send(res, code, body, headers) {
  res.writeHead(code, { 'X-Server-Time': String(Date.now()), 'Access-Control-Expose-Headers': 'X-Server-Time, Content-Disposition', 'Access-Control-Allow-Origin': '*', 'Access-Control-Allow-Headers': 'Authorization, Content-Type, Accept', 'Access-Control-Allow-Methods': 'GET,POST,PUT,PATCH,DELETE,OPTIONS', 'Content-Type': 'application/json', ...headers });
  res.end(typeof body === 'string' || Buffer.isBuffer(body) ? body : JSON.stringify(body));
}
function readBody(req) { return new Promise((r) => { const c = []; req.on('data', (d) => c.push(d)); req.on('end', () => r(Buffer.concat(c))); }); }
function parseMultipart(buf, ct) {
  const b = /boundary=(.+)$/.exec(ct)[1]; const out = {};
  buf.toString('latin1').split('--' + b).forEach((part) => { const m = /name="([^"]+)"(?!; filename)\r\n\r\n([\s\S]*?)\r\n$/.exec(part); if (m) out[m[1]] = Buffer.from(m[2], 'latin1').toString('utf8'); });
  return out;
}

const server = http.createServer(async (req, res) => {
  const u = new URL(req.url, 'http://x'); const p = u.pathname; const q = Object.fromEntries(u.searchParams);
  if (req.method === 'OPTIONS') return send(res, 204, '');
  if (p.startsWith('/images/')) { const f = path.join(IMG_DIR, path.basename(p)); return fs.existsSync(f) ? send(res, 200, fs.readFileSync(f), { 'Content-Type': f.endsWith('.svg') ? 'image/svg+xml' : 'image/png' }) : send(res, 404, ''); }
  if (!p.startsWith('/api/')) return send(res, 404, { message: 'Not found' });
  const route = p.slice(5);
  const raw = await readBody(req); const ct = req.headers['content-type'] || '';
  let body = {}; try { body = ct.includes('json') ? JSON.parse(raw.toString() || '{}') : ct.includes('multipart') ? parseMultipart(raw, ct) : {}; } catch (e) {}
  const m = req.method;

  if (route === 'config') return send(res, 200, { data: { shipping_fee: SET.shipping_fee, delivery_slots: SET.delivery_slots, store_open: SET.store_open, closed_message: SET.closed_message, max_advance_days: SET.max_advance_days, payment_ttl_minutes: SET.payment_ttl_minutes, order_cutoff_minutes: SET.order_cutoff_minutes, today: day(0), batch_label: SET.batch_label, payment: { bank_name: SET.bank_name, bank_account: SET.bank_account, bank_holder: SET.bank_holder, driver: DRIVER, dynamic: DRIVER !== 'manual', qris_url: 'http://localhost:' + PORT + '/images/qris-placeholder.svg' }, contact: { whatsapp: SET.whatsapp, whatsapp_display: SET.whatsapp_display, instagram: SET.instagram, tiktok: SET.tiktok, admin_hours: SET.admin_hours } } });
  if (route === 'products' && m === 'GET') { const r = reserved(q.date || day(1)); return send(res, 200, { data: products.filter((x) => x.is_active).map((x) => ({ ...x, remaining: Math.max(0, x.daily_quota - (r[x.id] || 0)), available: x.daily_quota - (r[x.id] || 0) > 0 })) }); }
  if (route === 'orders' && m === 'POST') {
    const errors = {}; if (!body.name) errors.name = ['Nama wajib diisi.']; if (!body.whatsapp) errors.whatsapp = ['Nomor WhatsApp wajib diisi.'];
    if (Object.keys(errors).length) return send(res, 422, { message: 'Data tidak valid.', errors });
    const items = (body.items || []).map((i) => ({ name: (products.find((x) => x.id === i.product_id) || {}).name, qty: i.qty })).filter((i) => i.name);
    const o = mk(body.name, body.whatsapp, items, 'menunggu_pembayaran', 'belum_bayar', 0, body.delivery_date); o.address = body.address; o.delivery_time = body.delivery_time; o.notes = body.notes || null;
    return send(res, 201, { data: pub(o) });
  }
  let mt = /^orders\/([^/]+)\/simulate-pay$/.exec(route);
  if (mt && m === 'POST') { const o = orders.find((x) => x.tracking_token === mt[1]); if (!o) return send(res, 404, { message: 'Pesanan tidak ditemukan.' }); if (o.status === 'menunggu_pembayaran' || o.status === 'menunggu_konfirmasi') { o.status = 'proses_dapur'; o.payment_status = 'lunas'; o.payment_method = 'QRIS (simulasi)'; WA_LOG.push('paid ' + o.po_number); } return send(res, 200, { data: pub(o) }); }
  mt = /^orders\/([^/]+)(\/paid)?$/.exec(route);
  if (mt && mt[1] !== 'export') {
    const o = orders.find((x) => x.tracking_token === mt[1]); if (!o) return send(res, 404, { message: 'Pesanan tidak ditemukan.' });
    if (mt[2] && m === 'POST') { if (o.status === 'menunggu_pembayaran') { o.status = 'menunggu_konfirmasi'; o.payment_status = 'menunggu'; o.customer_paid_at = new Date(); } }
    return send(res, 200, { data: pub(o) });
  }
  if (route === 'admin/login' && m === 'POST') {
    const u2 = users[body.login] || Object.values(users).find((x) => x.email === body.login);
    if (!u2 || body.password !== (PASS[u2.username] || 'demo12345')) return send(res, 401, { message: 'Username/email atau kata sandi salah.' });
    const t = 'mock-' + Math.random().toString(36).slice(2); tokens[t] = u2; return send(res, 200, { data: { token: t, expires_at: iso(new Date(Date.now() + 43200e3)), user: me(u2) } });
  }
  function me(x) { return { ...x, can_view_reports: x.role === 'owner', pending_count: orders.filter((o) => o.status === 'menunggu_konfirmasi').length }; }
  if (route.startsWith('admin/')) {
    const user = tokens[(req.headers.authorization || '').replace('Bearer ', '')];
    if (!user) return send(res, 401, { message: 'Unauthenticated.' });
    const r = route.slice(6);
    if (r === 'me') return send(res, 200, { data: me(user) });
    if (r === 'logout') { return send(res, 200, { message: 'Berhasil keluar.' }); }
    if (r === 'dashboard') {
      const labels = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
      const trend = [-6, -5, -4, -3, -2, -1, 0].map((n) => { const d = day(n); const os = orders.filter((o) => o.created_at.toISOString().slice(0, 10) === d); return { date: d, label: labels[new Date(d + 'T00:00').getDay()], orders: os.length, portions: os.reduce((s, o) => s + portions(o), 0), revenue: os.filter((o) => o.payment_status === 'lunas').reduce((s, o) => s + money(o), 0) }; });
      const recent = [...orders].sort((a, b) => b.created_at - a.created_at).slice(0, 3).map((o) => ({ id: o.id, po_number: o.po_number, title: summary(o), customer_name: o.customer_name, ordered_at: iso(o.created_at), status: o.status, payment_status: o.payment_status }));
      return send(res, 200, { data: { today: day(0), batch_label: 'Batch Contoh', cards: { orders_today: { count: trend[6].orders, change_percent: 12 }, to_process: { count: 2 }, top_product: { name: 'Cilukba Bungcu', portions: 48 }, revenue: { amount: trend.reduce((s, d) => s + d.revenue, 0), period: '7 hari terakhir' } }, trend, insight: { peak_dates: [], peak_labels: ['Sel', 'Kam', 'Sab'], average_orders: 6 }, recent } });
    }
    if (r === 'products' && m === 'GET') { const list = products.filter((x) => (!q.category || x.category === q.category) && (!q.search || x.name.toLowerCase().includes(q.search.toLowerCase()))); return send(res, 200, { data: list.map(adminProduct), meta: { total: list.length, active_count: products.filter((x) => x.is_active).length, categories: ['Aci Kering', 'Aci Kuah'] } }); }
    if (r === 'products' && m === 'POST') { if (!body.name) return send(res, 422, { message: 'Nama produk wajib diisi.', errors: { name: ['Nama produk wajib diisi.'] } }); const np = { id: products.length + 10, name: body.name, short_name: body.name.split(' ')[0], slug: body.name.toLowerCase().replace(/\W+/g, '-'), category: body.category, short_description: body.short_description, default_note: body.default_note, price: +body.price, daily_quota: +body.daily_quota, image_url: null, is_active: body.is_active === '1' }; products.push(np); return send(res, 201, { data: adminProduct(np) }); }
    if (r === 'products/stock' ) {}
    mt = /^products\/(\d+)\/stock$/.exec(r);
    if (mt && m === 'PATCH') { const pr = products.find((x) => x.id === +mt[1]); if (!pr) return send(res, 404, { message: 'Tidak ditemukan' }); if (body.daily_quota !== undefined) pr.daily_quota = +body.daily_quota; if (body.is_active !== undefined) pr.is_active = !!body.is_active; return send(res, 200, { data: adminProduct(pr) }); }
    if (r === 'me/password' && m === 'POST') { if (body.current_password !== (PASS[user.username] || 'demo12345')) return send(res, 422, { message: 'Sandi saat ini salah.', errors: { current_password: ['Sandi saat ini salah.'] } }); PASS[user.username] = body.password; return send(res, 200, { message: 'ok' }); }
    if (['settings', 'users'].includes(r.split('/')[0]) && user.role !== 'owner') return send(res, 403, { message: 'Akses ditolak.' });
    if (r === 'settings' && m === 'GET') return send(res, 200, { data: { ...SET, qris_url: null, integrations: { whatsapp_driver: 'log', payment_driver: DRIVER } } });
    if (r === 'settings' && m === 'POST') {
      if (body.delivery_slots) body.delivery_slots = String(body.delivery_slots).split(',').filter(Boolean).sort();
      ['shipping_fee', 'order_cutoff_minutes', 'max_advance_days', 'payment_ttl_minutes'].forEach((k) => { if (body[k] !== undefined) body[k] = +body[k]; });
      if (body.store_open !== undefined) body.store_open = body.store_open === '1' || body.store_open === true;
      if (body.whatsapp) body.whatsapp = body.whatsapp.replace(/^0/, '62');
      Object.keys(SET).forEach((k) => { if (body[k] !== undefined) SET[k] = body[k]; });
      return send(res, 200, { data: { ...SET, qris_url: null, integrations: { whatsapp_driver: 'log', payment_driver: DRIVER } } });
    }
    if (r === 'settings/test-whatsapp' && m === 'POST') { if (!/^(08|62)[0-9]{8,13}$/.test(body.phone || '')) return send(res, 422, { message: 'Nomor harus berformat 08xxxxxxxxxx atau 62xxxxxxxxxx.', errors: { phone: ['Nomor harus berformat 08xxxxxxxxxx atau 62xxxxxxxxxx.'] } }); WA_LOG.push('tes ' + body.phone); return send(res, 200, { data: { driver: 'log', sent: true }, message: 'Mode uji: pesan hanya dicatat di storage/logs/laravel.log, tidak terkirim sungguhan.' }); }
    if (r === 'users' && m === 'GET') return send(res, 200, { data: Object.values(users).map((x) => ({ ...x, is_active: x.is_active !== false })) });
    if (r === 'users' && m === 'POST') { users[body.username] = { id: Object.keys(users).length + 1, name: body.name, username: body.username, email: body.email, role: body.role, role_label: body.role === 'owner' ? 'Owner' : 'Admin' }; PASS[body.username] = body.password; return send(res, 201, { data: users[body.username] }); }
    mt = /^users\/(\d+)(?:\/(password))?$/.exec(r);
    if (mt) { const t = Object.values(users).find((x) => x.id === +mt[1]); if (!t) return send(res, 404, { message: 'Tidak ditemukan' }); if (mt[2]) { PASS[t.username] = body.password; return send(res, 200, { message: 'ok' }); } if (body.is_active !== undefined) t.is_active = !!body.is_active; return send(res, 200, { data: t }); }
    mt = /^products\/(\d+)$/.exec(r);
    if (mt) { const pr = products.find((x) => x.id === +mt[1]); if (!pr) return send(res, 404, { message: 'Tidak ditemukan' }); if (m === 'DELETE') { products = products.filter((x) => x !== pr); return send(res, 200, { message: 'Produk dihapus.' }); } Object.assign(pr, { name: body.name, category: body.category, short_description: body.short_description, default_note: body.default_note, price: +body.price, daily_quota: +body.daily_quota, is_active: body.is_active === '1' }); return send(res, 200, { data: adminProduct(pr) }); }
    const filt = () => orders.filter((o) => (!q.status || o.status === q.status) && (!q.date || o.delivery_date === q.date) && (!q.search || (o.po_number + o.customer_name + o.whatsapp).toLowerCase().includes(q.search.toLowerCase())));
    if (r === 'orders/export') return send(res, 200, Buffer.from('PK mock'), { 'Content-Type': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition': 'attachment; filename="data-pesanan-mock.xlsx"', 'Access-Control-Expose-Headers': 'X-Server-Time, Content-Disposition' });
    if (r === 'orders' && m === 'GET') { const per = +q.per_page || 10, pg = +q.page || 1; const all = filt().sort((a, b) => (b.status === 'menunggu_konfirmasi') - (a.status === 'menunggu_konfirmasi') || b.created_at - a.created_at); const last = Math.max(1, Math.ceil(all.length / per)); return send(res, 200, { data: all.slice((pg - 1) * per, pg * per).map(row), meta: { total: all.length, per_page: per, current_page: pg, last_page: last, from: all.length ? (pg - 1) * per + 1 : null, to: Math.min(all.length, pg * per), queue_total: orders.filter((o) => ['menunggu_pembayaran', 'menunggu_konfirmasi', 'proses_dapur'].includes(o.status)).length, pending_count: orders.filter((o) => o.status === 'menunggu_konfirmasi').length } }); }
    mt = /^orders\/(\d+)(?:\/(validate|reject|status))?$/.exec(r);
    if (mt) { const o = orders.find((x) => x.id === +mt[1]); if (!o) return send(res, 404, { message: 'Tidak ditemukan' });
      if (mt[2] === 'validate') { o.payment_status = 'lunas'; o.status = 'proses_dapur'; o.payment_method = body.payment_method || 'QRIS'; }
      if (mt[2] === 'reject') { o.payment_status = 'ditolak'; o.status = 'dibatalkan'; o.rejected_reason = body.reason || null; }
      if (mt[2] === 'status') o.status = body.status;
      return send(res, 200, { data: detail(o) }); }
    if (r === 'reports/export' || r === 'reports') {
      if (user.role !== 'owner') return send(res, 403, { message: 'Akses ditolak.' });
      if (r === 'reports/export') return send(res, 200, Buffer.from('PK mock'), { 'Content-Type': 'application/octet-stream', 'Content-Disposition': 'attachment; filename="laporan-mock.xlsx"', 'Access-Control-Expose-Headers': 'X-Server-Time, Content-Disposition' });
      const from = q.from || day(-6), to = q.to || day(0);
      const all = orders.filter((o) => o.payment_status === 'lunas' && o.created_at.toISOString().slice(0, 10) >= from && o.created_at.toISOString().slice(0, 10) <= to).sort((a, b) => b.created_at - a.created_at);
      const per = +q.per_page || 25, pg = +q.page || 1;
      return send(res, 200, { data: { period: { from, to }, summary: { orders: all.length, portions: all.reduce((s, o) => s + portions(o), 0), revenue: all.reduce((s, o) => s + money(o), 0) }, transactions: all.slice((pg - 1) * per, pg * per).map((o) => ({ transaction_id: 'TRX-' + String(o.id).padStart(6, '0'), po_number: o.po_number, ordered_at: iso(o.created_at), customer_name: o.customer_name, whatsapp_masked: mask(o.whatsapp), items_summary: summary(o), portions: portions(o), payment_method: o.payment_method, net_total: money(o), status: 'Lunas' })) }, meta: { total: all.length, per_page: per, current_page: pg, last_page: Math.max(1, Math.ceil(all.length / per)), from: all.length ? (pg - 1) * per + 1 : null, to: Math.min(all.length, pg * per) } });
    }
  }
  send(res, 404, { message: 'Not found' });
});
server.listen(PORT, () => console.log('Mock API aktif di http://localhost:' + PORT + '/api  (demo: owner/admin, sandi demo12345)'));
