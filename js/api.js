/* ============================================================
   api.js — the single data-access gateway.

   Every call to the PHP backend goes through request() below, which
   handles: same-origin cookies, the CSRF token, the {ok, data} /
   {ok, error, code} envelope, and turning a failure into an Error
   carrying .message, .code and .fields so the existing inline form
   error UI can display it unchanged.

   Pages never fetch() directly, and never talk to MySQL or to the
   Python AI service — they call these functions, and PHP does the rest.

   db.js is gone: every page loads seed.js, api.js, common.js and then its
   own script. The localStorage "database" it used to provide has been
   replaced table by table — cart_items, products, orders, sub_orders,
   users, sellers, faqs, settings and admin_actions are all MySQL now.
   ============================================================ */

const API = (() => {
  const BASE_URL = ''; // e.g. 'https://api.consult.lk/v1'
  const LATENCY = 420; // simulated network delay so loading states are visible

  /* ============================================================
     THE PHP BACKEND
     ============================================================ */

  /* Derived from the current page, not hard-coded, so the site works at
     http://localhost/consult-project/ and at any other sub-path without
     editing this file. Every page lives in the project root, so the API
     is always './api/' relative to it. */
  const API_BASE = (() => {
    const path = location.pathname;
    return path.slice(0, path.lastIndexOf('/') + 1) + 'api/';
  })();

  /* An API failure is an Error, so `catch (err)` in page code works as
     usual, but it also carries what the UI needs:
       .code   VALIDATION | AUTH | FORBIDDEN | NOT_FOUND | SERVER | NETWORK
       .fields {email: 'Enter a valid email address', ...} on VALIDATION
       .status the HTTP status */
  class ApiError extends Error {
    constructor(message, code, fields, status){
      super(message);
      this.name = 'ApiError';
      this.code = code || 'SERVER';
      this.fields = fields || null;
      this.status = status || 0;
    }
  }

  let _csrf = null;

  /* The CSRF token is minted by PHP against the session cookie. It's
     cached here because /api/auth/me.php hands it over on page load, so
     in practice this extra round trip rarely happens. */
  function csrfToken(){
    if (_csrf) return Promise.resolve(_csrf);
    return request('auth/csrf.php').then(d => (_csrf = d.csrf_token));
  }
  function setCsrf(token){ _csrf = token || null; }

  /* Called when the server says the session is gone. common.js registers
     the handler that clears the local snapshot and goes to login.html. */
  let _unauthorizedHandler = null;
  function onUnauthorized(fn){ _unauthorizedHandler = fn; }

  function parseEnvelope(res, opts){
    return res.text().then(text => {
      let payload = null;
      try { payload = text ? JSON.parse(text) : null; } catch (e){}

      // Not our envelope at all: a PHP error page, an Apache 404, an
      // .htaccess redirect. Say something a person can act on rather
      // than dumping HTML into the console.
      if (!payload || typeof payload.ok !== 'boolean'){
        throw new ApiError(
          res.status === 404
            ? 'That API endpoint is missing. Is the site being served by Apache from htdocs?'
            : 'The server sent back something unexpected (HTTP ' + res.status + '). Check logs/app.log.',
          'SERVER', null, res.status
        );
      }

      if (payload.ok) return payload.data;

      const err = new ApiError(payload.error, payload.code, payload.fields, res.status);
      // A real 401 means the PHP session ended under us.
      if (err.status === 401 && !opts.silent401 && _unauthorizedHandler){
        _unauthorizedHandler(err);
      }
      throw err;
    });
  }

  /**
   * One request to the PHP API.
   *   request('auth/me.php')
   *   request('auth/login.php', { method: 'POST', body: { email, password } })
   *
   * opts.silent401  don't fire the "session ended" handler (login screens)
   * opts.timeout    ms, default 15000
   */
  function request(path, opts){
    opts = opts || {};
    const method = (opts.method || 'GET').toUpperCase();
    const mutating = method !== 'GET' && method !== 'HEAD';

    const send = token => {
      const headers = { 'Accept': 'application/json' };
      let body;
      if (opts.body !== undefined){
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(opts.body);
      }
      if (token) headers['X-CSRF-Token'] = token;

      return fetch(API_BASE + path, {
        method: method,
        headers: headers,
        body: body,
        // Send the session cookie. Without this the PHP session is a new
        // empty one on every call and nobody is ever logged in.
        credentials: 'include',
        signal: AbortSignal.timeout(opts.timeout || 15000)
      }).catch(() => {
        throw new ApiError(
          'Could not reach the server. Is Apache running in XAMPP?',
          'NETWORK', null, 0
        );
      }).then(res => parseEnvelope(res, opts));
    };

    return (mutating ? csrfToken() : Promise.resolve(null))
      .then(send)
      .catch(err => {
        // A stale token (PHP restarted, session rotated) is worth exactly
        // one silent retry with a fresh one. Any second failure is real.
        if (mutating && err instanceof ApiError && err.status === 403
            && err.code === 'FORBIDDEN' && !opts._retried){
          _csrf = null;
          return request(path, Object.assign({}, opts, { _retried: true }));
        }
        throw err;
      });
  }

  /**
   * One multipart upload. Separate from request() because a file goes up as
   * FormData, not JSON: the browser has to set its own Content-Type with the
   * multipart boundary, so this must NOT set that header itself.
   *
   * Everything else is the same — session cookie, CSRF token, envelope.
   *   upload('seller/upload.php', fileInput.files[0], { kind: 'product' })
   */
  function upload(path, file, fields, opts){
    opts = opts || {};
    if (!file) return Promise.reject(new ApiError('No file was chosen.', 'VALIDATION', null, 0));

    return csrfToken().then(token => {
      const form = new FormData();
      form.append('file', file);
      Object.keys(fields || {}).forEach(k => form.append(k, fields[k]));

      return fetch(API_BASE + path, {
        method: 'POST',
        headers: { 'Accept': 'application/json', 'X-CSRF-Token': token },
        body: form,
        credentials: 'include',
        // Uploads are slower than JSON calls, so they get a longer leash.
        signal: AbortSignal.timeout(opts.timeout || 45000)
      }).catch(() => {
        throw new ApiError('Could not reach the server. Is Apache running in XAMPP?', 'NETWORK', null, 0);
      }).then(res => parseEnvelope(res, opts));
    });
  }

  /* ---------- auth endpoints (Phase 2) ---------- */
  const Auth = {
    /** Who is signed in, plus a fresh CSRF token. Never 401s. */
    me: () => request('auth/me.php'),

    /** {user, csrf_token}. Throws ApiError(code 'AUTH') on bad credentials. */
    login: (email, password) => request('auth/login.php', {
      method: 'POST', body: { email: email, password: password }, silent401: true
    }),

    /** payload: {name, email, phone, password, accept_terms} */
    register: payload => request('auth/register.php', {
      method: 'POST', body: payload, silent401: true
    }),

    logout: () => request('auth/logout.php', { method: 'POST', silent401: true }),

    updateProfile: payload => request('auth/update_profile.php', {
      method: 'POST', body: payload
    }),

    changePassword: (currentPassword, newPassword) => request('auth/change_password.php', {
      method: 'POST',
      body: { current_password: currentPassword, new_password: newPassword }
    }),

    /* The signed-in user's own profile photo, whatever their role — one
       endpoint for customers, sellers and admins, so there is one place
       where an upload is validated and the old file cleaned up. */
    uploadAvatar: file => upload('auth/avatar.php', file),
    removeAvatar: ()   => request('auth/avatar.php', { method: 'POST', body: { remove: true } })
  };

  /* ---------- querystring from an object, skipping empties ---------- */
  function qs(params){
    if (!params) return '';
    const parts = Object.keys(params)
      .filter(k => params[k] !== undefined && params[k] !== null && params[k] !== '')
      .map(k => encodeURIComponent(k) + '=' + encodeURIComponent(params[k]));
    return parts.length ? '?' + parts.join('&') : '';
  }

  /* ---------- catalog, cart, wishlist, reviews (Phase 3) ---------- */
  const Catalog = {
    /** Raw paged response: {items, total, page, per_page, pages}. */
    list:   params => request('products/list.php' + qs(params)),
    detail: id     => request('products/detail.php?id=' + encodeURIComponent(id)),

    /**
     * Several products by public id, in the order asked for.
     * {items, missing, total} — `missing` are ids that are not buyable now,
     * which is how a stale AI catalogue makes itself known.
     */
    byIds: ids => request('products/by_ids.php?ids=' +
      encodeURIComponent((Array.isArray(ids) ? ids : [ids]).join(',')))
  };

  const Cart = {
    get:    ()            => request('cart/get.php'),
    add:    (id, qty)     => request('cart/add.php',    { method: 'POST', body: { product_id: id, qty: qty || 1 } }),
    update: (id, qty)     => request('cart/update.php', { method: 'POST', body: { product_id: id, qty: qty } }),
    remove: id            => request('cart/remove.php', { method: 'POST', body: { product_id: id } }),
    clear:  ()            => request('cart/clear.php',  { method: 'POST' }),
    /** items: [{product_id, qty}] — folds a guest cart into the account's. */
    merge:  items         => request('cart/merge.php',  { method: 'POST', body: { items: items } })
  };

  const Wishlist = {
    ids:    ()  => request('wishlist/list.php?ids_only=1'),
    list:   ()  => request('wishlist/list.php'),
    toggle: id  => request('wishlist/toggle.php', { method: 'POST', body: { product_id: id } })
  };

  const Reviews = {
    list:   (id, params) => request('reviews/list.php' + qs(Object.assign({ product: id }, params || {}))),
    create: (id, rating, text) => request('reviews/create.php', {
      method: 'POST', body: { product_id: id, rating: rating, text: text || '' }
    })
  };

  /* ---------- low-level AJAX ---------- */

  // Real XMLHttpRequest — classic AJAX, kept because some hosts
  // still proxy fetch oddly and this is dependency-free.
  function xhrGet(url) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('GET', url, true);
      xhr.onload = () => {
        if (xhr.status >= 200 && xhr.status < 300) {
          try { resolve(JSON.parse(xhr.responseText)); }
          catch (e) { reject(new Error('Bad JSON from ' + url)); }
        } else {
          reject(new Error('HTTP ' + xhr.status));
        }
      };
      xhr.onerror = () => reject(new Error('Network error'));
      xhr.send();
    });
  }

  function post(url, body) {
    return fetch(BASE_URL + url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    }).then(r => {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    });
  }

  /* ============================================================
     CATALOG

     The products now come from MySQL through api/products/*.php.
     data/products.json survives as a READ-ONLY fallback: if Apache
     or MySQL is down mid-demo the storefront still shows something
     rather than a blank grid. Writes never fall back — a cart add or
     a review that could not reach the server fails loudly instead of
     pretending to have worked.

     Nothing here reads localStorage any more. Seller-created listings
     used to be merged in from db.js; they come from the products
     table now, and a pending listing is correctly invisible until an
     admin approves it.
     ============================================================ */

  const delay = v => new Promise(res => setTimeout(() => res(v), LATENCY));

  /* ---------- the fallback, used only when the API is unreachable ---------- */
  let _fallback = null;

  function loadFallbackCatalog(){
    if (_fallback) return Promise.resolve(_fallback);
    return xhrGet('data/products.json')
      .catch(() => {
        // file:// or offline — the seed baked into seed.js is the last resort
        if (window.SEED_DATA) return window.SEED_DATA;
        throw new ApiError('The catalogue is unavailable.', 'SERVER', null, 0);
      })
      .then(data => {
        const products = data.products.map(p => Object.assign({}, p, {
          // Match what PHP returns: a nested seller object is always present
          // (the detail page reads p.seller.name unguarded), and images[] is
          // always an array.
          seller: data.sellers.find(s => s.id === p.sellerId)
            || { id: p.sellerId, name: 'Unknown seller', city: '', rating: 0, verified: false },
          images: (Array.isArray(p.images) && p.images.length) ? p.images : (p.image ? [p.image] : []),
          specs: p.specs || {},
          onSale: false
        }));
        _fallback = { products: products, sellers: data.sellers };
        return _fallback;
      });
  }

  function warnFallback(err){
    console.warn(
      '[catalog] api/products/list.php unreachable (' + err.message + ') — ' +
      'showing data/products.json instead. Prices and stock may be out of date. ' +
      'Check that Apache and MySQL are started in XAMPP.'
    );
  }

  /* ---------- the whole active catalogue ----------
     products.html filters, sorts and counts client-side over the full list,
     so this pages through everything rather than returning one page. The
     endpoint supports server-side filters too (see Catalog.list) — pass
     params here and they go straight through. */
  let _catalogCache = null;

  function getProducts(params){
    if (!params && _catalogCache) return Promise.resolve(_catalogCache);

    const page1 = Object.assign({ per_page: 100, page: 1 }, params || {});

    return Catalog.list(page1)
      .then(first => {
        const pages = first.pages || 1;
        if (pages <= 1) return first.items;

        // More than 100 products: pull the rest in parallel.
        const rest = [];
        for (let p = 2; p <= Math.min(pages, 20); p++){
          rest.push(Catalog.list(Object.assign({}, page1, { page: p })).then(r => r.items));
        }
        return Promise.all(rest).then(chunks => first.items.concat.apply(first.items, chunks));
      })
      .then(items => {
        if (!params) _catalogCache = items;
        return items;
      })
      .catch(err => {
        warnFallback(err);
        return loadFallbackCatalog().then(d => d.products);
      });
  }

  /* Call after anything that changes the catalogue (a review, a seller
     edit) so the next read is fresh instead of the page-load snapshot. */
  function invalidateCatalog(){
    _catalogCache = null;
  }

  /** One product, with specs, gallery, reviews and the sale price applied. */
  function getProduct(id){
    return Catalog.detail(id)
      .then(d => d.product)
      .catch(err => {
        // A genuinely missing product resolves undefined: the detail page
        // already renders its own "Product not found" for that.
        if (err instanceof ApiError && err.code === 'NOT_FOUND') return undefined;
        warnFallback(err);
        return loadFallbackCatalog().then(d => d.products.find(p => p.id === id));
      });
  }

  /** The six cards the home page leads with: badged, then best rated. */
  function getTrending(){
    return getProducts().then(list => list.slice(0, 6));
  }

  /* ---------- the AI consult endpoint ----------
     Three paths, in order:

       1. api/ai/consult.php — a PHP proxy that forwards to the Python AI
          service (RAG over the catalogue via Ollama).
       2. If the proxy says the service is unreachable, consultMock's local
          keyword matching, so the chat still answers.
       3. If even that fails, an honest error bubble.

     The browser no longer talks to port 8001 itself. It goes through PHP,
     which means: one origin (so the Python service can stop allowing
     `*`), the port is not published in shipped JS, the request is
     validated once server-side, and "the service is off" arrives as a
     normal {ok:false} envelope instead of a console network error.

     RE-PRICING. The AI answers out of data/products.json — a snapshot it
     embedded at startup — so its product objects can be stale. The proxy
     therefore strips them and returns only `product_ids`; the ids are
     re-read here from api/products/by_ids.php, so a card always shows what
     MySQL says it costs and whether it is in stock. The AI picks WHICH
     products; the database decides the price. An id it recommends that is
     no longer buyable simply does not come back and the card disappears.

     (api/tools/export_products_json.php rebuilds the snapshot from MySQL;
     api/ai/health.php reports when the two have drifted.)
  ------------------------------------------------ */

  /* ---------- online/offline indicator ----------
     Wired to .consult-status-dot next to the "Consult AI" chat header.
     Checked once eagerly on page load (not before every message — that'd
     add a round trip to every send), then kept in sync by whatever the
     next real consult() attempt finds. No refresh needed either way:
     consult() always tries the real service fresh on every call, so
     recovery after restarting the Python server is automatic. */
  let aiServiceOnline = false;
  let aiSnapshotNote = null;

  function updateServiceStatusIndicator(){
    const dot = document.querySelector('.consult-status-dot');
    if (!dot) return;
    dot.classList.toggle('offline', !aiServiceOnline);
    dot.title = aiServiceOnline
      ? (aiSnapshotNote || 'AI service connected')
      : 'AI service offline — using fallback answers. Reconnects automatically once the Python server is reachable again, no refresh needed.';
  }

  /** Ask OUR server whether the AI service is up, rather than poking 8001. */
  function checkAIServiceHealth(){
    return request('ai/health.php', { timeout: 8000 })
      .then(d => {
        aiServiceOnline = !!d.online;

        /* The snapshot drifting is the one failure here that is silent: the
           AI keeps answering, just about a catalogue that no longer
           matches. Surfaced on the status dot's tooltip and in the console
           so it is discoverable rather than mysterious. */
        if (d.snapshot && d.snapshot.in_sync === false){
          aiSnapshotNote = 'AI connected, but its catalogue snapshot is out of date (' +
            d.snapshot.products_in_file + ' products vs ' + d.snapshot.products_in_db + ' live).';
          console.warn('[AI] ' + aiSnapshotNote + ' Regenerate with: ' + d.snapshot.regenerate_with);
        } else {
          aiSnapshotNote = null;
        }
      })
      .catch(() => { aiServiceOnline = false; })
      .then(updateServiceStatusIndicator);
  }
  if (typeof document !== 'undefined'){
    document.addEventListener('DOMContentLoaded', checkAIServiceHealth);
  }

  /**
   * Ask the consultant.
   *
   * Resolves the same {type, message, products?, chips?, context} shape the
   * chat has always rendered, whichever path answered — so nothing
   * downstream has to know.
   */
  function consult(message, context){
    return request('ai/consult.php', {
      method: 'POST',
      body: { message: message, context: context || {} },
      /* Deliberately LONGER than the proxy's own wait (ai_service_timeout
         in api/config.php, 45s). If the browser gave up first, the shopper
         would see a bare network error instead of the proxy's "taking too
         long" message — the outer bound is only here in case PHP itself
         hangs, which is a different failure. */
      timeout: 55000
    })
      .then(answer => {
        aiServiceOnline = true;
        updateServiceStatusIndicator();
        console.log('[AI service]', message);

        const ids = answer.product_ids || [];
        if (!ids.length){
          // A question or an empty result: no cards to re-price.
          return Object.assign({}, answer, { products: [] });
        }

        /* Re-hydrate from MySQL. If THIS fails the answer is still worth
           showing — the text is the useful part — so the cards are dropped
           rather than the whole reply. */
        return Catalog.byIds(ids)
          .then(d => {
            if (d.missing && d.missing.length){
              console.warn(
                '[AI] recommended ' + d.missing.join(', ') + ' but ' +
                (d.missing.length === 1 ? 'it is' : 'they are') +
                ' not buyable now. The AI catalogue snapshot is probably stale — ' +
                'run php api/tools/export_products_json.php and restart the AI service.'
              );
            }
            return Object.assign({}, answer, { products: d.items });
          })
          .catch(err => {
            console.warn('[AI] could not re-price the recommended products —', err.message);
            return Object.assign({}, answer, { products: [] });
          });
      })
      .catch(err => {
        aiServiceOnline = false;
        updateServiceStatusIndicator();

        /* The proxy sets .fallback on exactly the failures where a local
           answer is better than an error: service down, timed out, or a bad
           reply. A validation failure (message too long, say) is NOT one of
           those — the shopper should see what was wrong with it. */
        if (err instanceof ApiError && err.code === 'VALIDATION') throw err;

        console.warn('[mock fallback]', message, '—', err.message);
        console.warn(
          'The PHP proxy at api/ai/consult.php could not reach the Python service. ' +
          'Start it with: cd ai_service && venv\\Scripts\\activate && python -m uvicorn main:app --port 8001 ' +
          '(the port lives in api/config.php as ai_service_url, not in this file any more).'
        );
        return consultMock(message, context);
      });
  }

  function consultMock(message, context) {
    if (BASE_URL) return post('/consult', { message, context });

    // Reads the same catalogue the rest of the site does (MySQL first,
    // data/products.json if that is unreachable), so even the offline
    // fallback answers about real products at real prices.
    return getProducts().then(products => {
      const t = message.toLowerCase();
      const ctx = Object.assign({}, context);

      const budget = (() => {
        const m = t.replace(/[, ]/g, '').match(/(\d{5,7})/);
        return m ? parseInt(m[1], 10) : ctx.budget || null;
      })();
      if (budget) ctx.budget = budget;

      const has = (...w) => w.some(x => t.includes(x));

      let category = null;
      if (has('laptop', 'programming', 'coding', 'notebook')) category = 'laptop';
      else if (has('phone', 'mobile', 'smartphone')) category = 'phone';
      else if (has('fridge', 'refrigerator', 'appliance')) category = 'appliance';
      else if (has('headphone', 'earphone', 'audio')) category = 'audio';
      else if (has('camera', 'vlog', 'filming')) category = 'camera';
      if (category) ctx.category = category;
      else category = ctx.category;

      if (has('gaming', 'game')) ctx.intent = 'gaming';

      // Underspecified → ask, don't guess. This is the whole product.
      if (has('gift', 'birthday', 'present') && !ctx.giftBudget) {
        return delay({
          type: 'question',
          message: "Happy to help with a gift — two quick things so I'm not guessing: what's your rough budget, and does she lean more toward tech, audio, or something for the home?",
          chips: ['Around 15,000 — audio', 'Around 15,000 — tech', 'Under 50,000 — surprise me'],
          context: Object.assign(ctx, { giftBudget: true })
        });
      }

      if (!category) {
        return delay({
          type: 'question',
          message: "Tell me a bit more — what are you shopping for, and what matters most: budget, brand, or how long you need it to last?",
          chips: ['A laptop for programming', 'Best phone under 150,000', 'A fridge for a family of five'],
          context: ctx
        });
      }

      let picks = products.filter(p => p.category === category);

      if (ctx.intent === 'gaming') {
        picks.sort((a, b) => (b.tags.includes('gaming') ? 1 : 0) - (a.tags.includes('gaming') ? 1 : 0));
      }
      if (ctx.budget) {
        const within = picks.filter(p => p.price <= ctx.budget * 1.05);
        if (within.length) picks = within;
      }
      picks = picks.slice(0, 2);

      if (!picks.length) {
        return delay({
          type: 'empty',
          message: "Nothing in the catalogue genuinely fits that yet — I'd rather say so than push you toward something that doesn't. Want to widen the budget or try a different category?",
          chips: ['Show me anything close', 'Try a different category'],
          context: ctx
        });
      }

      const lead = ctx.budget
        ? `Working with about ${fmt(ctx.budget)} — here's what actually fits:`
        : `Here's what I'd put in front of you:`;

      return delay({ type: 'recommendation', message: lead, products: picks, context: ctx });
    });
  }

  /* ---------- orders (Phase 4) ----------
     All of this is the server's now. The browser does not compute a total,
     does not split the cart into sub-orders and does not invent an order id:
     it posts the delivery address, and api/orders/checkout.php re-prices
     every line from MySQL inside one transaction. The simulated local
     placeOrder() that used to live here is gone, along with the
     localStorage order history it fed.
  ------------------------------------------------ */

  const Orders = {
    /**
     * Place the order. The ONLY thing sent is the delivery address —
     * prices, quantities and totals are read from the database server-side,
     * so nothing the browser could tamper with affects what is charged.
     * Resolves the created order (one order, one payment, one sub-order per
     * seller). Throws ApiError with .fields on a bad address.
     */
    checkout: shipping => request('orders/checkout.php', {
      method: 'POST', body: shipping
    }).then(d => d.order),

    /** Paged history: {items, total, page, per_page, pages}. */
    list: params => request('orders/list.php' + qs(params)),

    /** One order with its sub-orders, items and status timeline. */
    detail: id => request('orders/detail.php?id=' + encodeURIComponent(id))
      .then(d => d.order),

    /**
     * The customer confirms one seller's shipment arrived, which is what
     * releases that sub-order's payment out of escrow. Resolves the whole
     * refreshed order so the page can repaint from one response.
     */
    confirmDelivery: subOrderId => request('orders/confirm_delivery.php', {
      method: 'POST', body: { sub_order_id: subOrderId }
    })
  };

  /* ---------- the seller backend (Phase 5) ----------
     Everything the seller dashboard reads and writes. The dashboard used to
     go through db.js (localStorage); these are the real endpoints, and every
     one of them is scoped server-side to the signed-in seller's own rows.
  ------------------------------------------------ */

  const Seller = {
    /** A seller application. Creates a PENDING seller; does not sign in. */
    register: payload => request('seller/register.php', {
      method: 'POST', body: payload, silent401: true
    }),

    /**
     * Everything the dashboard paints itself from, in one request:
     * {seller, stats, series, payouts, products, orders, settings}.
     * `days` picks the length of the chart series (7–90).
     */
    dashboard: days => request('seller/dashboard.php' + qs(days ? { days: days } : null)),

    /* ---- listings ---- */
    saveProduct:   payload => request('seller/product_save.php',   { method: 'POST', body: payload }),
    deleteProduct: id      => request('seller/product_delete.php', { method: 'POST', body: { id: id } }),
    toggleProduct: id      => request('seller/product_toggle.php', { method: 'POST', body: { id: id } }),

    /* ---- inventory: absolute, relative, or bulk ---- */
    setStock:    (id, stock)  => request('seller/stock.php', { method: 'POST', body: { id: id, stock: stock } }),
    adjustStock: (id, delta)  => request('seller/stock.php', { method: 'POST', body: { id: id, delta: delta } }),
    bulkStock:   (ids, stock) => request('seller/stock.php', { method: 'POST', body: { ids: ids, stock: stock } }),

    /* ---- scheduled sale pricing ---- */
    scheduleSale: (id, salePrice, startDate, endDate) => request('seller/sale.php', {
      method: 'POST',
      body: { id: id, sale_price: salePrice, start_date: startDate, end_date: endDate }
    }),
    clearSale: id => request('seller/sale.php', { method: 'POST', body: { id: id, clear: true } }),

    /** Advance one of the seller's own shipments. 'delivered' is refused. */
    setOrderStatus: (subOrderId, status, extra) => request('seller/order_status.php', {
      method: 'POST',
      body: Object.assign({ sub_order_id: subOrderId, status: status }, extra || {})
    }),

    /* ---- profile ---- */
    saveProfile: payload => request('seller/profile.php', { method: 'POST', body: payload }),
    // Profile photos are role-agnostic, so they go through Auth — see
    // api/auth/avatar.php. Kept here as an alias so the dashboard reads
    // the same either way.
    removeAvatar: () => Auth.removeAvatar(),

    /** A product image. Returns {url} to attach when the form is saved. */
    uploadImage: file => upload('seller/upload.php', file, { kind: 'product' })
  };

  /* ---------- the admin backend (Phase 6) ----------
     The web-master console. Every one of these is gated server-side by
     require_admin(), and every write lands in the admin_actions audit
     trail in the same transaction as the change it describes.
  ------------------------------------------------ */

  const Admin = {
    /**
     * Everything the console paints itself from, in one request:
     * {admin, stats, series, sellers, users, products, orders, faqs,
     *  messages, by_seller, settings, actions}.
     */
    dashboard: days => request('admin/dashboard.php' + qs(days ? { days: days } : null)),

    /* ---- seller verification ---- */
    approveSeller: id           => request('admin/seller_verify.php', { method: 'POST', body: { seller_id: id, action: 'approve' } }),
    rejectSeller:  (id, reason) => request('admin/seller_verify.php', { method: 'POST', body: { seller_id: id, action: 'reject', reason: reason } }),
    /** rate = null resets the seller to the platform default. */
    setCommission: (id, rate)   => request('admin/seller_verify.php', { method: 'POST', body: { seller_id: id, action: 'commission', rate: rate } }),

    /* ---- product moderation. reject and takedown both REQUIRE a reason,
            because the seller is shown it and corrects from it. ---- */
    approveProduct:  id           => request('admin/product_moderate.php', { method: 'POST', body: { id: id, action: 'approve' } }),
    rejectProduct:   (id, reason) => request('admin/product_moderate.php', { method: 'POST', body: { id: id, action: 'reject', reason: reason } }),
    takedownProduct: (id, reason) => request('admin/product_moderate.php', { method: 'POST', body: { id: id, action: 'takedown', reason: reason } }),

    /* ---- users ---- */
    suspendUser:    (id, reason) => request('admin/user_status.php', { method: 'POST', body: { user_id: id, action: 'suspend', reason: reason } }),
    reactivateUser: id           => request('admin/user_status.php', { method: 'POST', body: { user_id: id, action: 'reactivate' } }),

    /* ---- orders and disputes ---- */
    overrideStatus: (subOrderId, status, note) => request('admin/order_action.php', {
      method: 'POST', body: { sub_order_id: subOrderId, action: 'override', status: status, note: note || '' }
    }),
    flagDispute: (subOrderId, reason) => request('admin/order_action.php', {
      method: 'POST', body: { sub_order_id: subOrderId, action: 'dispute', reason: reason }
    }),
    refundOrder: (subOrderId, reason) => request('admin/order_action.php', {
      method: 'POST', body: { sub_order_id: subOrderId, action: 'refund', reason: reason }
    }),
    /** Close a dispute in the seller's favour — releases the escrow. */
    resolveForSeller: (subOrderId, note) => request('admin/order_action.php', {
      method: 'POST', body: { sub_order_id: subOrderId, action: 'resolve', note: note || '' }
    }),

    /* ---- finance ---- */
    finance: params => request('admin/finance.php' + qs(params)),
    /** The CSV download URL — handed to the browser, not fetched. */
    financeCsvUrl: params => API_BASE + 'admin/finance.php' +
      qs(Object.assign({ format: 'csv' }, params || {})),

    /* ---- FAQs ---- */
    saveFaq:   payload => request('admin/faqs.php', { method: 'POST', body: Object.assign({ action: 'save' }, payload) }),
    deleteFaq: id      => request('admin/faqs.php', { method: 'POST', body: { action: 'delete', id: id } }),

    /** Any subset of {commission_rate, categories, seller_signups_open,
        customer_signups_open}; absent keys are left alone. */
    saveSettings: patch => request('admin/settings.php', { method: 'POST', body: patch })
  };

  /* ---------- the public pages (Phase 7) ----------
     No auth: the FAQ and the contact form are what a visitor uses before
     they have an account.
  ------------------------------------------------ */

  const Faqs = {
    /** {items, groups, total} — grouped by category, in the admin's order. */
    list: category => request('faqs.php' + qs(category ? { category: category } : null))
  };

  const Contact = {
    /**
     * Called when the form renders. Stamps the SESSION with the time, which
     * is what lets the server reject a submission that arrived impossibly
     * fast — a timestamp in the form itself would just be forged.
     * Also returns a prefill for somebody already signed in.
     */
    open: () => request('contact_open.php'),

    /**
     * Send a message. `website` is the honeypot: the form renders it
     * hidden, so a person leaves it empty and a bot fills it.
     */
    submit: payload => request('contact_submit.php', { method: 'POST', body: payload })
  };

  function fmt(n) {
    return 'LKR ' + n.toLocaleString('en-LK');
  }

  return {
    // what the pages call — same signatures as before, MySQL-backed now
    getProducts, getProduct, getTrending, invalidateCatalog, fmt,
    // the AI chat is still direct to Python: Phase 7 puts a PHP proxy here
    consult,
    // the PHP backend
    request, qs, API_BASE, ApiError, setCsrf, csrfToken, onUnauthorized,
    Auth, Catalog, Cart, Wishlist, Reviews, Orders, Seller, Admin, Faqs, Contact, upload
  };
})();
