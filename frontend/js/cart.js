/*
 * Keranjang belanja pelanggan (disimpan di localStorage browser).
 * Harga di sini hanya untuk tampilan: total sebenarnya selalu dihitung ulang oleh backend.
 */
(function () {
  var KEY = 'acicu_cart_v1';

  function read() {
    try {
      var data = JSON.parse(localStorage.getItem(KEY) || '[]');
      return Array.isArray(data) ? data : [];
    } catch (e) { return []; }
  }

  function write(items) {
    try { localStorage.setItem(KEY, JSON.stringify(items)); } catch (e) {}
    window.dispatchEvent(new CustomEvent('cart:change'));
  }

  var Cart = {
    items: function () { return read(); },

    count: function () {
      return read().reduce(function (sum, i) { return sum + i.qty; }, 0);
    },

    subtotal: function () {
      return read().reduce(function (sum, i) { return sum + i.price * i.qty; }, 0);
    },

    /** product: objek dari GET /api/products */
    add: function (product, qty) {
      qty = qty || 1;
      var items = read();
      var found = items.find(function (i) { return i.product_id === product.id; });
      if (found) {
        found.qty = Math.min(50, found.qty + qty);
        found.price = product.price;
      } else {
        items.push({
          product_id: product.id,
          name: product.name,
          short_name: product.short_name,
          price: product.price,
          image_url: product.image_url,
          default_note: product.default_note || '',
          qty: qty,
        });
      }
      write(items);
    },

    setQty: function (productId, qty) {
      var items = read();
      var found = items.find(function (i) { return i.product_id === productId; });
      if (!found) return;
      found.qty = Math.max(1, Math.min(50, qty));
      write(items);
    },

    remove: function (productId) {
      write(read().filter(function (i) { return i.product_id !== productId; }));
    },

    clear: function () { write([]); },
  };

  window.Cart = Cart;
})();
