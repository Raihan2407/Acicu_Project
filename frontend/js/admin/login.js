(function () {
  var form = document.getElementById('loginForm');
  var err = document.getElementById('loginError');
  var btn = document.getElementById('loginBtn');

  // sudah login? langsung ke dashboard
  if (Api.getToken()) {
    Api.get('admin/me', { auth: true }).then(function () { location.replace('index.html'); }).catch(function () {});
  }

  document.getElementById('togglePw').addEventListener('click', function () {
    var pw = document.getElementById('password');
    pw.type = pw.type === 'password' ? 'text' : 'password';
  });

  document.getElementById('forgot').addEventListener('click', function () {
    err.textContent = 'Lupa password? Hubungi Owner ACICU untuk mengatur ulang kata sandi Anda.';
    err.classList.remove('hidden');
  });

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    err.classList.add('hidden');
    var login = form.login.value.trim(), password = form.password.value;
    if (!login || !password) {
      err.textContent = 'Isi username/email dan kata sandi.';
      err.classList.remove('hidden');
      return;
    }
    btn.disabled = true; btn.textContent = 'Memeriksa…';
    try {
      var res = await Api.post('admin/login', { json: { login: login, password: password, remember: document.getElementById('remember').checked } });
      Api.setToken(res.data.token);
      location.replace('index.html');
    } catch (ex) {
      err.textContent = ex.status === 429 ? 'Terlalu banyak percobaan. Coba lagi sebentar lagi.' : ex.message;
      err.classList.remove('hidden');
      btn.disabled = false; btn.textContent = 'Masuk';
    }
  });
})();
