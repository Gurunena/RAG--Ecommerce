/* ============================================================
   common.js — shared across every page of the site.
   Session, cart, nav, toast, validation, order persistence,
   and the go() compatibility shim that turns route keywords
   into real file navigations. Load order on every page:
   seed.js, api.js, common.js, then the page's own script.
   (The dashboards add dash-common.js before their own script.)
   ============================================================ */

const $  = s => document.querySelector(s);
const $$ = s => [...document.querySelectorAll(s)];
const fmt = n => API.fmt(n);

/* ---------- icon set (inline SVG, tinted by CSS vars) ---------- */
const ICONS = {
  laptop: (s=54) => `<svg width="${s}" height="${s}" viewBox="0 0 24 24" fill="none">
    <rect x="3" y="4.5" width="18" height="12" rx="1.6" stroke="#1B2A4A" stroke-width="1.3"/>
    <path d="M1.5 19.5h21" stroke="#8B2E3C" stroke-width="1.6" stroke-linecap="round"/></svg>`,
  phone: (s=54) => `<svg width="${s*0.62}" height="${s}" viewBox="0 0 16 24" fill="none">
    <rect x="1" y="1" width="14" height="22" rx="2.6" stroke="#1B2A4A" stroke-width="1.3"/>
    <circle cx="8" cy="19.6" r="0.9" fill="#8B2E3C"/></svg>`,
  headphones: (s=54) => `<svg width="${s}" height="${s}" viewBox="0 0 24 24" fill="none">
    <path d="M4 15v-3a8 8 0 0116 0v3" stroke="#1B2A4A" stroke-width="1.3"/>
    <rect x="2.5" y="14.5" width="4" height="6" rx="1.6" stroke="#8B2E3C" stroke-width="1.3"/>
    <rect x="17.5" y="14.5" width="4" height="6" rx="1.6" stroke="#8B2E3C" stroke-width="1.3"/></svg>`,
  fridge: (s=54) => `<svg width="${s*0.8}" height="${s}" viewBox="0 0 20 26" fill="none">
    <rect x="2" y="1.5" width="16" height="23" rx="2" stroke="#1B2A4A" stroke-width="1.3"/>
    <path d="M2 10h16" stroke="#8B2E3C" stroke-width="1.3"/>
    <path d="M5.5 5.5v2.5M5.5 13v3" stroke="#8B2E3C" stroke-width="1.3" stroke-linecap="round"/></svg>`,
  camera: (s=54) => `<svg width="${s}" height="${s*0.78}" viewBox="0 0 26 20" fill="none">
    <rect x="1" y="4" width="24" height="15" rx="2.2" stroke="#1B2A4A" stroke-width="1.3"/>
    <circle cx="13" cy="11.5" r="4.2" stroke="#8B2E3C" stroke-width="1.3"/>
    <path d="M8 4l1.6-2.5h6.8L18 4" stroke="#1B2A4A" stroke-width="1.3"/></svg>`
};
const icon = (k,s) => (ICONS[k] || ICONS.laptop)(s);

function escapeHtml(s){
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

/* ---------- toast ---------- */
let toastT;
function toast(msg){
  const t = $('#toast');
  if (!t) return;
  t.textContent = msg;
  t.classList.add('show');
  clearTimeout(toastT);
  toastT = setTimeout(() => t.classList.remove('show'), 2200);
}

/* ============================================================
   SESSION — real auth against the PHP backend.

   The source of truth is the PHP session cookie, which JavaScript
   cannot read (HttpOnly) and cannot forge. localStorage now holds
   nothing but a *snapshot* of the signed-in user, for one reason:
   every page's own DOMContentLoaded handler calls requireAuth() or
   requireRole() synchronously, and a fetch() cannot answer in time.

   So load() does two things:
     1. synchronously adopt the snapshot, so the route guards answer
        immediately and the nav draws without a flash of "Sign in";
     2. fire GET api/auth/me.php and reconcile when it answers — if
        the real session is gone, or the role changed under us, the
        same guard is re-applied and the user is redirected then.

   Nothing is trusted from the snapshot: every protected endpoint
   re-checks the session and the role in PHP. The snapshot only
   decides what this one browser draws.
   ============================================================ */
const SESSION_KEY = 'consult_session';

const AUTH_TOASTS = {
  cart: 'Sign in to view your cart',
  checkout: 'Sign in to checkout',
  orders: 'Sign in to view orders',
  success: 'Sign in to continue',
  seller: 'Sign in to access the seller dashboard',
  admin: 'Sign in with an admin account',
  account: 'Sign in to view your account',
  addcart: 'Sign in to add items to your cart',
  wishlist: 'Sign in to save items to your wishlist',
  review: 'Sign in to write a review'
};

const Session = {
  user: null,
  isLoggedIn: false,

  /* Resolves once the server has had its say. Page code that needs the
     authoritative answer (rather than the snapshot) awaits it:
       Session.ready.then(user => ...)

     Starts as an already-resolved promise rather than null, so a page
     script that somehow runs before load() gets a no-op instead of a
     TypeError. load() replaces it with the real one. */
  ready: Promise.resolve(null),

  /* true when api/auth/me.php could not be reached at all, so what is on
     screen is the snapshot and may be stale. */
  offline: false,

  /* What this page asked for, recorded by requireAuth/requireRole so the
     same gate can be re-applied once the server answers. */
  _gate: null,

  load(){
    this._readSnapshot();
    updateNav();

    this.ready = API.Auth.me()
      .then(data => {
        this.offline = false;
        if (data.csrf_token) API.setCsrf(data.csrf_token);
        if (!data.user && data.reason) toast(data.reason);   // e.g. suspended
        this._reconcile(data.user);
        return this.user;
      })
      .catch(err => {
        // Apache or MySQL down mid-demo: keep drawing the snapshot rather
        // than throwing the user out of the page, but be loud in the console
        // and leave Session.offline set so pages can say so.
        this.offline = true;
        console.warn('[session] api/auth/me.php unreachable —', err.message);
        return this.user;
      });

    return this.ready;
  },

  /* ---------- snapshot (a cache, never the authority) ---------- */

  _readSnapshot(){
    try {
      const raw = localStorage.getItem(SESSION_KEY);
      if (!raw) return;
      const u = JSON.parse(raw);
      if (u && u.id && u.status !== 'suspended'){
        this.user = u;
        this.isLoggedIn = true;
      } else {
        localStorage.removeItem(SESSION_KEY);
      }
    } catch(e){
      this.user = null;
      this.isLoggedIn = false;
    }
  },

  /* Adopt a user object the server just returned — after a profile edit,
     say. Sets the in-memory user AND the snapshot together, so the nav is
     right now and still right after the next page load. Page code should
     call this rather than assigning Session.user, which would update the
     page but leave a stale snapshot behind. */
  adopt(user){
    this.user = user || null;
    this.isLoggedIn = !!(user && user.id);
    this._writeSnapshot();
    updateNav();
    return this.user;
  },

  _writeSnapshot(){
    try {
      if (this.user) localStorage.setItem(SESSION_KEY, JSON.stringify(this.user));
      else localStorage.removeItem(SESSION_KEY);
    } catch(e){}
  },

  /* ---------- what the server said ---------- */

  _reconcile(serverUser){
    const wasLoggedIn = this.isLoggedIn;

    this.user = serverUser || null;
    this.isLoggedIn = !!serverUser;
    this._writeSnapshot();
    updateNav();

    if (wasLoggedIn && !serverUser){
      // The PHP session ended: expired, signed out in another tab, or the
      // account was suspended. If this page needed a login, bounce now.
      if (this._gate) redirectToLogin(this._gate.routeName);
      return;
    }
    // Still signed in, but an admin may have changed the role since the
    // snapshot was written — re-run whatever gate this page set.
    this._applyGate();
  },

  _applyGate(){
    const gate = this._gate;
    if (!gate) return;                       // public page, nothing to enforce
    if (!this.isLoggedIn){ redirectToLogin(gate.routeName); return; }
    if (gate.kind === 'role' && this.user.role !== gate.role) rejectRole(gate.role);
  },

  /* ---------- sign in / out ----------
     login() keeps its old name and meaning for existing call sites: adopt
     this user object into the session. The object now comes from PHP
     instead of from localStorage. */

  login(user){
    if (!user){ this.clear(); return false; }
    // A real user always has the numeric users.id MySQL assigned. Anything
    // without one is a client-invented object from the old fake-auth days;
    // adopting it would show a signed-in nav that evaporates on the next
    // page load. Refuse it loudly instead.
    if (typeof user.id !== 'number'){
      console.error('[session] refusing a user object with no database id', user);
      return false;
    }
    if (user.status === 'suspended'){
      toast('This account has been suspended. Contact support.');
      this.clear();
      return false;
    }
    this.user = user;
    this.isLoggedIn = true;
    this._writeSnapshot();
    updateNav();
    return true;
  },

  /** The real sign-in. Resolves with the user, rejects with an ApiError. */
  signIn(email, password){
    return API.Auth.login(email, password).then(data => {
      API.setCsrf(data.csrf_token);
      if (!this.login(data.user)){
        throw new API.ApiError('This account has been suspended. Contact support.', 'FORBIDDEN', null, 403);
      }
      return this._afterSignIn();
    });
  },

  /* Fold any browser-held cart into the account, then load the account's
     real cart and wishlist. Resolves with the user either way: a cart
     problem must never turn a successful sign-in into a failed one. */
  _afterSignIn(){
    return mergeGuestCart()
      .then(() => Promise.all([loadCart().catch(() => {}), loadWishlist()]))
      .catch(() => {})
      .then(() => this.user);
  },

  /** Customer sign-up. payload: {name, email, phone, password, accept_terms} */
  signUp(payload){
    return API.Auth.register(payload).then(data => {
      API.setCsrf(data.csrf_token);
      this.login(data.user);
      return this._afterSignIn();
    });
  },

  clear(){
    this.user = null;
    this.isLoggedIn = false;
    this._writeSnapshot();
    API.setCsrf(null);
    // The cart and wishlist belong to the account, so the mirrors go with
    // the session. The rows themselves stay in MySQL for the next sign-in.
    State.cart = [];
    State.cartTotals = { count: 0, sellers: 0, subtotal: 0, delivery: 0, total: 0 };
    Wish.ids = new Set();
    updateNav();
    paintHearts();
  },

  /* Local state is cleared immediately so the UI never looks signed in
     after the click, whether or not the server call lands. */
  logout(){
    const done = API.Auth.logout().catch(err => {
      console.warn('[session] server logout failed —', err.message);
    });
    this.clear();
    return done;
  }
};

/* A 401 from any endpoint means the PHP session is gone. Drop the snapshot
   and send the user to the login page, remembering where they were. */
API.onUnauthorized(() => {
  if (!Session.isLoggedIn) return;
  Session.clear();
  redirectToLogin(Session._gate ? Session._gate.routeName : null);
});

function currentFile(){
  return location.pathname.split('/').pop() || 'index.html';
}

function redirectToLogin(toastKey){
  if (currentFile() === 'login.html') return;      // already there
  toast(AUTH_TOASTS[toastKey] || 'Sign in to continue');
  location.href = 'login.html?return=' + encodeURIComponent(currentFile());
}

/* The route guards below answer from the snapshot so the page can render
   straight away. They also record what they gated on, so Session._reconcile
   can re-apply the same decision once PHP has answered — and every endpoint
   the page then calls enforces it again server-side regardless. */
function requireAuth(routeName){
  Session._gate = { kind: 'auth', routeName: routeName };
  if (Session.isLoggedIn) return true;
  redirectToLogin(routeName);
  return false;
}

function requireRole(role, routeName){
  Session._gate = { kind: 'role', role: role, routeName: routeName || role };
  if (!Session.isLoggedIn){ redirectToLogin(routeName || role); return false; }
  if (Session.user.role === role) return true;
  rejectRole(role);
  return false;
}

function rejectRole(role){
  toast(role === 'admin' ? 'That page is for platform admins' : 'Register as a seller first');
  location.href = (role === 'seller' && Session.user && Session.user.role === 'customer')
    ? 'seller-register.html'
    : 'index.html';
}

/* The page to come back to after signing in. This site has always used
   ?return=; ?next= is accepted as an alias so a link written either way
   works. */
function returnTarget(){
  const q = new URLSearchParams(location.search);
  return q.get('return') || q.get('next') || '';
}

/* carries a pending ?return= target across the signin/signup/register cross-links */
function wireAuthCrossLinks(){
  const ret = returnTarget();
  if (!ret) return;
  $$('.auth-cross-link').forEach(a => {
    const url = new URL(a.getAttribute('href'), location.href);
    url.searchParams.set('return', ret);
    a.setAttribute('href', url.pathname + url.search);
  });
}

/* where to send the user after a successful sign in / sign up / seller
   register. The pattern test matters: without it, ?return=//evil.example.com
   would be an open redirect off this site straight after a login. */
function postLoginRedirect(){
  const ret = returnTarget();
  if (ret && /^[a-zA-Z0-9_-]+\.html$/.test(ret)) return ret;
  return 'index.html';
}

/* ---------- go() shim — route keywords -> real files, kept so markup
   generated the same way across pages can still call go('cart') etc. ---------- */
const ROUTE_FILES = {
  home: 'index.html',
  chat: 'index.html#chat',
  products: 'products.html',
  cart: 'cart.html',
  checkout: 'checkout.html',
  success: 'success.html',
  signin: 'login.html',
  signup: 'register.html',
  register: 'seller-register.html',
  orders: 'orders.html',
  seller: 'seller-dashboard.html',
  account: 'account.html',
  contact: 'contact.html',
  about: 'about.html'
};
function go(route){
  if (route.indexOf('product/') === 0){
    location.href = 'product-detail.html?id=' + encodeURIComponent(route.slice(8));
    return;
  }
  location.href = ROUTE_FILES[route] || 'index.html';
}

/* ---------- nav (rebuilt whenever auth state changes) ---------- */
function updateNav(){
  const mid = $('#navMidExtra');
  const authArea = $('#navAuthArea');
  if (!authArea) return;

  if (!Session.isLoggedIn){
    if (mid) mid.innerHTML = '';
    authArea.innerHTML = `<div class="nav-auth-pills">
      <a href="login.html" class="pill-ghost">Sign in</a>
      <a href="register.html" class="pill-primary">Sign up</a>
    </div>`;
    syncCart();
    return;
  }

  const u = Session.user;
  const initial = (u.name || u.email || '?').trim().charAt(0).toUpperCase();
  const isAdmin = u.role === 'admin', isSeller = u.role === 'seller';

  if (mid){
    mid.innerHTML = isAdmin
      ? `<a href="admin-dashboard.html">Admin</a>`
      : isSeller
        ? `<a href="seller-dashboard.html">Dashboard</a><a href="orders.html">Orders</a>`
        : `<a href="orders.html">Orders</a>`;
  }

  /* the admin badge sits on the avatar itself so the mode is never
     ambiguous, whichever page they're on */
  authArea.innerHTML = `
    <div class="user-menu${isAdmin ? ' is-admin' : ''}" id="userMenu">
      <button type="button" class="avatar-btn" onclick="toggleUserMenu(event)" aria-label="Account menu">${escapeHtml(initial)}${isAdmin ? '<span class="avatar-admin-tag">Admin</span>' : ''}</button>
      <div class="user-dropdown" id="userDropdown">
        <div class="user-dropdown-head">
          <div class="udh-name">${escapeHtml(u.name || u.email)}</div>
          <div class="udh-role">${isAdmin ? 'Platform admin' : isSeller ? 'Seller account' : 'Customer'}</div>
        </div>
        ${isAdmin
          ? `<a href="admin-dashboard.html">Admin Dashboard</a>`
          : isSeller
            ? `<a href="seller-dashboard.html">Seller Dashboard</a>`
            : `<a href="account.html">My Profile</a>`}
        ${isAdmin ? '' : `<a href="orders.html">My Orders</a>`}
        <a class="danger" onclick="openLogoutModal()">Sign out</a>
      </div>
    </div>`;
  syncCart();
}

function toggleUserMenu(e){
  e.stopPropagation();
  $('#userDropdown')?.classList.toggle('open');
}
function closeUserMenu(){
  $('#userDropdown')?.classList.remove('open');
}
document.addEventListener('click', e => {
  const menu = $('#userMenu');
  if (menu && !menu.contains(e.target)) closeUserMenu();
});

function openLogoutModal(){
  $('#logoutModal')?.classList.add('show');
}
function closeLogoutModal(){
  $('#logoutModal')?.classList.remove('show');
}
/* Wait for the server to drop the PHP session before navigating: leaving
   the page mid-request would abort the fetch and leave the session alive.
   The local state is already cleared by logout() either way, so a failed
   or slow call never leaves the UI looking signed in. */
function confirmLogout(){
  closeLogoutModal();
  toast('Signed out');
  Session.logout().then(() => { location.href = 'index.html'; });
}

/* highlight the current page's nav-mid link */
function highlightNav(){
  const page = document.body.dataset.page;
  if (!page) return;
  $$('nav.editorial .nav-mid a[data-nav]').forEach(a => {
    a.classList.toggle('active', a.dataset.nav === page);
  });
}

/* nav height feeds any "100vh - nav" sizing (chat shell, auth-wrap) */
function setNavH(){
  const nav = $('nav.editorial');
  if (nav) document.documentElement.style.setProperty('--nav-h', nav.offsetHeight + 'px');
}
window.addEventListener('resize', setNavH);

/* ============================================================
   CART — rows in cart_items, mirrored into State.cart

   The cart lives in MySQL against the user's id, so it follows the
   account between browsers and devices. State.cart stays as it was —
   an array of {product, qty} — because cart.js and checkout.js read
   it synchronously while rendering; it is now a mirror of the server's
   answer rather than the thing being stored.

   Every cart endpoint returns the WHOLE cart, so the mirror is always
   replaced wholesale and can never drift from the database.

   localStorage keeps one job: holding a cart put there by an earlier
   build (or by a guest), which is merged into the account on sign-in
   and then deleted.
   ============================================================ */
const CART_KEY = 'consult_cart';

const State = {
  cart: [],
  /* Server-computed totals, so the summary, the badge and checkout all
     quote the same figures rather than three recalculations. */
  cartTotals: { count: 0, sellers: 0, subtotal: 0, delivery: 0, total: 0 }
};

/* Replace the mirror from a cart endpoint's response. */
function adoptCart(payload){
  State.cart = (payload.lines || []).map(l => ({ product: l.product, qty: l.qty }));
  State.cartTotals = {
    count:    payload.count    || 0,
    sellers:  payload.sellers  || 0,
    subtotal: payload.subtotal || 0,
    delivery: payload.delivery || 0,
    total:    payload.total    || 0
  };
  updateCartBadge();
  // Things the server fixed on our behalf: a sold-out item dropped, a
  // quantity reduced to the stock that is left. Say so rather than
  // letting the number change silently.
  (payload.notices || []).forEach(n => toast(n));
  return State.cart;
}

/* Pull the cart from the server. Pages that render it await this. */
function loadCart(){
  if (!Session.isLoggedIn){
    State.cart = [];
    State.cartTotals = { count: 0, sellers: 0, subtotal: 0, delivery: 0, total: 0 };
    updateCartBadge();
    return Promise.resolve(State.cart);
  }
  return API.Cart.get()
    .then(adoptCart)
    .catch(err => {
      console.warn('[cart] could not load the cart —', err.message);
      throw err;
    });
}

/* Add by product object OR by id string — home.js calls addToCart('p1')
   and the detail page calls quickAdd(p.id), and both have to work. The
   id is all the server needs; it re-prices and re-checks stock itself. */
function addToCart(product, qty = 1){
  if (!Session.isLoggedIn){
    redirectToLogin('addcart');
    return Promise.resolve();
  }
  const id = typeof product === 'string' ? product : (product && product.id);
  if (!id){
    console.error('[cart] addToCart called without a product id', product);
    return Promise.resolve();
  }
  const name = (product && product.title) || null;

  return API.Cart.add(id, qty)
    .then(payload => {
      adoptCart(payload);
      toast((name || payload.added || 'Item') + ' added to cart');
      if (typeof renderCart === 'function') renderCart();
    })
    .catch(err => {
      toast(err.message);
      if (err.code === 'AUTH') redirectToLogin('addcart');
    });
}

function quickAdd(id){
  return addToCart(id);
}

/** Set a line to an absolute quantity; 0 removes it. */
function setCartQty(id, qty){
  if (!Session.isLoggedIn){ redirectToLogin('cart'); return Promise.resolve(); }
  return API.Cart.update(id, qty)
    .then(payload => {
      adoptCart(payload);
      if (typeof renderCart === 'function') renderCart();
    })
    .catch(err => toast(err.message));
}

function removeFromCart(id){
  if (!Session.isLoggedIn){ redirectToLogin('cart'); return Promise.resolve(); }
  return API.Cart.remove(id)
    .then(payload => {
      adoptCart(payload);
      if (typeof renderCart === 'function') renderCart();
    })
    .catch(err => toast(err.message));
}

function clearCart(){
  if (!Session.isLoggedIn) return Promise.resolve();
  return API.Cart.clear().then(adoptCart).catch(err => toast(err.message));
}

function updateCartBadge(){
  const badge = $('#cartCount');
  if (!badge) return;
  const n = State.cartTotals.count;
  if (Session.isLoggedIn && n){
    badge.textContent = n;
    badge.style.display = 'grid';
  } else {
    badge.style.display = 'none';
  }
}

/* Kept because the nav rebuild calls it. It only redraws the badge now —
   there is nothing to save, the server already holds the cart. */
function syncCart(){
  updateCartBadge();
}

/* The subtotal, straight from the server when we have it. The local sum is
   the fallback for the moment before the first response lands; a line with
   a non-numeric price contributes 0 rather than turning the total into NaN. */
const cartTotal = () => State.cartTotals.subtotal
  || State.cart.reduce((s, l) =>
       s + (l.product && typeof l.product.price === 'number' ? l.product.price : 0) * l.qty, 0);

/* ---------- the guest / legacy cart in localStorage ---------- */

function readGuestCart(){
  try {
    const raw = localStorage.getItem(CART_KEY);
    if (!raw) return [];
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) return [];
    return parsed
      .filter(l => l && l.product && l.product.id)
      .map(l => ({ product_id: l.product.id, qty: Math.max(1, parseInt(l.qty, 10) || 1) }));
  } catch(e){ return []; }
}

function clearGuestCart(){
  try { localStorage.removeItem(CART_KEY); } catch(e){}
}

/* Run once right after sign-in / sign-up. Anything the browser was holding
   is folded into the account's cart, then the local copy goes away — from
   here on there is one cart and it lives in MySQL. */
function mergeGuestCart(){
  const items = readGuestCart();
  if (!items.length) return Promise.resolve(null);

  return API.Cart.merge(items)
    .then(payload => {
      clearGuestCart();
      adoptCart(payload);
      if (payload.merged){
        toast(payload.merged === 1
          ? 'Your saved cart item was moved to your account'
          : `${payload.merged} saved cart items were moved to your account`);
      }
      return payload;
    })
    .catch(err => {
      // Never block a sign-in over this.
      console.warn('[cart] guest cart merge failed —', err.message);
      return null;
    });
}

/* ============================================================
   WISHLIST — rows in wishlist, mirrored into a Set of ids

   toggleWishlist() is the function the home page's heart button has
   always called in its onclick; until now it did not exist anywhere,
   so every click threw a ReferenceError.
   ============================================================ */
const Wish = { ids: new Set() };

function paintHearts(){
  $$('.product-heart').forEach(btn => {
    const id = btn.dataset.product || heartProductId(btn);
    if (!id) return;
    const saved = Wish.ids.has(id);
    btn.classList.toggle('saved', saved);
    btn.textContent = saved ? '♥' : '♡';
    btn.setAttribute('aria-pressed', saved ? 'true' : 'false');
    btn.setAttribute('aria-label', saved ? 'Remove from wishlist' : 'Add to wishlist');
  });
}

/* The markup carries the id inside the inline onclick, not in a data
   attribute, so read it from there rather than editing every template. */
function heartProductId(btn){
  const m = (btn.getAttribute('onclick') || '').match(/toggleWishlist\('([^']+)'/);
  if (m) btn.dataset.product = m[1];
  return m ? m[1] : null;
}

function loadWishlist(){
  if (!Session.isLoggedIn){
    Wish.ids = new Set();
    paintHearts();
    return Promise.resolve(Wish.ids);
  }
  return API.Wishlist.ids()
    .then(d => { Wish.ids = new Set(d.ids || []); paintHearts(); return Wish.ids; })
    .catch(err => {
      console.warn('[wishlist] could not load —', err.message);
      return Wish.ids;
    });
}

/* Product cards are rendered asynchronously, and from several places —
   the home grid, the "Discover with AI" row, the chat's result cards. Any
   of them can appear after loadWishlist() has already painted, which would
   leave a saved item showing an empty heart.

   Rather than calling paintHearts() from every one of those render paths,
   watch for hearts arriving and repaint. Debounced, because a single grid
   render inserts many nodes in one go. */
function watchForHearts(){
  if (typeof MutationObserver === 'undefined') return;
  let queued = false;

  new MutationObserver(records => {
    if (queued) return;
    const sawHeart = records.some(r =>
      [...r.addedNodes].some(n =>
        n.nodeType === 1 &&
        (n.classList?.contains('product-heart') || n.querySelector?.('.product-heart'))));
    if (!sawHeart) return;

    queued = true;
    requestAnimationFrame(() => { queued = false; paintHearts(); });
  }).observe(document.body, { childList: true, subtree: true });
}

function toggleWishlist(id, btn){
  if (!Session.isLoggedIn){
    redirectToLogin('wishlist');
    return Promise.resolve();
  }
  if (btn) btn.disabled = true;

  return API.Wishlist.toggle(id)
    .then(d => {
      Wish.ids = new Set(d.ids || []);
      paintHearts();
      toast(d.saved ? `${d.title} saved to your wishlist` : `${d.title} removed from your wishlist`);
    })
    .catch(err => {
      toast(err.message);
      if (err.code === 'AUTH') redirectToLogin('wishlist');
    })
    .then(() => { if (btn) btn.disabled = false; });
}

/* ---------- defensive rendering helpers ----------
   Cart/checkout/order/seller-dashboard rendering all read nested
   product fields (seller.name, seller.city, price, title...) that
   aren't guaranteed present on every stored item — older localStorage
   data, a partial AI-service response, anything. safeProduct() gives
   every render call site a fully-populated object to read from instead
   of guarding each field inline everywhere. fmtOrNA() is the matching
   safe formatter for prices that might not be a number. */
function safeProduct(p){
  p = p || {};
  return {
    id: p.id || '',
    title: p.title || 'Unknown product',
    subtitle: p.subtitle || '',
    price: typeof p.price === 'number' ? p.price : null,
    icon: p.icon || 'laptop',
    image: p.image || (Array.isArray(p.images) && p.images[0]) || '',
    seller: p.seller || { name: 'Unknown seller', city: '', verified: false }
  };
}
const fmtOrNA = n => (typeof n === 'number' && !isNaN(n)) ? fmt(n) : 'Price unavailable';

/* last line of defense for a page-level render function: on any
   uncaught error, replace a blank page with a visible, actionable
   message instead of silently doing nothing. `recovery` controls the
   button — 'clear-cart' is safe to offer everywhere the page depends
   on the cart (cart/checkout); order history is worth more than a
   cart, so orders/seller-dashboard just offer a reload. */
function renderErrorState(selector, recovery = 'reload'){
  const el = typeof selector === 'string' ? $(selector) : selector;
  if (!el) return;
  const action = recovery === 'clear-cart'
    ? `onclick="localStorage.removeItem('consult_cart'); location.reload();"`
    : `onclick="location.reload();"`;
  const label = recovery === 'clear-cart' ? 'Clear cart and try again' : 'Reload page';
  el.innerHTML = `
    <div class="render-error">
      <p class="serif" style="font-size:17px; margin-bottom:6px">Something went wrong loading this page</p>
      <p class="muted" style="margin-bottom:16px">
        One of your saved items looks incomplete. Refreshing usually fixes it.
      </p>
      <button type="button" class="btn btn-dark" ${action}>${label}</button>
    </div>`;
}

/* AI-context badges for the "Discover with AI" row — cosmetic labels only,
   layered on top of whatever badge (if any) already lives in product data. */
const CONTEXT_BADGES = {
  overall:     { label: 'Best Overall',      cls: 'badge-overall' },
  students:    { label: 'Best for Students',  cls: 'badge-students' },
  performance: { label: 'Best Performance',   cls: 'badge-performance' },
  budget:      { label: 'Best Budget',        cls: 'badge-budget' }
};

function starRow(rating, reviews){
  const filled = Math.round(rating || 0);
  const stars = Array.from({ length: 8 }, (_, i) => `<span class="star${i < filled ? ' on' : ''}">★</span>`).join('');
  return `<div class="rating-row">${stars}<span class="rating-count">(${reviews || 0})</span></div>`;
}

/* Every field below is seller-entered text out of the products table, so
   all of it goes through escapeHtml() before it reaches innerHTML. It used
   to come from a static data/products.json, where nobody could put a
   <script> in a title; that is no longer true. */
function productCard(p, contextKey){
  const ctx = contextKey && CONTEXT_BADGES[contextKey];
  const badgeHtml = ctx
    ? `<span class="badge ${ctx.cls}">${ctx.label}</span>`
    : (p.badge ? `<span class="badge${p.badge==='deal'?' alt':''}">${escapeHtml(p.badge)}</span>` : '');
  const title = escapeHtml(p.title || 'Untitled product');
  return `<div class="pcard" onclick="go('product/${encodeURIComponent(p.id)}')">
    <div class="pthumb">
       ${p.image ? `<img src="${escapeHtml(p.image)}" alt="${title}">` : icon(p.icon, 54)}
    </div>

    ${badgeHtml}

    <div class="ptitle">${title}</div>

    <div class="pmeta">${escapeHtml(p.subtitle || '')}</div>

    ${starRow(p.rating, p.reviews)}

    <div class="pprice">
    ${fmt(p.price)}${p.oldPrice ? `<span class="pold">${p.oldPrice.toLocaleString()}</span>` : ''}
    
    </div>
    <button type="button" 
    class="pcard-add" 
    onclick="event.stopPropagation(); 
    quickAdd('${p.id}')" 
    aria-label="Add to cart">+</button>
  </div>`;
}

/* ---------- order history ----------
   There is nothing to persist here any more. An order is rows in MySQL
   (orders + sub_orders + order_items + order_status_log), read back through
   API.Orders.* — so it survives a cleared browser, follows the account to
   another device, and is the SAME record the seller and admin dashboards
   read. The old consult_orders localStorage store and its saveOrder() /
   loadOrdersForUser() helpers are gone with it: an order history that only
   existed in one browser was never the truth.

   Sub-order status changes are server-side too, and the legal transitions
   are enforced in api/orders/_orders.php — not by whichever page drew the
   button. */

/* ============================================================
   FORM VALIDATION — shared by every form on the site
   ============================================================ */
function fieldError(input){
  let err = document.getElementById(input.id + '-err');
  if (err) return err;
  err = document.createElement('div');
  err.className = 'field-error';
  err.id = input.id + '-err';
  const authCheck = input.closest('.auth-check');
  if (authCheck) authCheck.insertAdjacentElement('afterend', err);
  else (input.closest('.field') || input.parentElement).appendChild(err);
  return err;
}

function setFieldValid(input, valid, msg){
  const err = fieldError(input);
  err.textContent = valid ? '' : (msg || '');
  err.classList.toggle('show', !valid);
  const container = input.closest('.field') || input.closest('.auth-check');
  container?.classList.toggle('error', !valid);

  if (!input._errWired){
    input._errWired = true;
    input.addEventListener(input.type === 'checkbox' ? 'change' : 'input', () => setFieldValid(input, true, ''));
  }
}

/* Sri Lankan mobile format: 07X XXXXXXX or +94 7X XXXXXXX */
const PHONE_RE = /^(0|\+94)7\d{8}$/;

/* Any 10-digit Sri Lankan number, mobile or landline. Sign-up forms keep
   the mobile-only rule above (a shopper needs an SMS-able number), but a
   profile or a shop contact line can legitimately be a landline — the
   seeded admin's number is 0112000000. The PHP validator accepts both. */
const PHONE_ANY_RE = /^(0|\+94)\d{9}$/;

function validateField(inputId, rule, errorMsg){
  const input = document.getElementById(inputId);
  if (!input) return true;
  let valid = true;
  if (rule === 'required')        valid = input.value.trim().length > 0;
  else if (rule === 'email')      valid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(input.value.trim());
  else if (rule === 'phone')      valid = PHONE_RE.test(input.value.trim().replace(/[\s-]/g, ''));
  else if (rule === 'phoneAny')   valid = PHONE_ANY_RE.test(input.value.trim().replace(/[\s-]/g, ''));
  else if (rule === 'minLength6') valid = input.value.trim().length >= 6;
  else if (rule === 'minLength8') valid = input.value.trim().length >= 8;
  else if (rule === 'checked')    valid = input.checked;
  setFieldValid(input, valid, errorMsg);
  return valid;
}

/* ---------- showing a server-side failure on a form ----------
   The PHP endpoints answer a bad submission with
     {ok:false, code:'VALIDATION', fields:{email:'...', password:'...'}}
   so the same inline error UI the client-side rules use can show it.

   `map` translates the API's field names to this page's input ids, e.g.
   { email: 'siEmail', password: 'siPass' }. Anything the server flags that
   this page has no input for falls back to a toast, so nothing is silently
   swallowed. Returns true if at least one field was marked. */
function showApiErrors(err, map, fallbackFieldId){
  let marked = false;
  const unplaced = [];

  if (err && err.fields){
    Object.keys(err.fields).forEach(key => {
      const input = map[key] ? document.getElementById(map[key]) : null;
      if (input){ setFieldValid(input, false, err.fields[key]); marked = true; }
      else unplaced.push(err.fields[key]);
    });
  }

  // Not a field problem (wrong password, suspended account, server down):
  // put it under one named input so it is visible, not just a toast.
  if (!marked){
    const input = fallbackFieldId ? document.getElementById(fallbackFieldId) : null;
    if (input){ setFieldValid(input, false, err.message); marked = true; }
  }

  toast(unplaced.length ? unplaced[0] : (marked ? 'Please fix the highlighted fields' : err.message));
  return marked;
}

/* ============================================================
   AUTH CHARACTER — shared reactions for login / register /
   seller-register (each page draws its own copy of the SVG,
   these just target whichever scene id is passed in).
   ============================================================ */
const MOUTHS = {
  neutral: 'M136 210 Q150 218 164 210',
  happy:   'M132 205 Q150 230 168 205',
  sad:     'M134 220 Q150 202 166 220',
  worried: 'M137 215 Q150 209 163 215'
};

function setMood(sceneId, mood){
  const el = document.querySelector('#' + sceneId + ' .char-mouth');
  if (el) el.setAttribute('d', MOUTHS[mood] || MOUTHS.neutral);
}

function shakeHead(sceneId){
  const scene = $('#' + sceneId);
  if (!scene) return;
  scene.classList.remove('error-shake');
  void scene.offsetWidth; // restart the animation even if it just played
  scene.classList.add('error-shake');
  setMood(sceneId, 'worried');
  setTimeout(() => {
    scene.classList.remove('error-shake');
    setMood(sceneId, 'neutral');
  }, 550);
}

function celebrate(sceneId){
  const scene = $('#' + sceneId);
  if (!scene) return;
  scene.classList.add('success');
  setMood(sceneId, 'happy');
}

function wireFocus(sceneId, emailId, passId){
  const scene = $('#' + sceneId);
  if (!scene) return;
  const emailEl = emailId ? $('#' + emailId) : null;
  const passEl  = passId  ? $('#' + passId)  : null;
  emailEl?.addEventListener('focus', () => scene.classList.add('email-focus'));
  emailEl?.addEventListener('blur',  () => scene.classList.remove('email-focus'));
  passEl?.addEventListener('focus', () => scene.classList.add('pass-focus'));
  passEl?.addEventListener('blur',  () => scene.classList.remove('pass-focus'));
}

function spawnConfetti(container){
  if (!container) return;
  const colors = ['#8B2E3C', '#D4A83C', '#1B2A4A', '#C98F6B'];
  for (let i = 0; i < 20; i++){
    const d = document.createElement('div');
    d.className = 'confetti-piece';
    d.style.setProperty('--cx', (Math.random() * 220 - 110) + 'px');
    d.style.setProperty('--cr', (Math.random() * 360) + 'deg');
    d.style.left = (38 + Math.random() * 24) + '%';
    d.style.background = colors[i % colors.length];
    d.style.animationDelay = (Math.random() * 0.25) + 's';
    container.appendChild(d);
    setTimeout(() => d.remove(), 1700);
  }
}

/* password show/hide toggle, used on every password field site-wide */
const EYE_ON  = `<svg width="17" height="17" viewBox="0 0 24 24" fill="none"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.6"/></svg>`;
const EYE_OFF = `<svg width="17" height="17" viewBox="0 0 24 24" fill="none"><path d="M3 3l18 18M10.6 10.6a3 3 0 004.2 4.2M6.6 6.7C3.9 8.3 2 12 2 12s4 7 11 7c2 0 3.7-.5 5.1-1.3M17.6 17.7C19.7 16.2 21.1 14 22 12c0 0-4-7-11-7-1 0-1.9.1-2.8.4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>`;
function togglePass(id, btn){
  const input = $('#' + id);
  if (!input) return;
  const show = input.type === 'password';
  input.type = show ? 'text' : 'password';
  btn.innerHTML = show ? EYE_OFF : EYE_ON;
}

/* ---------- boot: runs on every page ----------
   Session.load() answers synchronously from the snapshot, so the route
   guards and the nav are right immediately. The cart and the wishlist are
   rows in MySQL now, so they can only be fetched once the server has
   confirmed who we are — hence off Session.ready rather than inline. */
document.addEventListener('DOMContentLoaded', () => {
  Session.load();
  highlightNav();
  setNavH();
  watchForHearts();

  Session.ready.then(user => {
    if (!user) return;
    loadCart().catch(() => {});   // pages that render it handle their own errors
    loadWishlist();
  });
});

function productThumb(p, iconName, size){
  const src = p.image || (p.images && p.images[0]);
  if (!src) return icon(iconName, size);
  // src and title are seller-entered; escape both before they reach innerHTML.
  return `<img src="${escapeHtml(src)}" alt="${escapeHtml(p.title || '')}"
    style="width:100%; height:100%; object-fit:cover; border-radius:inherit"
    onerror="this.outerHTML = window.iconMarkup ? window.iconMarkup('${escapeHtml(iconName || 'laptop')}', ${parseInt(size, 10) || 34}) : ''">`;
}

window.iconMarkup = icon;