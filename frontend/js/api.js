/* Pembungkus fetch untuk memanggil API Laravel. Dipakai semua halaman. */
(function () {
  var TOKEN_KEY = 'acicu_admin_token';

  function ApiError(status, message, errors) {
    this.name = 'ApiError';
    this.status = status;
    this.message = message || 'Terjadi kesalahan.';
    this.errors = errors || {};
  }
  ApiError.prototype = Object.create(Error.prototype);

  function getToken() {
    try { return localStorage.getItem(TOKEN_KEY); } catch (e) { return null; }
  }
  function setToken(token) {
    try { token ? localStorage.setItem(TOKEN_KEY, token) : localStorage.removeItem(TOKEN_KEY); } catch (e) {}
  }

  function buildUrl(path, query) {
    var url = window.ACICU_CONFIG.API_BASE.replace(/\/$/, '') + '/' + path.replace(/^\//, '');
    if (query) {
      var qs = Object.keys(query)
        .filter(function (k) { return query[k] !== undefined && query[k] !== null && query[k] !== ''; })
        .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(query[k]); })
        .join('&');
      if (qs) url += (url.indexOf('?') === -1 ? '?' : '&') + qs;
    }
    return url;
  }

  /**
   * options: { query, json, form (FormData), auth (true = kirim token admin), blob (kembalikan Blob) }
   */
  async function request(method, path, options) {
    options = options || {};
    var headers = { Accept: 'application/json' };
    var body;

    if (options.form) {
      body = options.form;
    } else if (options.json !== undefined) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(options.json);
    }
    if (options.auth) {
      var token = getToken();
      if (token) headers.Authorization = 'Bearer ' + token;
    }

    var res;
    try {
      res = await fetch(buildUrl(path, options.query), { method: method, headers: headers, body: body });
    } catch (e) {
      throw new ApiError(0, 'Tidak dapat terhubung ke server. Pastikan backend sudah berjalan.');
    }

    var st = res.headers.get('X-Server-Time');
    if (st && window.Util && Util.syncClock) Util.syncClock(st);

    if (options.blob) {
      if (!res.ok) throw await toError(res, options);
      return { blob: await res.blob(), filename: filenameFrom(res) };
    }

    var data = null;
    var text = await res.text();
    if (text) { try { data = JSON.parse(text); } catch (e) { data = null; } }

    if (!res.ok) throw makeError(res.status, data, options);
    return data;
  }

  async function toError(res, options) {
    var data = null;
    try { data = JSON.parse(await res.text()); } catch (e) {}
    return makeError(res.status, data, options);
  }

  function makeError(status, data, options) {
    if (status === 401 && options && options.auth) {
      // token kedaluwarsa / dicabut: kembali ke halaman login admin
      setToken(null);
      if (window.AdminShell && typeof window.AdminShell.redirectToLogin === 'function') window.AdminShell.redirectToLogin();
    }
    var message = (data && data.message) || 'Terjadi kesalahan (' + status + ').';
    if (status === 422 && data && data.errors) {
      var first = Object.keys(data.errors)[0];
      if (first) message = data.errors[first][0];
    }
    return new ApiError(status, message, data && data.errors);
  }

  function filenameFrom(res) {
    var cd = res.headers.get('Content-Disposition') || '';
    var m = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(cd);
    return m ? decodeURIComponent(m[1]) : null;
  }

  /** Simpan Blob sebagai unduhan. */
  function saveBlob(blob, filename) {
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = filename || 'unduhan';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 2000);
  }

  window.Api = {
    ApiError: ApiError,
    getToken: getToken,
    setToken: setToken,
    get: function (path, options) { return request('GET', path, options); },
    post: function (path, options) { return request('POST', path, options); },
    put: function (path, options) { return request('PUT', path, options); },
    patch: function (path, options) { return request('PATCH', path, options); },
    del: function (path, options) { return request('DELETE', path, options); },
    saveBlob: saveBlob,
  };
})();
