/* ============================================================
   admin-dashboard.js — admin-dashboard.html

   The web-master console. Eight sections: Overview, Seller
   verification, Product moderation, Users, Orders & disputes,
   Finance, FAQs, Settings. Requires Session.user.role === 'admin';
   anyone else gets an on-page "no admin access" message.

   Same shell and component system as the seller dashboard: sidebar
   (#sdSide), sticky top bar (#sdTop), section content (#adminBody),
   all sd-* classes from css/seller-dashboard.css.

   Everything is read from api/admin/dashboard.php and written
   through api/admin/*.php. Every write lands in the admin_actions
   audit trail — written by the SERVER, in the same transaction as
   the change it describes, so an action cannot happen without its
   record even if the request dies half way.

   Nothing here decides permissions: require_admin() gates every
   endpoint, and api/orders/_orders.php decides which order status
   may follow which even for an admin.
   ============================================================ */

const SIDE = [
  { group: 'Console' },
  { id: 'overview', label: 'Overview',            icon: 'home' },
  { id: 'sellers',  label: 'Seller verification', icon: 'shield' },
  { id: 'products', label: 'Product moderation',  icon: 'box' },
  { id: 'users',    label: 'Users',               icon: 'users' },
  { id: 'orders',   label: 'Orders & disputes',   icon: 'orders' },
  { id: 'finance',  label: 'Finance',             icon: 'cash' },
  { group: 'Platform' },
  { id: 'faqs',     label: 'FAQs',                icon: 'help' },
  { id: 'settings', label: 'Platform settings',   icon: 'gear' }
];
const VIEWS = SIDE.filter(s => s.id);
const VIEW_IDS = VIEWS.map(s => s.id);
const PAGE_SIZE = 10;

/* icons only this page needs (same inline-SVG idiom as DI in dash-common.js) */
const SI = {
  sparkle: `<svg viewBox="0 0 24 24" fill="none"><path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z" fill="currentColor"/><path d="M18.5 15.5l.7 2 2 .7-2 .7-.7 2-.7-2-2-.7 2-.7z" fill="currentColor"/></svg>`,
  chev:    `<svg viewBox="0 0 24 24" fill="none"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>`,
  collapse:`<svg viewBox="0 0 24 24" fill="none"><path d="M15 6l-6 6 6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 4v16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>`,
  globe:   `<svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.6"/><path d="M3.5 12h17M12 3.5c2.5 2.6 3.5 5.4 3.5 8.5s-1 5.9-3.5 8.5c-2.5-2.6-3.5-5.4-3.5-8.5s1-5.9 3.5-8.5z" stroke="currentColor" stroke-width="1.6"/></svg>`,
  logout:  `<svg viewBox="0 0 24 24" fill="none"><path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h10" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>`,
  user:    `<svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="1.6"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>`,
  phone:   `<svg viewBox="0 0 24 24" fill="none"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>`,
  pin:     `<svg viewBox="0 0 24 24" fill="none"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0112 3a7 7 0 017 6.5C19 14.8 12 21 12 21z" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="9.5" r="2.5" stroke="currentColor" stroke-width="1.6"/></svg>`,
  store:   `<svg viewBox="0 0 24 24" fill="none"><path d="M4 9l1.5-5h13L20 9M4 9v11h16V9M4 9h16M9 20v-6h6v6" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>`,
  upload:  `<svg viewBox="0 0 24 24" fill="none"><path d="M12 16V4M7 9l5-5 5 5M4 20h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>`,
  arrow:   `<svg viewBox="0 0 24 24" fill="none"><path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>`,
  eye:     `<svg viewBox="0 0 24 24" fill="none"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.6"/></svg>`,
  cart:    `<svg viewBox="0 0 24 24" fill="none"><path d="M3 4h2.2l2.3 11.5a1.5 1.5 0 001.5 1.2h8.6a1.5 1.5 0 001.5-1.2L20.5 8H6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><circle cx="10" cy="20" r="1.3" fill="currentColor"/><circle cx="17" cy="20" r="1.3" fill="currentColor"/></svg>`,
  percent: `<svg viewBox="0 0 24 24" fill="none"><path d="M18 6L6 18" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><circle cx="7.5" cy="7.5" r="2.5" stroke="currentColor" stroke-width="1.6"/><circle cx="16.5" cy="16.5" r="2.5" stroke="currentColor" stroke-width="1.6"/></svg>`,
  toggle:  `<svg viewBox="0 0 24 24" fill="none"><rect x="3" y="7" width="18" height="10" rx="5" stroke="currentColor" stroke-width="1.6"/><circle cx="16" cy="12" r="3" fill="currentColor"/></svg>`,
  tag:     `<svg viewBox="0 0 24 24" fill="none"><path d="M3 12V4h8l10 10-8 8z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><circle cx="7.5" cy="8.5" r="1.5" fill="currentColor"/></svg>`
};

/* The shape AD.data holds before the first load lands, so every reader
   works against an empty console instead of throwing while the skeleton is
   on screen. Declared before AD because AD references it. */
const AD_EMPTY = {
  admin: null, stats: {}, series: [], sellers: [], users: [], products: [],
  orders: [], faqs: [], messages: [], by_seller: [],
  settings: { commission_rate: 8, categories: [], seller_signups_open: true, customer_signups_open: true },
  actions: []
};

const AD = {
  me: null, view: 'overview', loading: true, collapsed: false, drawer: false,
  data: AD_EMPTY,          // the whole console, from api/admin/dashboard.php
  busy: false,             // a write is in flight
  chartDays: 30, tipIdx: 0,
  uquery: '', urole: 'all', userDetail: null,
  oquery: '', otab: 'all', opage: 1, openKey: null,
  frange: '30d', fseller: 'all',
  pendingAvatar: undefined,
  avatarPreview: null
};

/* ============================================================
   DATA — one load from api/admin/dashboard.php, held in AD.data

   Same arrangement as the seller console: this page renders eight
   views synchronously and redraws them on every click, so it needs
   its data in hand rather than a promise per read. The whole
   console arrives in ONE request and the readers below are
   synchronous views over it.

   Every write posts to its endpoint and then RE-READS, because the
   KPIs, the finance figures and the audit trail are all computed in
   SQL — asking the server again is the only way to be sure the
   screen agrees with the database.

   NOTHING HERE IS A PERMISSION CHECK. require_admin() on every
   endpoint is the gate, and api/orders/_orders.php decides which
   status may follow which even for an admin. The flags below only
   keep the page from drawing a control that would be refused.
   ============================================================ */

/* Mirrors ORDER_FLOW / ORDER_TERMINAL in api/orders/_orders.php. */
const FLOW     = ['pending', 'packed', 'shipped', 'delivered'];
const TERMINAL = ['cancelled', 'disputed', 'refunded'];

const ADB = {
  /* ---------- reads ---------- */
  orders:   () => AD.data.orders,
  users:    () => AD.data.users,
  products: () => AD.data.products,
  faqs:     () => AD.data.faqs,
  messages: () => AD.data.messages,
  settings: () => AD.data.settings,
  stats:    () => AD.data.stats,
  actions:  () => AD.data.actions,
  bySeller: () => AD.data.by_seller,

  /* Ids arrive from the server as integers but travel through data-id
     attributes, which always read back as strings — so every lookup
     compares loosely on purpose. Getting this wrong is silent: the row
     simply never matches and the button does nothing. */
  user:    id  => AD.data.users.find(u => String(u.id) === String(id)) || null,
  product: id  => AD.data.products.find(p => String(p.id) === String(id)) || null,
  order:   key => AD.data.orders.find(o => String(o.key) === String(key)) || null,

  /* The filtered variants the seller drill-down and the user drawer need.
     Loose comparison again: these ids come out of data-id attributes. */
  ordersBySeller: id => AD.data.orders.filter(o => String(o.seller_id) === String(id)),
  ordersByUser:   id => AD.data.orders.filter(o => String(o.user_id) === String(id)),

  sellers:        () => AD.data.sellers,
  pendingSellers: () => AD.data.sellers.filter(u => (u.seller || {}).kyc_status === 'pending'),
  pendingProducts: () => AD.data.products.filter(p => p.status === 'pending'),
  sellerProducts: id => AD.data.products.filter(p => String(p.seller_id) === String(id)),

  /* The rate that actually applies to a seller: their negotiated one, or
     the platform default. */
  commissionRateFor(sellerId){
    const u = ADB.user(sellerId) || AD.data.sellers.find(x => String(x.id) === String(sellerId));
    const own = u && u.seller ? u.seller.commission_rate : null;
    return own == null ? AD.data.settings.commission_rate : own;
  },

  /* Today's effective price. `on_sale` was decided by the database
     (CURDATE() BETWEEN start_date AND end_date), not by comparing dates here. */
  effectivePrice(p){
    if (p && p.on_sale && p.sale_price) return { price: p.sale_price, oldPrice: p.price, onSale: true };
    return { price: p ? p.price : 0, oldPrice: (p && p.old_price) || null, onSale: false };
  },

  /* The payout the SERVER computed for this sub-order. */
  payoutFor: o => (o && o.payout) || { gross: 0, rate: 0, commission: 0, net: 0, state: 'escrow' },

  /* An admin product row → the storefront card shape, so the moderation
     preview shows the seller's listing exactly as a shopper would see it
     rather than an approximation of it. */
  toCatalogShape(p){
    const eff = ADB.effectivePrice(p);
    return {
      id: p.id,
      sellerId: (p.seller && p.seller.id) || p.seller_id,
      category: p.category,
      icon: p.icon || 'laptop',
      title: p.title,
      subtitle: p.subtitle || '',
      image: (p.images && p.images[0]) || '',
      images: p.images || [],
      price: eff.price,
      oldPrice: eff.oldPrice,
      currency: p.currency || 'LKR',
      stock: p.stock,
      tags: p.tags || [],
      specs: p.specs || {},
      why: p.description || '',
      rating: p.rating || 0,
      reviews: p.review_count || 0,
      badge: eff.onSale ? 'deal' : (p.badge || null),
      seller: p.seller || { name: '—', city: '', rating: 0, verified: false }
    };
  },

  /* Load, or reload, the whole console. */
  load(){
    return API.Admin.dashboard().then(d => {
      AD.data = Object.assign({}, AD_EMPTY, d);
      if (d.admin) AD.me = Object.assign({}, AD.me, d.admin);
      return AD.data;
    });
  }
};

/**
 * Every write: post, then re-read and repaint. Also used for the failure
 * path, so a refused change leaves the screen showing what the server
 * actually holds rather than the change that did not happen.
 */
function adWrite(promise, opts){
  opts = opts || {};
  AD.busy = true;

  const finish = () => ADB.load()
    .then(() => { AD.busy = false; render(); })
    .catch(err => {
      AD.busy = false;
      console.error('[admin] could not reload the console:', err);
      toast('Saved, but the console could not refresh. Reload the page.');
    });

  return promise
    .then(res => {
      if (opts.onOk) opts.onOk(res);
      const msg = opts.message || (res && res.message);
      if (msg) toast(msg);
      // Said separately so an important caveat is not lost in a toast that
      // also has to say "saved".
      if (res && res.notice) setTimeout(() => toast(res.notice), 2600);
      return finish();
    })
    .catch(err => {
      AD.busy = false;
      console.error('[admin] write failed:', err);
      if (err.code === 'AUTH') return;            // api.js is redirecting
      toast(err.fields ? Object.values(err.fields)[0] : (err.message || 'That change could not be saved.'));
      if (opts.onFail) opts.onFail(err);
      return finish();
    });
}

/* Generic day bucketing for the SVG charts. The headline KPIs come from
   SQL (see AD.data.stats); this buckets the same real rows client-side so
   the chart's range switch and its week-over-week comparison do not each
   need a round trip. */
function daySeries(rows, days, dateOf, valueOf){
  const buckets = [];
  const start = new Date(); start.setHours(0, 0, 0, 0);
  for (let i = days - 1; i >= 0; i--){
    const d = new Date(start); d.setDate(d.getDate() - i);
    buckets.push({
      start: d.getTime(), end: d.getTime() + 86400000, total: 0,
      label: d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })
    });
  }
  rows.forEach(r => {
    // parseWhen (dash-common.js) handles MySQL's 'YYYY-MM-DD HH:MM:SS'.
    const when = parseWhen(dateOf(r));
    if (!when) return;
    const t = when.getTime();
    const b = buckets.find(x => t >= x.start && t < x.end);
    if (b) b.total += valueOf(r);
  });
  return buckets;
}

/* ---------- data helpers ---------- */
const allSubOrders = () => ADB.orders();

/* The server already puts the seller's and buyer's names on every order
   row, so these are only a fallback for a row that came from somewhere
   else. */
const sellerName = id => {
  const u = ADB.user(id) || AD.data.sellers.find(x => String(x.id) === String(id));
  return u ? ((u.seller && u.seller.business_name) || u.name) : (id || '—');
};
const userName = id => { const u = ADB.user(id); return u ? u.name : (id || 'guest'); };
const isLive = o => !o.refunded_at && o.status !== 'cancelled';
const plural = (n, w) => `${n} ${w}${n === 1 ? '' : 's'}`;
const withinDays = (iso, days) => iso && hoursSince(iso) <= days * 24;
const catOf = l => (l.product && l.product.category)
  || (ADB.product(l.product && l.product.id) || {}).category || 'other';
const kpiMoney = n => `<small>LKR</small>${Math.round(n || 0).toLocaleString('en-LK')}`;
const itemsLine = o => (o.items || []).map(l => { const p = safeProduct(l.product); return `${escapeHtml(p.title)}${l.qty > 1 ? ` ×${l.qty}` : ''}`; }).join(', ') || 'Unknown product';
const orderThumb = o => { const p = o.items && o.items[0] && o.items[0].product; return p ? smallThumb(p) : `<span class="pthumb-sm">${DI.box}</span>`; };
const shipOf = o => o.shipping || {};

/* 14 daily values → last 7 vs the 7 before. `invert` for metrics where up is bad. */
function trendInfo(series, invert){
  const recent = series.slice(-7).reduce((a, b) => a + b, 0);
  const prior  = series.slice(-14, -7).reduce((a, b) => a + b, 0);
  if (!prior && !recent) return { cls: 'flat', text: '—', note: 'no activity yet' };
  if (!prior) return { cls: invert ? 'down' : 'up', text: '▲ new', note: 'vs last week' };
  const p = Math.round(((recent - prior) / prior) * 100);
  if (!p) return { cls: 'flat', text: '0%', note: 'vs last week' };
  const good = invert ? p < 0 : p > 0;
  return { cls: good ? 'up' : 'down', text: `${p > 0 ? '▲' : '▼'} ${Math.abs(p)}%`, note: 'vs last week' };
}

/* ============================================================
   AVATAR — identical to the seller console: photo if uploaded,
   else initials on a palette colour picked from the id.
   ============================================================ */
/* The photo is users.avatar_url — a file under uploads/, uploaded through
   api/auth/avatar.php. It used to be a data URL in localStorage, which meant
   it vanished on another device and could blow the storage quota. */
function getAvatar(){ return (AD.me && AD.me.avatar_url) || null; }
function initials(name){
  const w = String(name || '').trim().split(/\s+/).filter(Boolean);
  return ((w[0] || '?').charAt(0) + (w.length > 1 ? w[w.length - 1].charAt(0) : '')).toUpperCase();
}
function colorIdx(seed){ let h = 0; for (const c of String(seed || '')) h = (h * 31 + c.charCodeAt(0)) >>> 0; return h % 3; }
function avatarHtml(size = '', src){
  const u = AD.me;
  const photo = src === undefined ? getAvatar() : src;
  return `<span class="sd-av ${size} c${colorIdx(u.id)}" aria-hidden="true">${photo
    ? `<img src="${escapeHtml(photo)}" alt="">`
    : escapeHtml(initials(u.name || u.email))}</span>`;
}
/* everyone else (sellers, buyers) gets initials keyed by their name */
function personAv(name, size = ''){ return `<span class="sd-av ${size} c${colorIdx(name)}" aria-hidden="true">${escapeHtml(initials(name || '?'))}</span>`; }
const firstName = () => String(AD.me.name || AD.me.email || 'there').trim().split(/\s+/)[0];

/* ============================================================
   RENDER
   ============================================================ */
function render(){
  const box = $('#adminBody');
  if (!box) return;
  box.setAttribute('data-ready', '1');                 // the HTML watchdog stands down
  try {
    const counts = sideCounts();
    $('#dash').classList.remove('is-gated');
    $('#dash').classList.toggle('is-collapsed', AD.collapsed);
    $('#dash').classList.toggle('drawer-open', AD.drawer);
    renderSide(counts);
    renderTop();
    if (AD.loading){ box.innerHTML = skeleton(); return; }
    box.innerHTML = { overview: overviewView, sellers: sellersView, products: productsView, users: usersView, orders: ordersView, finance: financeView, faqs: faqsView, settings: settingsView }[AD.view]();
  } catch(err){
    console.error('Admin dashboard render failed:', err);
    renderErrorState(box, 'reload');
  }
}
function skeleton(){
  return `<div class="skeleton" style="height:56px; width:50%; margin-bottom:20px; border-radius:var(--r-md)"></div>
    <div class="sd-skel-row">${'<div class="skeleton"></div>'.repeat(4)}</div>
    <div class="skeleton" style="height:300px; border-radius:var(--r-lg)"></div>`;
}
function sideCounts(){
  const ps = ADB.pendingSellers().length, pp = ADB.pendingProducts().length;
  const disputes = allSubOrders().filter(o => o.status === 'disputed').length;
  return {
    sellers:  ps ? { n: ps, title: ps + ' awaiting verification', warn: true } : null,
    products: pp ? { n: pp, title: pp + ' awaiting moderation', warn: true } : null,
    orders:   disputes ? { n: disputes, title: disputes + ' open dispute' + (disputes === 1 ? '' : 's') } : null
  };
}

/* ---------- sidebar ---------- */
function renderSide(counts){
  const waiting = ADB.pendingSellers().length + ADB.pendingProducts().length;
  $('#sdSide').innerHTML = `
    <div class="sd-store" title="Consult platform">
      ${avatarHtml('sm')}
      <div class="sd-store-copy">
        <div class="sd-store-name">Consult platform</div>
        <div class="sd-store-meta"><span class="ad-role">Admin</span> Web-master</div>
      </div>
    </div>
    ${SIDE.map(it => {
      if (it.group) return `<div class="sd-side-label">${it.group}</div>`;
      const c = counts[it.id], on = AD.view === it.id;
      return `<button class="sd-nav${on ? ' on' : ''}" data-act="view" data-view="${it.id}" title="${it.label}" ${on ? 'aria-current="page"' : ''}>
        <span class="sd-nav-ico">${DI[it.icon]}</span><span class="sd-nav-label">${it.label}</span>
        ${c ? `<span class="sd-nav-badge${c.warn ? ' warn' : ''}" title="${escapeHtml(c.title)}">${c.n}</span>` : ''}
      </button>`;
    }).join('')}
    <div class="sd-side-foot">
      <div class="ad-queue-card">
        <strong>${waiting ? plural(waiting, 'item') + ' waiting' : 'Review queues are clear'}</strong>
        <p>${waiting ? 'Seller applications and new listings wait here until you review them.' : 'New seller applications and listings appear here the moment they arrive.'}</p>
        <button class="btn btn-primary" data-act="view" data-view="${ADB.pendingSellers().length ? 'sellers' : 'products'}">${waiting ? 'Open the queue' : 'View moderation'}</button>
      </div>
      <button class="sd-collapse" data-act="toggle-side" aria-label="${AD.collapsed ? 'Expand sidebar' : 'Collapse sidebar'}" title="${AD.collapsed ? 'Expand sidebar' : 'Collapse sidebar'}">${SI.collapse}<span>Collapse</span></button>
    </div>`;
}

/* ---------- top bar ---------- */
function alertItems(){
  const items = [];
  const ps = ADB.pendingSellers(), pp = ADB.pendingProducts();
  const disputes = allSubOrders().filter(o => o.status === 'disputed');
  if (disputes.length) items.push({ icon: 'flag', bad: true, view: 'orders', tab: 'disputed', title: `${plural(disputes.length, 'dispute')} to resolve`, sub: 'Seller payouts are held until you decide' });
  if (ps.length) items.push({ icon: 'shield', view: 'sellers', title: `${plural(ps.length, 'seller application')} waiting`, sub: `Oldest: ${ps.sort((a, b) => new Date(a.created_at) - new Date(b.created_at))[0].seller.business_name} — ${timeAgo(ps[0].created_at)}` });
  if (pp.length) items.push({ icon: 'box', view: 'products', title: `${plural(pp.length, 'listing')} to moderate`, sub: pp.slice(0, 2).map(p => p.title).join(', ') + (pp.length > 2 ? '…' : '') });
  const stale = allSubOrders().filter(o => FLOW.includes(o.status) && o.status !== 'delivered' && hoursSince(o.status_updated_at) > 72);
  if (stale.length) items.push({ icon: 'clock', view: 'orders', tab: 'all', title: `${plural(stale.length, 'order')} stuck over 3 days`, sub: 'A seller may need a nudge' });
  return items;
}
function renderTop(){
  const meta = VIEWS.find(v => v.id === AD.view) || VIEWS[0];
  const alerts = alertItems();
  const u = AD.me;
  $('#sdTop').innerHTML = `
    <button class="sd-icon-btn sd-burger" data-act="drawer-open" aria-label="Open menu">${DI.menu}</button>
    <div class="sd-top-title">
      <div class="sd-crumb">Web-master / <b>${meta.label}</b></div>
      <h1>${meta.label}</h1>
    </div>
    <div class="sd-top-search sd-pop-wrap">
      <div class="dsearch"><span class="dsearch-ico">${DI.search}</span>
        <input id="adGlobal" type="search" placeholder="Search users, sellers, orders, listings…" aria-label="Search the console" autocomplete="off">
        <kbd>/</kbd></div>
      <div class="sd-pop search" id="adSearchPop"></div>
    </div>
    <div class="sd-top-actions">
      <a class="btn btn-ghost sd-store-btn" href="index.html" title="View the storefront">${SI.globe}<span>View platform</span></a>
      <div class="sd-pop-wrap">
        <button class="sd-icon-btn" data-act="pop" data-pop="notif" aria-label="Notifications${alerts.length ? ` (${alerts.length})` : ''}" aria-expanded="false">${DI.bell}${alerts.length ? `<span class="dot">${alerts.length}</span>` : ''}</button>
        <div class="sd-pop" id="pop-notif">
          <div class="sd-pop-head"><strong>Notifications</strong><span>${alerts.length ? plural(alerts.length, 'item') + ' need you' : 'all clear'}</span></div>
          ${alerts.length ? alerts.map(a => `
            <button class="sd-pop-item${a.bad ? ' danger' : ''}" data-act="goto" data-view="${a.view}" ${a.tab ? `data-tab="${a.tab}"` : ''}>
              ${DI[a.icon]}<span><strong style="font-weight:600">${escapeHtml(a.title)}</strong><small>${escapeHtml(a.sub)}</small></span>
            </button>`).join('') : `<div class="sd-pop-empty">Nothing needs you right now.</div>`}
        </div>
      </div>
      <div class="sd-pop-wrap">
        <button class="sd-me" data-act="pop" data-pop="me" aria-haspopup="true" aria-expanded="false" aria-label="Account menu">
          ${avatarHtml('sm')}
          <span class="sd-me-copy"><strong>${escapeHtml(u.name || u.email)}</strong><span>Platform admin</span></span>
          ${SI.chev}
        </button>
        <div class="sd-pop" id="pop-me" style="width:250px">
          <div class="sd-pop-head" style="justify-content:flex-start; gap:10px">${avatarHtml('')}<div style="min-width:0"><strong style="display:block">${escapeHtml(u.name || '')}</strong><span style="display:block; overflow:hidden; text-overflow:ellipsis">${escapeHtml(u.email || '')}</span></div></div>
          <a class="sd-pop-item" href="index.html">${SI.globe} View platform</a>
          <button class="sd-pop-item" data-act="view" data-view="settings">${DI.gear} Settings</button>
          <div class="sd-pop-sep"></div>
          <button class="sd-pop-item danger" data-act="logout">${SI.logout} Log out</button>
        </div>
      </div>
    </div>`;
}
function closePops(except){
  $$('.sd-pop.open').forEach(p => { if (p !== except){ p.classList.remove('open'); p.previousElementSibling?.setAttribute?.('aria-expanded', 'false'); } });
}

/* global search — results in a popover, no re-render so focus is kept */
function globalSearch(q){
  const pop = $('#adSearchPop');
  if (!pop) return;
  q = q.trim().toLowerCase();
  if (!q){ pop.classList.remove('open'); pop.innerHTML = ''; return; }
  const users = ADB.users().filter(u => [u.name, u.email, u.phone, u.seller?.business_name].filter(Boolean).some(s => s.toLowerCase().includes(q))).slice(0, 4);
  const orders = allSubOrders().filter(o => [o.id, o.order_id, shipOf(o).name, sellerName(o.seller_id)].filter(Boolean).some(s => s.toLowerCase().includes(q))).slice(0, 4);
  const products = ADB.products().filter(p => !p.deleted_at && p.title.toLowerCase().includes(q)).slice(0, 3);
  pop.innerHTML = (!users.length && !orders.length && !products.length) ? `<div class="sd-pop-empty">Nothing matches “${escapeHtml(q)}”</div>` : `
    ${users.length ? `<div class="sd-pop-label">People &amp; sellers</div>${users.map(u => `<button class="sd-pop-item" data-act="user-open" data-id="${escapeHtml(u.id)}">${u.role === 'seller' ? DI.shield : SI.user}<span>${escapeHtml(u.seller?.business_name || u.name)}<small>${escapeHtml(u.role)} · ${escapeHtml(u.email)}</small></span></button>`).join('')}` : ''}
    ${orders.length ? `<div class="sd-pop-label">Orders</div>${orders.map(o => `<button class="sd-pop-item" data-act="order-open" data-key="${escapeHtml(o.key)}">${DI.orders}<span>${escapeHtml(o.id)}<small>${escapeHtml(shipOf(o).name || '—')} → ${escapeHtml(sellerName(o.seller_id))} · ${o.status}</small></span></button>`).join('')}` : ''}
    ${products.length ? `<div class="sd-pop-label">Listings</div>${products.map(p => `<button class="sd-pop-item" data-act="product-preview" data-id="${escapeHtml(p.id)}">${DI.box}<span>${escapeHtml(p.title)}<small>${escapeHtml(sellerName(p.seller_id))} · ${p.status}</small></span></button>`).join('')}` : ''}`;
  pop.classList.add('open');
}

function emptyBlock(ico, title, body, actions = ''){
  return `<div class="sd-empty"><span class="dempty-ico">${ico}</span><strong>${escapeHtml(title)}</strong><p>${body}</p>${actions ? `<div class="dempty-actions">${actions}</div>` : ''}</div>`;
}
function kpiCard({ view, tone = '', icon, label, value, series, note, invert, filter }){
  const t = series ? trendInfo(series, invert) : null;
  // a card with nowhere to go is a plain panel, not a button
  const tag = view || filter ? 'button' : 'div';
  const attrs = filter ? `data-act="${filter.act}" data-f="${filter.f}"` : view ? `data-act="view" data-view="${view}"` : '';
  return `<${tag} class="sd-card sd-kpi ${tone}" ${attrs}>
    <span class="sd-kpi-top"><span class="sd-kpi-label">${label}</span><span class="sd-kpi-ico">${DI[icon] || SI[icon]}</span></span>
    <span class="sd-kpi-value">${value}</span>
    <span class="sd-kpi-foot">
      ${t ? `<span><span class="sd-trend ${t.cls}">${t.text}</span> <span class="sd-trend-note">${note || t.note}</span></span>${sparkline(series.slice(-7))}`
          : `<span class="sd-trend-note">${note || ''}</span>`}
    </span>
  </${tag}>`;
}

/* ============================================================
   OVERVIEW
   ============================================================ */
/**
 * A KPI number, preferring the figure SQL computed over the WHOLE table.
 *
 * AD.data.orders is capped at 500 rows (admin_orders), so any card that
 * reduces over it measures a window, not the shop. AD.data.stats is
 * computed in SQL over every row and is the number to show.
 *
 * $fallback is the old client-side computation, used only when the server
 * did not send that stat -- an older payload still renders instead of
 * printing "undefined".
 */
function statNum(key, fallback){
  const v = (AD.data.stats || {})[key];
  return typeof v === 'number' ? v : fallback;
}

function overviewView(){
  const subs = allSubOrders(), live = subs.filter(isLive);
  const users = ADB.users();
  const live30 = live.filter(o => withinDays(o.placed_at, 30));
  const gmv30 = live30.reduce((s, o) => s + (o.total || 0), 0);
  const sellers = ADB.sellers();
  const activeSellers = sellers.filter(u => u.status === 'active' && u.seller.kyc_status === 'verified');
  const pendingSellers = ADB.pendingSellers(), pendingProducts = ADB.pendingProducts();
  const disputes = subs.filter(o => o.status === 'disputed');

  const gmv14 = daySeries(live, 14, o => o.placed_at, o => o.total || 0).map(b => b.total);
  const approved14 = daySeries(sellers.filter(u => u.seller.kyc_status === 'verified'), 14, u => u.seller.kyc_reviewed_at, () => 1).map(b => b.total);
  const queued14 = daySeries([...pendingSellers, ...pendingProducts], 14, x => x.created_at, () => 1).map(b => b.total);
  const disputes14 = daySeries(subs.filter(o => o.dispute), 14, o => o.dispute.opened_at, () => 1).map(b => b.total);

  const hour = new Date().getHours();
  const greet = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
  const waiting = pendingSellers.length + pendingProducts.length;

  return `
    <div class="sd-hello">
      <div class="sd-hello-main">
        ${avatarHtml('lg')}
        <div>
          <h2>${greet}, ${escapeHtml(firstName())}! 👋</h2>
          <p>${waiting || disputes.length
            ? `${[waiting ? `${plural(waiting, 'item')} in review` : '', disputes.length ? `${plural(disputes.length, 'open dispute')}` : ''].filter(Boolean).join(' and ')} — here's the rest of the marketplace at a glance.`
            : `Queues are clear. Here's how the marketplace is doing today.`}</p>
        </div>
      </div>
      <div class="sd-hello-actions">
        <button class="btn btn-ghost sd-btn-ico" data-act="view" data-view="finance">${DI.cash} Finance</button>
        <button class="btn btn-primary sd-btn-ico" data-act="view" data-view="${pendingSellers.length ? 'sellers' : 'products'}">${DI.shield} Review queue${waiting ? ` (${waiting})` : ''}</button>
      </div>
    </div>

    <div class="sd-kpis">
      ${kpiCard({ view: 'finance', icon: 'cash', label: 'Platform GMV · 30 days', value: kpiMoney(statNum('gmv_30d', gmv30)), series: gmv14 })}
      ${kpiCard({ view: 'sellers', tone: 'k-mustard', icon: 'shield', label: 'Active sellers', value: activeSellers.length, series: approved14, note: `${plural(sellers.length, 'account')} total` })}
      ${kpiCard({ view: 'sellers', tone: 'k-indigo', icon: 'clock', label: 'Pending verifications', value: waiting, series: queued14, invert: true, note: `${pendingSellers.length} sellers · ${pendingProducts.length} listings` })}
      ${kpiCard({ view: 'orders', icon: 'flag', label: 'Open disputes', value: statNum('disputes_open', disputes.length), series: disputes14, invert: true, note: statNum('disputes_open', disputes.length) ? 'payouts held' : 'all clear' })}
    </div>

    <div class="sd-ov">
      <div class="sd-ov-main">
        <div class="sd-charts">
          ${salesPanel(live, 'Platform GMV', 'Gross, all sellers')}
          ${categoryPanel(live30, 'Orders by category')}
        </div>
        ${recentOrdersPanel(subs)}
        <section class="sd-card">
          <div class="sd-card-head"><div><h2>Audit trail</h2><div class="sd-card-sub">Last 10 admin actions</div></div></div>
          ${adminFeed()}
        </section>
      </div>
      <aside class="sd-rail" aria-label="Insights">
        ${insightsPanel()}
        ${activityPanel(users, subs)}
        ${topSellersPanel(live30)}
      </aside>
    </div>`;
}

/* ---------- area chart (same drawing code as the seller console) ---------- */
function salesPanel(live, title, sub){
  const days = AD.chartDays;
  const rev = daySeries(live, days, o => o.placed_at, o => o.total || 0);
  const cnt = daySeries(live, days, o => o.placed_at, () => 1);
  const total = rev.reduce((s, b) => s + b.total, 0);
  const prev = daySeries(live, days * 2, o => o.placed_at, o => o.total || 0).slice(0, days).reduce((s, b) => s + b.total, 0);
  const d = prev ? Math.round(((total - prev) / prev) * 100) : null;
  return `<section class="sd-card">
    <div class="sd-card-head">
      <div><h2>${title}</h2><div class="sd-card-sub">${sub}</div></div>
      <div class="sd-seg" role="group" aria-label="Chart range">
        ${[7, 30].map(n => `<button class="${days === n ? 'on' : ''}" data-act="chart-days" data-days="${n}">${n}D</button>`).join('')}
      </div>
    </div>
    <div class="sd-chart-sum"><strong>${fmt(Math.round(total))}</strong>
      ${d === null ? `<span class="sd-trend-note">last ${days} days</span>` : `<span class="sd-trend ${d > 0 ? 'up' : d < 0 ? 'down' : 'flat'}">${d > 0 ? '▲' : d < 0 ? '▼' : ''} ${Math.abs(d)}%</span><span class="sd-trend-note">vs previous ${days} days</span>`}
    </div>
    ${areaChart(rev, cnt)}
    <div class="sd-legend"><span><i class="l-maroon"></i>Daily GMV</span><span>Hover a day for orders and revenue</span></div>
  </section>`;
}
function areaChart(buckets, counts){
  const W = 640, H = 230, padL = 46, padR = 10, padT = 14, padB = 28;
  const iw = W - padL - padR, ih = H - padT - padB;
  const vals = buckets.map(b => b.total);
  const nice = niceMax(Math.max(...vals, 1));
  const n = buckets.length;
  const x = i => padL + (n > 1 ? (i / (n - 1)) * iw : iw / 2);
  const y = v => padT + ih - (v / nice) * ih;
  const pts = vals.map((v, i) => [x(i), y(v)]);
  const clampY = v => Math.min(padT + ih, Math.max(padT, v));
  let d = `M${pts[0][0].toFixed(1)},${pts[0][1].toFixed(1)}`;
  for (let i = 0; i < pts.length - 1; i++){
    const p0 = pts[i - 1] || pts[i], p1 = pts[i], p2 = pts[i + 1], p3 = pts[i + 2] || p2;
    const c1 = [p1[0] + (p2[0] - p0[0]) / 6, clampY(p1[1] + (p2[1] - p0[1]) / 6)];
    const c2 = [p2[0] - (p3[0] - p1[0]) / 6, clampY(p2[1] - (p3[1] - p1[1]) / 6)];
    d += ` C${c1[0].toFixed(1)},${c1[1].toFixed(1)} ${c2[0].toFixed(1)},${c2[1].toFixed(1)} ${p2[0].toFixed(1)},${p2[1].toFixed(1)}`;
  }
  const base = (padT + ih).toFixed(1);
  const area = `${d} L${x(n - 1).toFixed(1)},${base} L${x(0).toFixed(1)},${base} Z`;
  const empty = vals.every(v => !v);
  const grid = [0, .25, .5, .75, 1].map(f => {
    const gy = (padT + ih - f * ih).toFixed(1);
    return `<line class="grid" x1="${padL}" x2="${W - padR}" y1="${gy}" y2="${gy}"/>${empty ? '' : `<text class="axis" x="${padL - 8}" y="${gy}" text-anchor="end" dominant-baseline="middle">${fmtAxis(nice * f, true)}</text>`}`;
  }).join('');
  const every = n <= 7 ? 1 : Math.ceil(n / 6);
  const xl = buckets.map((b, i) => (i % every === 0 || i === n - 1) && !(i !== n - 1 && n - 1 - i < every / 2)
    ? `<text class="axis" x="${x(i).toFixed(1)}" y="${H - 6}" text-anchor="middle">${escapeHtml(b.label)}</text>` : '').join('');
  const slot = n > 1 ? iw / (n - 1) : iw;
  const hits = buckets.map((b, i) => `<g>
      <rect class="hit" x="${(x(i) - slot / 2).toFixed(1)}" y="${padT}" width="${slot.toFixed(1)}" height="${ih}"
        data-x="${(x(i) / W * 100).toFixed(2)}" data-y="${(y(vals[i]) / H * 100).toFixed(2)}" data-label="${escapeHtml(b.label)}" data-v="${vals[i]}" data-n="${counts ? counts[i].total : ''}"/>
      <line class="guide" x1="${x(i).toFixed(1)}" x2="${x(i).toFixed(1)}" y1="${padT}" y2="${base}"/>
      <circle class="pt" cx="${x(i).toFixed(1)}" cy="${y(vals[i]).toFixed(1)}" r="4.5"/>
    </g>`).join('');
  return `<div class="sd-chart">
    <svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Daily values over the last ${n} days">
      <defs><linearGradient id="adAreaGrad" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0" style="stop-color:var(--maroon); stop-opacity:.24"/>
        <stop offset="1" style="stop-color:var(--mustard); stop-opacity:.02"/>
      </linearGradient></defs>
      ${grid}
      ${empty ? '' : `<path d="${area}" fill="url(#adAreaGrad)"/><path class="line" d="${d}"/>`}
      ${xl}${empty ? '' : hits}
      ${empty ? `<text class="empty" x="${padL + iw / 2}" y="${padT + ih / 2}" text-anchor="middle">No sales in this period yet — they'll chart here as orders arrive</text>` : ''}
    </svg>
    <div class="sd-tip" id="sdTip"></div>
  </div>`;
}

/* ---------- donut: any [label, value] rows, max 4 segments ---------- */
function donut(rowsIn, { centerLabel = 'LKR total', emptyNote = 'Nothing to chart yet.', money = true } = {}){
  let rows = rowsIn.filter(r => r[1] > 0).sort((a, b) => b[1] - a[1]);
  if (rows.length > 4) rows = rows.slice(0, 3).concat([['other', rows.slice(3).reduce((s, r) => s + r[1], 0)]]);
  const total = rows.reduce((s, r) => s + r[1], 0);
  const R = 62, C = 2 * Math.PI * R;
  let off = 0;
  const segs = rows.map(([c, v], i) => {
    const len = total ? (v / total) * C : 0;
    const gap = rows.length > 1 ? Math.min(3, len / 3) : 0;
    const s = `<circle class="seg seg-${i}" cx="80" cy="80" r="${R}" stroke-width="22" stroke-dasharray="${Math.max(0, len - gap).toFixed(2)} ${C.toFixed(2)}" stroke-dashoffset="${(-off).toFixed(2)}" transform="rotate(-90 80 80)"><title>${escapeHtml(cap(c))}: ${money ? fmt(Math.round(v)) : v}</title></circle>`;
    off += len;
    return s;
  }).join('');
  return `<div class="sd-donut">
    <svg viewBox="0 0 160 160" role="img" aria-label="Breakdown chart">
      <circle class="track" cx="80" cy="80" r="${R}" stroke-width="22"/>
      ${segs}
      <text class="c-v" x="80" y="78" text-anchor="middle">${total ? (money ? fmtAxis(total, true) : total) : '0'}</text>
      <text class="c-l" x="80" y="96" text-anchor="middle">${total ? centerLabel : 'nothing yet'}</text>
    </svg>
    ${rows.length ? `<div class="sd-donut-legend">${rows.map(([c, v], i) => `
      <div><i class="sw-${i}"></i><span>${escapeHtml(c)}</span><b>${Math.round((v / total) * 100)}%</b></div>`).join('')}</div>`
      : `<p class="sd-card-sub" style="text-align:center">${emptyNote}</p>`}
  </div>`;
}
function categoryPanel(live30, title){
  const byCat = {};
  live30.forEach(o => (o.items || []).forEach(l => { const c = catOf(l); byCat[c] = (byCat[c] || 0) + (Number(l.product && l.product.price) || 0) * (l.qty || 1); }));
  return `<section class="sd-card">
    <div class="sd-card-head"><div><h2>${title}</h2><div class="sd-card-sub">Revenue share, last 30 days</div></div></div>
    ${donut(Object.entries(byCat), { emptyNote: 'The category mix appears after the first sale.' })}
  </section>`;
}

/* ---------- recent orders (platform-wide) ---------- */
function recentOrdersPanel(subs){
  const list = subs.slice().sort((a, b) => new Date(b.placed_at) - new Date(a.placed_at)).slice(0, 6);
  return `<section class="sd-card">
    <div class="sd-card-head">
      <div><h2>Recent orders</h2><div class="sd-card-sub">Every seller — click one to manage it</div></div>
      <button class="sd-link" data-act="goto" data-view="orders" data-tab="all">View all ${SI.arrow}</button>
    </div>
    ${list.length ? `<div class="dtable"><table class="dt">
      <thead><tr><th>Order</th><th>Buyer</th><th>Seller</th><th>Date</th><th>Status</th><th class="num">Total</th></tr></thead>
      <tbody>${list.map(o => `<tr class="is-click" data-act="order-open" data-key="${escapeHtml(o.key)}">
        <td><div class="pcell">${orderThumb(o)}<div style="min-width:0"><div class="name">${itemsLine(o)}</div><span class="sub sd-mono">${escapeHtml(o.id)}</span></div></div></td>
        <td><div class="sd-cust">${personAv(shipOf(o).name)}<span>${escapeHtml(shipOf(o).name || userName(o.user_id))}</span></div></td>
        <td>${escapeHtml(sellerName(o.seller_id))}</td>
        <td style="white-space:nowrap">${shortDate(o.placed_at)}</td>
        <td>${statusPill(o.status)}</td>
        <td class="num"><strong>${fmt(o.total || 0)}</strong></td>
      </tr>`).join('')}</tbody></table></div>`
      : emptyBlock(DI.empty, 'No orders yet', 'Orders from every seller land here as soon as a customer checks out.')}
  </section>`;
}

/* ---------- audit trail ---------- */
function adminActionLabel(a){
  return ({
    approve_seller: `Approved seller <strong>${escapeHtml(sellerName(a.target_id))}</strong>`,
    reject_seller: `Rejected seller <strong>${escapeHtml(sellerName(a.target_id))}</strong>`,
    /* target_name comes back resolved from the server (a LEFT JOIN per
       target type), so a listing that has since been deleted still reads as
       an action rather than a bare id. */
    approve_product: `Approved listing <strong>${escapeHtml(a.target_name || a.target_id)}</strong>`,
    reject_product: `Rejected listing <strong>${escapeHtml(a.target_name || a.target_id)}</strong>`,
    takedown_product: `Took down listing <strong>${escapeHtml(a.target_name || a.target_id)}</strong>`,
    resolve_dispute: `Resolved a dispute on <strong>${escapeHtml(a.target_name || ('sub-order ' + a.target_id))}</strong>`,
    export_finance: `Exported the finance CSV`,
    create_faq: `Added an FAQ`,
    update_faq: `Edited an FAQ`,
    delete_faq: `Deleted an FAQ`,
    update_settings: `Updated platform settings`,
    suspend_user: `Suspended <strong>${escapeHtml(userName(a.target_id))}</strong>`,
    reactivate_user: `Reactivated <strong>${escapeHtml(userName(a.target_id))}</strong>`,
    set_commission: `Set commission for <strong>${escapeHtml(sellerName(a.target_id))}</strong>`,
    override_status: `Overrode status on <strong>${escapeHtml(a.target_id)}</strong>`,
    refund: `Refunded <strong>${escapeHtml(a.target_id)}</strong>`,
    open_dispute: `Opened dispute on <strong>${escapeHtml(a.target_id)}</strong>`,
    settings: `Updated platform settings`
  }[a.action_type] || escapeHtml(a.action_type));
}
function adminFeed(){
  const acts = ADB.actions().slice(0, 10);
  if (!acts.length) return `<p class="sd-card-sub">No admin actions recorded yet. Approvals, rejections, suspensions and overrides all land here.</p>`;
  return `<div class="sd-feed">${acts.map(a => `
    <div class="sd-feed-item"><span class="sd-feed-ico admin">${DI.shield}</span>
      <div class="sd-feed-copy">${adminActionLabel(a)}${a.notes ? ` <span class="muted">— ${escapeHtml(a.notes)}</span>` : ''}<span class="sd-feed-time">${timeAgo(a.created_at)}</span></div></div>`).join('')}</div>`;
}

/* ---------- platform insights (rotating, computed) ---------- */
function insightTips(){
  const tips = [];
  const subs = allSubOrders(), live = subs.filter(isLive);
  const ps = ADB.pendingSellers(), pp = ADB.pendingProducts();
  const oldest = [...ps, ...pp].sort((a, b) => new Date(a.created_at) - new Date(b.created_at))[0];
  if (oldest && hoursSince(oldest.created_at) > 24) tips.push({ html: `The oldest item in review has waited <b>${timeAgo(oldest.created_at).replace(' ago', '')}</b>. Sellers are promised a decision within 1–2 business days.`, view: ps.includes(oldest) ? 'sellers' : 'products', cta: 'Open the queue' });
  const disputes = subs.filter(o => o.status === 'disputed');
  if (disputes.length) tips.push({ html: `<b>${plural(disputes.length, 'dispute')}</b> ${disputes.length === 1 ? 'is' : 'are'} holding ${fmt(Math.round(disputes.reduce((s, o) => s + ADB.payoutFor(o).net, 0)))} of seller payouts. Resolve or refund to release it.`, view: 'orders', tab: 'disputed', cta: 'Review disputes' });
  const t = trendInfo(daySeries(live, 14, o => o.placed_at, o => o.total || 0).map(b => b.total));
  if (t.cls !== 'flat' && t.text !== '▲ new') tips.push({ html: `Platform GMV is <b>${t.cls === 'up' ? 'up' : 'down'} ${t.text.replace(/[▲▼] ?/, '')}</b> on last week.`, view: 'finance', cta: 'Open finance' });
  const out = ADB.products().filter(p => p.status === 'active' && !p.deleted_at && p.stock <= 0);
  if (out.length) tips.push({ html: `<b>${plural(out.length, 'live listing')}</b> ${out.length === 1 ? 'is' : 'are'} out of stock. The shopping AI skips them, so affected sellers lose visibility.`, view: 'products', cta: 'See listings' });
  const thin = ADB.products().filter(p => !p.deleted_at && p.status !== 'rejected' && Object.keys(p.specs || {}).length < 3);
  if (thin.length) tips.push({ html: `<b>${plural(thin.length, 'listing')}</b> ${thin.length === 1 ? 'has' : 'have'} fewer than 3 structured specs — the consultant can't compare ${thin.length === 1 ? 'it' : 'them'} properly.`, view: 'products', cta: 'Review listings' });
  const idle = ADB.sellers().filter(u => u.seller.kyc_status === 'verified' && !ADB.sellerProducts(u.id).some(p => p.status === 'active'));
  if (idle.length) tips.push({ html: `<b>${escapeHtml(idle[0].seller.business_name)}</b> is verified but has no live listings${idle.length > 1 ? ` (nor do ${idle.length - 1} others)` : ''}.`, view: 'sellers', cta: 'View sellers' });
  if (!tips.length) tips.push({ html: `Queues are clear and nothing is blocked. A good moment to review <b>platform settings</b> or the FAQ copy.`, view: 'settings', cta: 'Open settings' });
  return tips;
}
function insightsPanel(){
  const tips = insightTips();
  const i = AD.tipIdx % tips.length, tip = tips[i];
  return `<section class="sd-card sd-insight">
    <div class="sd-insight-head"><span class="sd-spark-badge">${SI.sparkle}</span><div><strong>Platform Insights</strong><span>Tip ${i + 1} of ${tips.length} · from live platform data</span></div></div>
    <p class="sd-insight-tip">${tip.html}</p>
    ${tips.length > 1 ? `<div class="sd-insight-dots">${tips.map((_, k) => `<button class="${k === i ? 'on' : ''}" data-act="tip" data-i="${k}" aria-label="Tip ${k + 1}"></button>`).join('')}</div>` : '<div style="height:14px"></div>'}
    <div class="sd-insight-acts">
      <button class="btn btn-dark sd-btn-ico" data-act="goto" data-view="${tip.view}" ${tip.tab ? `data-tab="${tip.tab}"` : ''}>${SI.arrow} ${tip.cta}</button>
      ${tips.length > 1 ? `<button class="btn btn-ghost" data-act="tip" data-i="${(i + 1) % tips.length}">Next tip</button>` : ''}
    </div>
  </section>`;
}

/* ---------- platform activity: derived from the real records ---------- */
function activityPanel(users, subs){
  const ev = [];
  users.forEach(u => {
    if (u.role === 'seller' && u.created_at) ev.push({ t: u.created_at, kind: 'product', icon: DI.shield, html: `New seller application: <strong>${escapeHtml(u.seller?.business_name || u.name)}</strong>` });
    else if (u.role === 'customer' && u.created_at) ev.push({ t: u.created_at, kind: 'status', icon: SI.user, html: `<strong>${escapeHtml(u.name)}</strong> joined as a customer` });
  });
  subs.forEach(o => {
    if (o.placed_at) ev.push({ t: o.placed_at, kind: 'order', icon: SI.cart, html: `Order <strong>${escapeHtml(o.id)}</strong> — ${fmt(o.total || 0)} to ${escapeHtml(sellerName(o.seller_id))}` });
    if (o.dispute?.opened_at) ev.push({ t: o.dispute.opened_at, kind: 'admin', icon: DI.flag, html: `Dispute opened on <strong>${escapeHtml(o.id)}</strong>` });
    if (o.payout_released_at) ev.push({ t: o.payout_released_at, kind: 'money', icon: DI.cash, html: `Payout released on <strong>${escapeHtml(o.id)}</strong>` });
  });
  /* `origin` was a db.js idea (seed vs seller-created). Every listing is a
     products row now and carries its own created_at, so the feed shows them
     all — which is what "submitted" meant anyway. */
  ADB.products().filter(p => p.created_at).forEach(p => ev.push({ t: p.created_at, kind: 'product', icon: DI.box, html: `<strong>${escapeHtml(p.title)}</strong> submitted by ${escapeHtml((p.seller && p.seller.name) || sellerName(p.seller_id))}` }));
  ev.sort((a, b) => new Date(b.t) - new Date(a.t));
  const top = ev.slice(0, 7);
  return `<section class="sd-card">
    <div class="sd-card-head"><div><h2>Recent activity</h2><div class="sd-card-sub">Sign-ups, orders, disputes and listings</div></div></div>
    ${top.length ? `<div class="sd-feed">${top.map(e => `
      <div class="sd-feed-item"><span class="sd-feed-ico ${e.kind}">${e.icon}</span>
        <div class="sd-feed-copy">${e.html}<span class="sd-feed-time">${timeAgo(e.t)}</span></div></div>`).join('')}</div>`
      : `<p class="sd-card-sub">Nothing yet — platform events appear here as they happen.</p>`}
  </section>`;
}

/* ---------- top sellers ---------- */
function topSellersPanel(live30){
  const agg = {};
  live30.forEach(o => { const a = agg[o.seller_id] || (agg[o.seller_id] = { id: o.seller_id, orders: 0, gmv: 0 }); a.orders++; a.gmv += o.total || 0; });
  const rows = Object.values(agg).sort((a, b) => b.gmv - a.gmv).slice(0, 5);
  const max = rows.length ? rows[0].gmv : 1;
  return `<section class="sd-card">
    <div class="sd-card-head"><div><h2>Top sellers</h2><div class="sd-card-sub">By GMV, last 30 days</div></div>
      <button class="sd-link" data-act="view" data-view="finance">All ${SI.arrow}</button></div>
    ${rows.length ? `<div class="sd-top-list">${rows.map((r, i) => `
      <div class="sd-top-item">
        <span class="sd-rank">${i + 1}</span>${personAv(sellerName(r.id))}
        <div style="min-width:0"><div class="sd-top-name" title="${escapeHtml(sellerName(r.id))}">${escapeHtml(sellerName(r.id))}</div><div class="sd-top-bar"><i style="width:${Math.max(6, Math.round(r.gmv / max * 100))}%"></i></div></div>
        <div class="sd-top-meta"><b>${fmtAxis(r.gmv, true)}</b><span>${plural(r.orders, 'order')}</span></div>
      </div>`).join('')}</div>`
      : `<p class="sd-card-sub">No sales in the last 30 days. The best-performing sellers will rank here.</p>`}
  </section>`;
}

/* ============================================================
   SELLER VERIFICATION
   ============================================================ */
function docLink(url){
  return url
    ? `<a class="doc-link" href="${escapeHtml(url)}" onclick="event.preventDefault(); toast('Document preview arrives with the file upload backend')">${DI.doc} ${escapeHtml(url.split('/').pop())}</a>`
    : '<span class="muted">none uploaded</span>';
}
function sellersView(){
  const all = ADB.sellers();
  const pending = ADB.pendingSellers().sort((a, b) => new Date(a.created_at) - new Date(b.created_at));
  const reviewed = all.filter(u => u.seller.kyc_status !== 'pending').sort((a, b) => new Date(b.seller.kyc_reviewed_at || 0) - new Date(a.seller.kyc_reviewed_at || 0));
  const verified = all.filter(u => u.seller.kyc_status === 'verified').length, rejected = all.filter(u => u.seller.kyc_status === 'rejected').length;
  const gmvOf = id => ADB.ordersBySeller(id).filter(isLive).reduce((s, o) => s + (o.total || 0), 0);

  return `
    <div class="sd-kpis cols-3">
      ${kpiCard({ tone: 'k-mustard', icon: 'clock', label: 'Awaiting verification', value: pending.length, note: pending.length ? `oldest ${timeAgo(pending[0].created_at)}` : 'queue is clear' })}
      ${kpiCard({ tone: 'k-indigo', icon: 'shield', label: 'Verified sellers', value: verified, note: `${plural(all.length, 'seller account')} total` })}
      ${kpiCard({ icon: 'flag', label: 'Rejected', value: rejected, note: 'can fix and reapply' })}
    </div>

    <section class="sd-card" style="margin-bottom:var(--sd-gap)">
      <div class="sd-card-head"><div><h2>Verification queue</h2><div class="sd-card-sub">${pending.length} waiting · oldest first · NIC and business registration checked before approval</div></div></div>
      ${pending.length ? `<div class="dtable"><table class="dt">
        <thead><tr><th>Business</th><th>Owner</th><th>Identity</th><th>Payout account</th><th>Documents</th><th>Submitted</th><th class="acts">Decision</th></tr></thead>
        <tbody>${pending.map(u => `<tr>
          <td><div class="sd-cust">${personAv(u.seller.business_name)}<div style="min-width:0"><div class="name">${escapeHtml(u.seller.business_name)}</div><span class="sub">${escapeHtml(u.seller.business_type.replace('_', ' '))} · ${escapeHtml(u.seller.city || '—')} · ${escapeHtml(u.seller.primary_category || '')}</span></div></div></td>
          <td>${escapeHtml(u.name)}<span class="sub">${escapeHtml(u.email)}</span><span class="sub">${escapeHtml(u.phone || '—')}</span></td>
          <td><div class="ad-kv-inline" style="flex-direction:column">NIC <b>${escapeHtml(u.seller.nic_number || '—')}</b>Reg. <b>${u.seller.registration_no ? escapeHtml(u.seller.registration_no) : '<span class="ad-warn">not provided</span>'}</b></div></td>
          <td>${escapeHtml(u.seller.bank_name || '—')}<span class="sub sd-mono">${escapeHtml(u.seller.account_number || '—')}${u.seller.branch ? ' · ' + escapeHtml(u.seller.branch) : ''}</span></td>
          <td>${docLink(u.seller.kyc_document_url)}</td>
          <td style="white-space:nowrap">${timeAgo(u.created_at)}</td>
          <td class="acts"><div class="sd-row-acts">
            <button class="btn btn-ghost" data-act="seller-reject" data-id="${escapeHtml(u.id)}" style="color:var(--maroon)">Reject…</button>
            <button class="btn btn-olive sd-btn-ico" data-act="seller-approve" data-id="${escapeHtml(u.id)}">${DI.check} Approve</button>
          </div></td>
        </tr>`).join('')}</tbody></table></div>`
        : emptyBlock(DI.check, 'Queue is clear', "New seller applications appear here the moment they're submitted.")}
    </section>

    <section class="sd-card">
      <div class="sd-card-head"><div><h2>All sellers</h2><div class="sd-card-sub">${reviewed.length} reviewed · commission, GMV and status per seller</div></div></div>
      ${reviewed.length ? `<div class="dtable"><table class="dt">
        <thead><tr><th>Business</th><th>Owner</th><th>KYC</th><th class="num">Commission</th><th class="num">Listings</th><th class="num">GMV</th><th>Reviewed</th><th class="acts">Actions</th></tr></thead>
        <tbody>${reviewed.map(u => `<tr>
          <td><div class="sd-cust">${personAv(u.seller.business_name)}<div style="min-width:0"><div class="name">${escapeHtml(u.seller.business_name)}</div><span class="sub">${escapeHtml(u.seller.city || '')}</span></div></div></td>
          <td>${escapeHtml(u.name)}<span class="sub">${escapeHtml(u.email)}</span></td>
          <td>${statusPill(u.seller.kyc_status)}${u.status === 'suspended' ? ' ' + statusPill('suspended') : ''}${u.seller.kyc_rejection_reason ? `<span class="sub">${escapeHtml(u.seller.kyc_rejection_reason)}</span>` : ''}</td>
          <td class="num">${pct(ADB.commissionRateFor(u.id))}<span class="sub">${u.seller.commission_rate == null ? 'default' : 'custom'}</span></td>
          <td class="num">${ADB.sellerProducts(u.id).filter(p => p.status === 'active').length}</td>
          <td class="num">${fmt(Math.round(gmvOf(u.id)))}</td>
          <td style="white-space:nowrap">${shortDate(u.seller.kyc_reviewed_at)}</td>
          <td class="acts"><div class="sd-row-acts">
            <button class="btn btn-ghost" data-act="seller-commission" data-id="${escapeHtml(u.id)}">Rate…</button>
            ${u.seller.kyc_status === 'rejected' ? `<button class="btn btn-ghost" data-act="seller-approve" data-id="${escapeHtml(u.id)}">Approve</button>` : ''}
            <button class="sd-sq" data-act="user-open" data-id="${escapeHtml(u.id)}" title="View seller" aria-label="View ${escapeHtml(u.seller.business_name)}">${SI.eye}</button>
          </div></td>
        </tr>`).join('')}</tbody></table></div>`
        : emptyBlock(DI.users, 'No reviewed sellers yet', 'Approved and rejected sellers are listed here.')}
    </section>`;
}

/* ============================================================
   PRODUCT MODERATION
   ============================================================ */
function productsView(){
  const all = ADB.products().filter(p => !p.deleted_at);
  const pending = ADB.pendingProducts().sort((a, b) => new Date(a.created_at) - new Date(b.created_at));
  const recent = all.filter(p => p.reviewed_at).sort((a, b) => new Date(b.reviewed_at) - new Date(a.reviewed_at)).slice(0, 12);
  const live = all.filter(p => p.status === 'active');

  return `
    <div class="sd-kpis cols-3">
      ${kpiCard({ tone: 'k-mustard', icon: 'clock', label: 'Awaiting moderation', value: pending.length, note: pending.length ? `oldest ${timeAgo(pending[0].created_at)}` : 'queue is clear' })}
      ${kpiCard({ tone: 'k-indigo', icon: 'box', label: 'Live listings', value: live.length, note: `${plural(all.length, 'listing')} incl. pending` })}
      ${kpiCard({ icon: 'flag', label: 'Rejected / taken down', value: all.filter(p => p.status === 'rejected').length, note: 'sellers see the reason' })}
    </div>

    <section class="sd-card" style="margin-bottom:var(--sd-gap)">
      <div class="sd-card-head"><div><h2>Moderation queue</h2><div class="sd-card-sub">${pending.length} waiting · preview shows the listing as a shopper sees it</div></div></div>
      ${pending.length ? `<div class="dtable"><table class="dt">
        <thead><tr><th>Listing</th><th>Seller</th><th class="num">Price</th><th>Specs</th><th>Submitted</th><th class="acts">Decision</th></tr></thead>
        <tbody>${pending.map(p => { const nSpecs = Object.keys(p.specs || {}).length; return `<tr>
          <td><div class="pcell">${smallThumb(p)}<div style="min-width:0"><div class="name">${escapeHtml(p.title)}</div><span class="sub">${escapeHtml(p.category)} · stock ${p.stock}${p.subtitle ? ' · ' + escapeHtml(p.subtitle) : ''}</span></div></div></td>
          <td>${escapeHtml(sellerName(p.seller_id))}</td>
          <td class="num">${fmt(p.price)}${p.old_price ? `<span class="sub">was ${fmt(p.old_price)}</span>` : ''}</td>
          <td>${nSpecs >= 3 ? `<span class="stock-tag ok">${nSpecs} specs</span>` : `<span class="stock-tag ${nSpecs ? 'low' : 'out'}">${nSpecs || 'no'} spec${nSpecs === 1 ? '' : 's'}</span>`}</td>
          <td style="white-space:nowrap">${timeAgo(p.created_at)}</td>
          <td class="acts"><div class="sd-row-acts">
            <button class="sd-sq" data-act="product-preview" data-id="${escapeHtml(p.id)}" title="Preview" aria-label="Preview ${escapeHtml(p.title)}">${SI.eye}</button>
            <button class="btn btn-ghost" data-act="product-reject" data-id="${escapeHtml(p.id)}" style="color:var(--maroon)">Reject…</button>
            <button class="btn btn-olive sd-btn-ico" data-act="product-approve" data-id="${escapeHtml(p.id)}">${DI.check} Approve</button>
          </div></td>
        </tr>`; }).join('')}</tbody></table></div>`
        : emptyBlock(DI.check, 'Nothing to moderate', 'New and re-submitted listings appear here.')}
    </section>

    <section class="sd-card">
      <div class="sd-card-head"><div><h2>Live &amp; recently reviewed</h2><div class="sd-card-sub">Take a listing down if it breaks the rules — the seller sees why and can fix it</div></div></div>
      ${live.length || recent.length ? `<div class="dtable"><table class="dt">
        <thead><tr><th>Listing</th><th>Seller</th><th class="num">Price</th><th class="num">Stock</th><th>Status</th><th>Reviewed</th><th class="acts">Actions</th></tr></thead>
        <tbody>${[...new Map([...recent, ...live].map(p => [p.id, p])).values()].slice(0, 20).map(p => `<tr>
          <td><div class="pcell">${smallThumb(p)}<div style="min-width:0"><div class="name">${escapeHtml(p.title)}</div><span class="sub">${escapeHtml(p.category)}</span></div></div></td>
          <td>${escapeHtml(sellerName(p.seller_id))}</td>
          <td class="num">${fmt(ADB.effectivePrice(p).price)}</td>
          <td class="num"><span class="stock-tag ${p.stock <= 0 ? 'out' : p.stock <= 5 ? 'low' : 'ok'}">${p.stock}</span></td>
          <td>${statusPill(p.status)}${p.rejection_reason ? `<span class="sub">${escapeHtml(p.rejection_reason)}</span>` : ''}</td>
          <td style="white-space:nowrap">${p.reviewed_at ? timeAgo(p.reviewed_at) : '<span class="muted">catalogue seed</span>'}</td>
          <td class="acts"><div class="sd-row-acts">
            <button class="sd-sq" data-act="product-preview" data-id="${escapeHtml(p.id)}" title="Preview" aria-label="Preview ${escapeHtml(p.title)}">${SI.eye}</button>
            ${p.status === 'active' ? `<button class="btn btn-ghost" data-act="product-takedown" data-id="${escapeHtml(p.id)}" style="color:var(--maroon)">Take down…</button>` : ''}
            ${p.status === 'rejected' ? `<button class="btn btn-ghost" data-act="product-approve" data-id="${escapeHtml(p.id)}">Approve</button>` : ''}
          </div></td>
        </tr>`).join('')}</tbody></table></div>`
        : emptyBlock(DI.box, 'No listings yet', 'Approved listings appear here.')}
    </section>`;
}
function productPreview(id){
  const p = ADB.product(id);
  if (!p){ toast("That listing couldn't be found"); return; }
  closePops();
  modalClass('sd-modal-wide');
  openDashModal(`
    <div class="sd-od-head">
      <div><h3>${escapeHtml(p.title)}</h3><div class="sd-card-sub">by ${escapeHtml(sellerName(p.seller_id))} · ${escapeHtml(p.category)} · ${p.created_at ? 'submitted ' + timeAgo(p.created_at) : 'catalogue seed'}</div></div>
      <div>${statusPill(p.status)}</div>
    </div>
    <div class="sd-od-body" style="display:block">
      <div class="ad-preview">
        <div>${productCard(ADB.toCatalogShape(p))}</div>
        <dl class="kv" style="margin-top:0">
          <dt>Price</dt><dd class="mono">${fmt(p.price)}${p.old_price ? ` <span class="muted">was ${fmt(p.old_price)}</span>` : ''}</dd>
          <dt>Stock</dt><dd class="mono">${p.stock}</dd>
          <dt>Description</dt><dd>${escapeHtml(p.description || '—')}</dd>
          <dt>Specs</dt><dd>${Object.keys(p.specs || {}).length ? Object.entries(p.specs).map(([k, v]) => `<span class="mono" style="font-size:11.5px">${escapeHtml(k)}: ${escapeHtml(v)}</span>`).join(' · ') : '<span class="muted">none — worth rejecting for; the AI can\'t reason over unstructured listings</span>'}</dd>
          <dt>Images</dt><dd>${p.images?.length ? p.images.map(escapeHtml).join('<br>') : '<span class="muted">none (icon placeholder)</span>'}</dd>
          ${p.sale ? `<dt>Sale</dt><dd>${fmt(p.sale.sale_price)} from ${escapeHtml(p.sale.start_date)} to ${escapeHtml(p.sale.end_date)}</dd>` : ''}
          ${p.rejection_reason ? `<dt>Reason</dt><dd>${escapeHtml(p.rejection_reason)}</dd>` : ''}
        </dl>
      </div>
    </div>
    <div class="sd-od-foot">
      <button type="button" class="btn btn-ghost btn-sm" data-act="modal-close">Close</button>
      ${p.status === 'pending' ? `<button type="button" class="btn btn-ghost btn-sm" data-act="product-reject" data-id="${escapeHtml(p.id)}" style="color:var(--maroon)">Reject…</button>
        <button type="button" class="btn btn-olive btn-sm sd-btn-ico" data-act="product-approve" data-id="${escapeHtml(p.id)}">${DI.check} Approve &amp; publish</button>` : ''}
      ${p.status === 'active' ? `<button type="button" class="btn btn-danger btn-sm" data-act="product-takedown" data-id="${escapeHtml(p.id)}">Take down…</button>` : ''}
    </div>`);
}

/* ============================================================
   USERS
   ============================================================ */
function usersView(){
  if (AD.userDetail) return userDetailView(AD.userDetail);
  const q = AD.uquery.trim().toLowerCase();
  const all = ADB.users();
  let users = all.slice().sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
  if (AD.urole !== 'all') users = users.filter(u => AD.urole === 'suspended' ? u.status === 'suspended' : u.role === AD.urole);
  if (q) users = users.filter(u => [u.name, u.email, u.phone, u.seller?.business_name].filter(Boolean).some(s => s.toLowerCase().includes(q)));
  const tabs = [['all', 'All', all.length], ['customer', 'Customers', all.filter(u => u.role === 'customer').length], ['seller', 'Sellers', all.filter(u => u.role === 'seller').length], ['admin', 'Admins', all.filter(u => u.role === 'admin').length], ['suspended', 'Suspended', all.filter(u => u.status === 'suspended').length]];
  const new30 = all.filter(u => withinDays(u.created_at, 30)).length;

  return `
    <div class="sd-kpis">
      ${kpiCard({ filter: { act: 'urole', f: 'all' }, icon: 'users', label: 'All users', value: all.length, series: daySeries(all, 14, u => u.created_at, () => 1).map(b => b.total), note: `${new30} joined in 30 days` })}
      ${kpiCard({ filter: { act: 'urole', f: 'customer' }, tone: 'k-mustard', icon: 'user', label: 'Customers', value: tabs[1][2], note: 'shop and check out' })}
      ${kpiCard({ filter: { act: 'urole', f: 'seller' }, tone: 'k-indigo', icon: 'shield', label: 'Sellers', value: tabs[2][2], note: `${ADB.pendingSellers().length} awaiting KYC` })}
      ${kpiCard({ filter: { act: 'urole', f: 'suspended' }, icon: 'flag', label: 'Suspended', value: tabs[4][2], note: 'signed out, blocked' })}
    </div>
    <section class="sd-card">
      <div class="sd-card-head"><div><h2>Users</h2><div class="sd-card-sub">Customers, sellers and admins share one table</div></div></div>
      <div class="dtabs">${tabs.map(([k, l, n]) => `<button class="dtab${AD.urole === k ? ' on' : ''}" data-act="urole" data-f="${k}">${l}<span class="dtab-n">${n}</span></button>`).join('')}</div>
      <div class="sd-toolbar"><div class="dsearch"><span class="dsearch-ico">${DI.search}</span><input id="adUSearch" type="search" placeholder="Search name, email, phone, business…" value="${escapeHtml(AD.uquery)}" aria-label="Search users"></div></div>
      ${users.length ? `<div class="dtable"><table class="dt">
        <thead><tr><th>User</th><th>Role</th><th>Phone</th><th>Joined</th><th>Status</th><th class="acts">Actions</th></tr></thead>
        <tbody>${users.map(u => `<tr class="is-click" data-act="user-open" data-id="${escapeHtml(u.id)}">
          <td><div class="sd-cust">${u.id === AD.me.id ? avatarHtml('') : personAv(u.name)}<div style="min-width:0"><div class="name">${escapeHtml(u.name)}${u.id === AD.me.id ? ' <span class="muted" style="font-weight:400">(you)</span>' : ''}</div><span class="sub">${escapeHtml(u.email)}${u.seller ? ' · ' + escapeHtml(u.seller.business_name) : ''}</span></div></div></td>
          <td>${statusPill(u.role)}${u.seller ? ' ' + statusPill(u.seller.kyc_status) : ''}</td>
          <td><span class="sd-mono">${escapeHtml(u.phone || '—')}</span></td>
          <td style="white-space:nowrap">${shortDate(u.created_at)}</td>
          <td>${statusPill(u.status)}</td>
          <td class="acts"><div class="sd-row-acts">
            ${u.id === AD.me.id ? '' : u.status === 'suspended'
              ? `<button class="btn btn-ghost" data-act="user-reactivate" data-id="${escapeHtml(u.id)}">Reactivate</button>`
              : `<button class="btn btn-ghost" style="color:var(--maroon)" data-act="user-suspend" data-id="${escapeHtml(u.id)}">Suspend…</button>`}
            <button class="sd-sq" data-act="user-open" data-id="${escapeHtml(u.id)}" title="View" aria-label="View ${escapeHtml(u.name)}">${SI.eye}</button>
          </div></td>
        </tr>`).join('')}</tbody></table></div>`
        : emptyBlock(DI.search, 'No users match', 'Try a different tab or clear the search.', `<button class="btn btn-ghost btn-sm" data-act="urole" data-f="all" data-clear="1">Clear filters</button>`)}
    </section>`;
}
function userDetailView(id){
  const u = ADB.user(id);
  if (!u) return `<section class="sd-card">${emptyBlock(DI.search, 'User not found', 'They may have been removed.', `<button class="btn btn-ghost btn-sm" data-act="user-close">← All users</button>`)}</section>`;
  const bought = ADB.ordersByUser(u.id), sold = u.role === 'seller' ? ADB.ordersBySeller(u.id) : [];
  const listings = u.role === 'seller' ? ADB.sellerProducts(u.id) : [];
  const orderTable = (rows, who) => rows.length ? `<div class="dtable"><table class="dt"><thead><tr><th>Sub-order</th><th>${who}</th><th class="num">Total</th><th>Status</th><th>Placed</th></tr></thead><tbody>${rows.map(o => `<tr class="is-click" data-act="order-open" data-key="${escapeHtml(o.key)}"><td><div class="pcell">${orderThumb(o)}<div style="min-width:0"><div class="name">${itemsLine(o)}</div><span class="sub sd-mono">${escapeHtml(o.id)}</span></div></div></td><td>${escapeHtml(who === 'Seller' ? sellerName(o.seller_id) : (shipOf(o).name || userName(o.user_id)))}</td><td class="num">${fmt(o.total || 0)}</td><td>${statusPill(o.status)}</td><td style="white-space:nowrap">${shortDate(o.placed_at)}</td></tr>`).join('')}</tbody></table></div>` : `<p class="sd-card-sub">None yet.</p>`;
  const spent = bought.filter(isLive).reduce((s, o) => s + (o.total || 0), 0), earned = sold.filter(isLive).reduce((s, o) => s + (o.total || 0), 0);
  return `
    <section class="sd-card" style="margin-bottom:var(--sd-gap)">
      <div class="sd-card-head">
        <button class="sd-link" data-act="user-close" style="color:var(--gray)">← All users</button>
        <div class="sd-card-actions">
          ${u.role === 'seller' ? `<button class="btn btn-ghost btn-sm" data-act="seller-commission" data-id="${escapeHtml(u.id)}">Commission…</button>` : ''}
          ${u.id === AD.me.id ? '' : u.status === 'suspended' ? `<button class="btn btn-olive btn-sm" data-act="user-reactivate" data-id="${escapeHtml(u.id)}">Reactivate</button>` : `<button class="btn btn-danger btn-sm" data-act="user-suspend" data-id="${escapeHtml(u.id)}">Suspend…</button>`}
        </div>
      </div>
      <div class="ad-profile">
        ${u.id === AD.me.id ? avatarHtml('xl') : personAv(u.seller?.business_name || u.name, 'xl')}
        <div><h2>${escapeHtml(u.seller?.business_name || u.name)}</h2>
          <div class="ad-profile-meta">${statusPill(u.role)} ${statusPill(u.status)} ${u.seller ? statusPill(u.seller.kyc_status) : ''} <span>joined ${shortDate(u.created_at)}</span></div></div>
      </div>
      <dl class="kv">
        ${u.seller ? `<dt>Owner</dt><dd>${escapeHtml(u.name)}</dd>` : ''}
        <dt>Email</dt><dd>${escapeHtml(u.email)}</dd><dt>Phone</dt><dd>${escapeHtml(u.phone || '—')}</dd><dt>ID</dt><dd class="mono">${escapeHtml(u.id)}</dd>
        ${u.seller ? `<dt>Location</dt><dd>${escapeHtml(u.seller.city || '—')}${u.seller.district ? ', ' + escapeHtml(u.seller.district) : ''}</dd>
          <dt>KYC</dt><dd>${statusPill(u.seller.kyc_status)}${u.seller.kyc_rejection_reason ? ' ' + escapeHtml(u.seller.kyc_rejection_reason) : ''}</dd>
          <dt>NIC</dt><dd class="mono">${escapeHtml(u.seller.nic_number || '—')}</dd>
          <dt>Bank</dt><dd>${escapeHtml(u.seller.bank_name || '—')} · <span class="mono">${escapeHtml(u.seller.account_number || '—')}</span></dd>
          <dt>Documents</dt><dd>${docLink(u.seller.kyc_document_url)}</dd>
          <dt>Commission</dt><dd>${pct(ADB.commissionRateFor(u.id))} <span class="muted">${u.seller.commission_rate == null ? '(platform default)' : '(custom)'}</span></dd>` : ''}
      </dl>
    </section>
    <div class="sd-kpis${u.role === 'seller' ? '' : ' ad-kpis-2'}">
      ${kpiCard({ tone: 'k-mustard', icon: 'cart', label: 'Orders as buyer', value: bought.length, note: `${fmt(Math.round(spent))} spent` })}
      ${kpiCard({ tone: 'k-indigo', icon: 'flag', label: 'Disputes', value: [...bought, ...sold].filter(o => o.dispute).length, note: 'as buyer or seller' })}
      ${u.role === 'seller' ? kpiCard({ icon: 'cash', label: 'Sales GMV', value: kpiMoney(earned), note: `${plural(sold.length, 'sub-order')}` }) + kpiCard({ tone: 'k-mustard', icon: 'box', label: 'Live listings', value: listings.filter(p => p.status === 'active').length, note: `${plural(listings.length, 'listing')} total` }) : ''}
    </div>
    <section class="sd-card" style="margin-bottom:var(--sd-gap)"><div class="sd-card-head"><div><h2>Order history</h2><div class="sd-card-sub">${bought.length} as buyer</div></div></div>${orderTable(bought, 'Seller')}</section>
    ${u.role === 'seller' ? `<section class="sd-card" style="margin-bottom:var(--sd-gap)"><div class="sd-card-head"><div><h2>Sales</h2><div class="sd-card-sub">${sold.length} sub-orders as seller</div></div></div>${orderTable(sold, 'Buyer')}</section>
    <section class="sd-card"><div class="sd-card-head"><div><h2>Listings</h2><div class="sd-card-sub">${listings.length}</div></div></div>${listings.length ? `<div class="dtable"><table class="dt"><thead><tr><th>Listing</th><th class="num">Price</th><th class="num">Stock</th><th>Status</th><th class="acts"></th></tr></thead><tbody>${listings.map(p => `<tr><td><div class="pcell">${smallThumb(p)}<span class="name">${escapeHtml(p.title)}</span></div></td><td class="num">${fmt(p.price)}</td><td class="num">${p.stock}</td><td>${statusPill(p.status)}</td><td class="acts"><button class="sd-sq" data-act="product-preview" data-id="${escapeHtml(p.id)}" title="Preview" aria-label="Preview">${SI.eye}</button></td></tr>`).join('')}</tbody></table></div>` : '<p class="sd-card-sub">No listings.</p>'}</section>` : ''}`;
}

/* ============================================================
   ORDERS & DISPUTES
   ============================================================ */
function ordersView(){
  const subs = allSubOrders().sort((a, b) => new Date(b.placed_at) - new Date(a.placed_at));
  const counts = { all: subs.length }; [...FLOW, ...TERMINAL].forEach(s => { counts[s] = subs.filter(o => o.status === s).length; });
  let list = AD.otab === 'all' ? subs : subs.filter(o => o.status === AD.otab);
  const q = AD.oquery.trim().toLowerCase();
  if (q) list = list.filter(o => [o.id, o.order_id, shipOf(o).name, sellerName(o.seller_id)].filter(Boolean).some(s => s.toLowerCase().includes(q)) || (o.items || []).some(l => safeProduct(l.product).title.toLowerCase().includes(q)));
  const tabs = [['disputed', 'Disputed'], ['all', 'All'], ...FLOW.map(s => [s, cap(s)]), ['cancelled', 'Cancelled']];
  const pages = Math.max(1, Math.ceil(list.length / PAGE_SIZE));
  AD.opage = Math.min(Math.max(1, AD.opage), pages);
  const from = (AD.opage - 1) * PAGE_SIZE, rows = list.slice(from, from + PAGE_SIZE);
  const open = subs.filter(o => FLOW.includes(o.status) && o.status !== 'delivered').length;
  const held = subs.filter(o => o.status === 'disputed').reduce((s, o) => s + ADB.payoutFor(o).net, 0);

  return `
    <div class="sd-kpis">
      ${kpiCard({ filter: { act: 'otab', f: 'all' }, icon: 'orders', label: 'All sub-orders', value: statNum('sub_orders_total', subs.length), series: daySeries(subs, 14, o => o.placed_at, () => 1).map(b => b.total), note: subs.length < statNum('sub_orders_total', subs.length) ? `newest ${subs.length} listed below` : '' })}
      ${kpiCard({ filter: { act: 'otab', f: 'pending' }, tone: 'k-mustard', icon: 'clock', label: 'In fulfilment', value: statNum('orders_open', open), note: `${counts.pending} pending · ${counts.shipped} shipped` })}
      ${kpiCard({ filter: { act: 'otab', f: 'delivered' }, tone: 'k-indigo', icon: 'check', label: 'Delivered', value: statNum('orders_delivered', counts.delivered), note: 'payouts released' })}
      ${kpiCard({ filter: { act: 'otab', f: 'disputed' }, icon: 'flag', label: 'Open disputes', value: statNum('disputes_open', counts.disputed), note: held ? `${fmt(Math.round(held))} held` : 'all clear' })}
    </div>
    <section class="sd-card">
      <div class="sd-card-head"><div><h2>Orders &amp; disputes</h2><div class="sd-card-sub">Platform-wide · every seller · click a row to manage it</div></div></div>
      <div class="dtabs">${tabs.map(([k, l]) => `<button class="dtab${AD.otab === k ? ' on' : ''}" data-act="otab" data-f="${k}">${l}<span class="dtab-n">${counts[k]}</span></button>`).join('')}</div>
      <div class="sd-toolbar"><div class="dsearch"><span class="dsearch-ico">${DI.search}</span><input id="adOSearch" type="search" placeholder="Search order, buyer, seller, product…" value="${escapeHtml(AD.oquery)}" aria-label="Search orders"></div></div>
      ${list.length ? `<div class="dtable"><table class="dt">
        <thead><tr><th>Order</th><th>Buyer</th><th>Seller</th><th>Placed</th><th>Status</th><th class="num">Total</th><th class="acts">Actions</th></tr></thead>
        <tbody>${rows.map(o => `<tr class="is-click${o.status === 'disputed' ? ' is-out' : ''}" data-act="order-open" data-key="${escapeHtml(o.key)}">
          <td><div class="pcell">${orderThumb(o)}<div style="min-width:0"><div class="name">${itemsLine(o)}</div><span class="sub sd-mono">${escapeHtml(o.id)} · ${escapeHtml(o.order_id)}</span></div></div></td>
          <td><div class="sd-cust">${personAv(shipOf(o).name)}<div style="min-width:0"><div>${escapeHtml(shipOf(o).name || userName(o.user_id))}</div><span class="sub">${escapeHtml(shipOf(o).city || '')}</span></div></div></td>
          <td>${escapeHtml(sellerName(o.seller_id))}</td>
          <td style="white-space:nowrap">${shortDate(o.placed_at)}<span class="sub">${timeAgo(o.placed_at)}</span></td>
          <td><div class="sd-status-cell">${statusPill(o.status)}${o.refunded_at ? statusPill('refunded') : ''}<small>updated ${timeAgo(o.status_updated_at)}</small></div></td>
          <td class="num"><strong>${fmt(o.total || 0)}</strong><span class="sub">${fmt(ADB.payoutFor(o).commission)} fee</span></td>
          <td class="acts"><div class="sd-row-acts"><button class="btn ${o.status === 'disputed' ? 'btn-primary' : 'btn-ghost'}" data-act="order-open" data-key="${escapeHtml(o.key)}">${o.status === 'disputed' ? 'Resolve' : 'Manage'}</button></div></td>
        </tr>`).join('')}</tbody></table></div>
        <div class="sd-pager">
          <span>Showing ${from + 1}–${from + rows.length} of ${list.length}</span>
          ${pages > 1 ? `<div class="sd-pager-btns">
            <button data-act="opage" data-p="${AD.opage - 1}" ${AD.opage === 1 ? 'disabled' : ''} aria-label="Previous page">‹</button>
            ${Array.from({ length: pages }, (_, i) => i + 1).filter(p => p === 1 || p === pages || Math.abs(p - AD.opage) <= 1)
              .map((p, i, arr) => `${i && p - arr[i - 1] > 1 ? '<span style="padding:0 4px">…</span>' : ''}<button class="${p === AD.opage ? 'on' : ''}" data-act="opage" data-p="${p}">${p}</button>`).join('')}
            <button data-act="opage" data-p="${AD.opage + 1}" ${AD.opage === pages ? 'disabled' : ''} aria-label="Next page">›</button>
          </div>` : ''}
        </div>`
        : emptyBlock(AD.otab === 'disputed' ? DI.check : DI.search, AD.otab === 'disputed' ? 'No open disputes' : 'No orders here', subs.length ? 'Try another tab or search.' : 'Orders appear as soon as a customer checks out.')}
    </section>`;
}

/* order detail — the same modal pattern as the seller console, plus admin powers */
const STEP_LABEL = { pending: 'Order placed', packed: 'Packed', shipped: 'Shipped', delivered: 'Delivered — payout released' };
const PAY_LABEL = { escrow: 'Held · escrow', held: 'Held · dispute', released: 'Released', refunded: 'Refunded', cancelled: 'Cancelled' };
const payPill = state => `<span class="status-pill st-${escapeHtml(state)}">${escapeHtml(PAY_LABEL[state] || state)}</span>`;
function timelineHtml(o){
  const log = o.status_log || [];
  const at = s => { for (let i = log.length - 1; i >= 0; i--) if (log[i].status === s) return log[i]; return null; };
  const idx = FLOW.indexOf(o.status);
  const reached = idx >= 0 ? idx : Math.max(0, ...log.map(l => FLOW.indexOf(l.status)));
  const rows = FLOW.map((s, i) => {
    const e = at(s);
    const when = e ? e.changed_at : (i === 0 ? o.placed_at : null);
    const cls = i < reached || (i === reached && (idx < 0 || s === 'delivered')) ? 'done' : i === reached ? 'now' : 'todo';
    return `<li class="${cls}"><strong>${STEP_LABEL[s]}</strong><span>${when ? `${shortDate(when)} · ${timeAgo(when)}${e ? ' · by ' + escapeHtml(e.changed_by) : ''}` : cls === 'todo' ? 'Not yet' : ''}</span></li>`;
  });
  if (idx < 0){
    const e = at(o.status);
    rows.push(`<li class="bad"><strong>${escapeHtml(cap(o.status))}</strong><span>${e ? `${shortDate(e.changed_at)} · ${timeAgo(e.changed_at)} · by ${escapeHtml(e.changed_by)}${e.note ? ' · ' + escapeHtml(e.note) : ''}` : ''}</span></li>`);
  }
  return `<ul class="sd-timeline">${rows.join('')}</ul>`;
}
function modalClass(extra){ const b = $('#dashModalBox'); if (b) b.className = 'modal-box dmodal' + (extra ? ' ' + extra : ''); }
const modalOpen = () => $('#dashModal')?.classList.contains('show');

function openOrder(key){
  const o = allSubOrders().find(x => x.key === key);
  if (!o){ toast("That order couldn't be found"); return; }
  closePops();
  AD.openKey = key;
  const pay = ADB.payoutFor(o), ship = shipOf(o);
  const canRefund = !o.refunded_at;
  modalClass('sd-modal-wide');
  openDashModal(`
    <div class="sd-od-head">
      <div><h3>${escapeHtml(o.id)}</h3><div class="sd-card-sub">Part of ${escapeHtml(o.order_id)} · placed ${shortDate(o.placed_at)} (${timeAgo(o.placed_at)}) · sold by ${escapeHtml(sellerName(o.seller_id))}</div></div>
      <div style="display:flex; gap:6px; align-items:center">${statusPill(o.status)}${payPill(pay.state)}</div>
    </div>
    <div class="sd-od-body">
      <div>
        <div class="sd-od-sec"><div class="sd-od-label">Items</div>
          <div class="sd-od-items">${(o.items || []).map(l => { const p = safeProduct(l.product); return `
            <div class="sd-od-item">${smallThumb(l.product || {})}<div class="grow"><strong>${escapeHtml(p.title)}</strong><span>${escapeHtml(p.subtitle)}</span></div>
              <div style="text-align:right"><div class="sd-price">${fmtOrNA(p.price === null ? null : p.price * (l.qty || 1))}</div><span class="sd-mono">${l.qty || 1} × ${fmtOrNA(p.price)}</span></div></div>`; }).join('')}</div></div>
        <div class="sd-od-sec"><div class="sd-od-label">Money</div>
          <div class="sd-od-box sd-od-sum">
            <div><span>Gross</span><span>${fmt(pay.gross)}</span></div>
            <div><span>Platform commission (${pct(pay.rate)})</span><span>${fmt(pay.commission)}</span></div>
            <div class="tot"><span>Net to seller</span><span>${fmt(pay.net)}</span></div>
          </div></div>
        ${o.dispute ? `<div class="dispute-box"><span class="k">Dispute opened ${timeAgo(o.dispute.opened_at)} by ${escapeHtml(o.dispute.opened_by || '—')}</span>${escapeHtml(o.dispute.reason || 'No reason recorded')}${o.dispute.resolution ? `<div class="muted" style="margin-top:4px">Resolved: ${escapeHtml(o.dispute.resolution)}</div>` : ''}</div>` : ''}
        <div class="sd-od-sec"><div class="sd-od-label">Admin actions</div>
          <div class="ad-admin-actions">
            <select class="dselect" id="adOverride" aria-label="Override status">${[...FLOW, 'cancelled'].map(s => `<option value="${s}"${s === o.status ? ' selected' : ''}>${cap(s)}</option>`).join('')}</select>
            <button type="button" class="btn btn-dark btn-sm" data-act="order-override" data-key="${escapeHtml(o.key)}">Override status</button>
          </div>
          <p class="dhint" style="margin:10px 0 0">Overrides bypass the seller's one-step rule and are logged. A refund cancels the sub-order and returns the buyer's money; resolving in the seller's favour marks it delivered and releases the payout.</p>
        </div>
      </div>
      <div>
        <div class="sd-od-sec"><div class="sd-od-label">Buyer</div>
          <div class="sd-od-box">
            <div class="row" style="align-items:center">${personAv(ship.name)}<strong>${escapeHtml(ship.name || userName(o.user_id))}</strong></div>
            <div class="row">${SI.phone}<span>${escapeHtml(ship.phone || '—')}</span></div>
            <div class="row">${SI.pin}<span>${escapeHtml(ship.address || '—')}${ship.city ? ', ' + escapeHtml(ship.city) : ''} ${escapeHtml(ship.zip || '')}</span></div>
          </div></div>
        <div class="sd-od-sec"><div class="sd-od-label">Status timeline</div>${(o.status_log || []).length ? timelineHtml(o) : '<p class="sd-card-sub">No log — placed before status logging existed.</p>'}</div>
      </div>
    </div>
    <div class="sd-od-foot">
      <button type="button" class="btn btn-ghost btn-sm" data-act="modal-close">Close</button>
      ${o.status !== 'disputed' && canRefund ? `<button type="button" class="btn btn-ghost btn-sm" data-act="order-dispute" data-key="${escapeHtml(o.key)}">Flag as disputed…</button>` : ''}
      ${canRefund ? `<button type="button" class="btn btn-danger btn-sm" data-act="order-refund" data-key="${escapeHtml(o.key)}">Force refund…</button>` : '<span class="muted" style="font-size:12px; align-self:center">Refunded — no further action</span>'}
      ${o.status === 'disputed' ? `<button type="button" class="btn btn-olive btn-sm" data-act="order-release" data-key="${escapeHtml(o.key)}">Resolve in seller's favour</button>` : ''}
    </div>`);
}
/* after an action taken from inside the order modal: refresh page + modal */
function afterOrderAction(key){ render(); if (key) openOrder(key); }

/* ============================================================
   FINANCE
   ============================================================ */
function financeView(){
  const days = { '7d': 7, '30d': 30, '90d': 90, all: 100000 }[AD.frange];
  const subs = allSubOrders().filter(o => hoursSince(o.placed_at) <= days * 24);
  const sellers = ADB.sellers();
  const bySeller = new Map();
  subs.forEach(o => {
    const p = ADB.payoutFor(o);
    const row = bySeller.get(o.seller_id) || { id: o.seller_id, name: sellerName(o.seller_id), orders: 0, gross: 0, commissionReleased: 0, escrow: 0, released: 0, held: 0, refunded: 0 };
    row.orders++; if (isLive(o)) row.gross += p.gross;
    if (p.state === 'released'){ row.commissionReleased += p.commission; row.released += p.net; }
    if (p.state === 'escrow') row.escrow += p.net;
    if (p.state === 'held') row.held += p.net;
    if (p.state === 'refunded') row.refunded += p.gross;
    bySeller.set(o.seller_id, row);
  });
  let rows = [...bySeller.values()].sort((a, b) => b.gross - a.gross);
  if (AD.fseller !== 'all') rows = rows.filter(r => r.id === AD.fseller);
  const tot = rows.reduce((t, r) => { Object.keys(r).forEach(k => { if (typeof r[k] === 'number') t[k] = (t[k] || 0) + r[k]; }); return t; }, {});
  const pendingAll = allSubOrders().map(ADB.payoutFor).filter(p => p.state === 'escrow' || p.state === 'held').reduce((s, p) => s + p.net, 0);
  const period = AD.frange === 'all' ? 'all time' : 'last ' + days + ' days';
  const liveAll = allSubOrders().filter(isLive);
  const comm14 = daySeries(allSubOrders().filter(o => ADB.payoutFor(o).state === 'released'), 14, o => o.payout_released_at || o.placed_at, o => ADB.payoutFor(o).commission).map(b => b.total);
  const states = {};
  subs.forEach(o => { const p = ADB.payoutFor(o); const k = PAY_LABEL[p.state] || p.state; states[k] = (states[k] || 0) + (p.state === 'refunded' ? p.gross : p.net); });

  return `
    <div class="sd-kpis">
      ${kpiCard({ icon: 'percent', label: 'Commission collected', value: kpiMoney(tot.commissionReleased), series: comm14, note: `released · ${period}` })}
      ${kpiCard({ tone: 'k-mustard', icon: 'clock', label: 'Escrow held', value: kpiMoney(pendingAll), note: 'owed to sellers on delivery · all time' })}
      ${kpiCard({ tone: 'k-indigo', icon: 'cash', label: 'GMV', value: kpiMoney(tot.gross), series: daySeries(liveAll, 14, o => o.placed_at, o => o.total || 0).map(b => b.total), note: `${plural(rows.length, 'seller')} · ${period}` })}
      ${kpiCard({ icon: 'flag', label: 'Refunded', value: kpiMoney(tot.refunded), note: period })}
    </div>

    <div class="sd-charts" style="margin-bottom:var(--sd-gap)">
      ${salesPanel(liveAll, 'Platform revenue trend', 'Daily GMV, all sellers')}
      <section class="sd-card">
        <div class="sd-card-head"><div><h2>Where the money is</h2><div class="sd-card-sub">Payout state · ${period}</div></div></div>
        ${donut(Object.entries(states), { emptyNote: 'No sub-orders in this period.' })}
      </section>
    </div>

    <section class="sd-card">
      <div class="sd-card-head"><div><h2>Commission by seller</h2><div class="sd-card-sub">Payout overview across sellers · ${period}</div></div>
        <div class="sd-card-actions">
          <select class="dselect" data-act="frange" aria-label="Period">${[['7d', 'Last 7 days'], ['30d', 'Last 30 days'], ['90d', 'Last 90 days'], ['all', 'All time']].map(([k, l]) => `<option value="${k}"${AD.frange === k ? ' selected' : ''}>${l}</option>`).join('')}</select>
          <select class="dselect" data-act="fseller" aria-label="Seller"><option value="all">All sellers</option>${sellers.map(s => `<option value="${escapeHtml(s.id)}"${AD.fseller === s.id ? ' selected' : ''}>${escapeHtml(s.seller.business_name)}</option>`).join('')}</select>
          <button class="btn btn-ghost btn-sm sd-btn-ico" data-act="export-finance">${DI.download} Export CSV</button>
        </div></div>
      ${rows.length ? `<div class="dtable"><table class="dt"><thead><tr><th>Seller</th><th class="num">Sub-orders</th><th class="num">Gross</th><th class="num">Rate</th><th class="num">Commission</th><th class="num">Released to seller</th><th class="num">In escrow</th><th class="num">Refunded</th></tr></thead>
        <tbody>${rows.map(r => `<tr class="is-click" data-act="user-open" data-id="${escapeHtml(r.id)}"><td><div class="sd-cust">${personAv(r.name)}<span class="name">${escapeHtml(r.name)}</span></div></td><td class="num">${r.orders}</td><td class="num">${fmt(r.gross)}</td><td class="num">${pct(ADB.commissionRateFor(r.id))}</td><td class="num">${fmt(Math.round(r.commissionReleased))}</td><td class="num">${fmt(Math.round(r.released))}</td><td class="num">${fmt(Math.round(r.escrow + r.held))}</td><td class="num">${fmt(Math.round(r.refunded))}</td></tr>`).join('')}</tbody>
        <tfoot><tr><td>Total</td><td class="num">${tot.orders}</td><td class="num">${fmt(tot.gross)}</td><td></td><td class="num">${fmt(Math.round(tot.commissionReleased))}</td><td class="num">${fmt(Math.round(tot.released))}</td><td class="num">${fmt(Math.round(tot.escrow + tot.held))}</td><td class="num">${fmt(Math.round(tot.refunded))}</td></tr></tfoot></table></div>`
        : emptyBlock(DI.cash, 'No sales in this period', 'Try a longer period or another seller.')}
    </section>`;
}

/* ============================================================
   FAQs
   ============================================================ */
function faqsView(){
  const faqs = ADB.faqs();
  const cats = [...new Set(faqs.map(f => f.category))];
  return `<section class="sd-card">
    <div class="sd-card-head">
      <div><h2>FAQ management</h2><div class="sd-card-sub">${plural(faqs.length, 'entry').replace('entrys', 'entries')} · ${plural(cats.length, 'category').replace('categorys', 'categories')}</div></div>
      <div class="sd-card-actions"><button class="btn btn-primary btn-sm sd-btn-ico" data-act="faq-new">${DI.plus} New FAQ</button></div>
    </div>
    <p class="dhint" style="margin:-4px 0 16px">There's no customer-facing help page yet, so these entries aren't shown anywhere on the site — the storage and this editor are ready for one.</p>
    ${faqs.length ? `<div class="dtable"><table class="dt">
      <thead><tr><th>Question &amp; answer</th><th>Category</th><th class="acts">Actions</th></tr></thead>
      <tbody>${cats.map(c => faqs.filter(f => f.category === c).map(f => `<tr>
        <td><div class="ad-faq-q">${escapeHtml(f.question)}</div><div class="ad-faq-a">${escapeHtml(f.answer)}</div></td>
        <td><span class="ad-cat">${escapeHtml(f.category)}</span></td>
        <td class="acts"><div class="sd-row-acts">
          <button class="sd-sq" data-act="faq-edit" data-id="${escapeHtml(f.id)}" title="Edit" aria-label="Edit FAQ">${DI.edit}</button>
          <button class="sd-sq danger" data-act="faq-delete" data-id="${escapeHtml(f.id)}" title="Delete" aria-label="Delete FAQ">${DI.trash}</button>
        </div></td></tr>`).join('')).join('')}</tbody></table></div>`
      : emptyBlock(DI.help, 'No FAQs yet', 'Write answers to the questions shoppers and sellers ask most.', `<button class="btn btn-dark btn-sm" data-act="faq-new">Write the first one</button>`)}
  </section>`;
}
function faqForm(f){
  const cats = [...new Set(ADB.faqs().map(x => x.category).concat(['general', 'ordering', 'payments', 'sellers']))];
  modalClass('sd-form-modal');
  openDashModal(`<form id="faqForm" autocomplete="off">
    <div class="sd-form-head"><h3>${f ? 'Edit FAQ' : 'New FAQ'}</h3><p>Short, specific answers work best — link the policy rather than restating it.</p></div>
    <div class="sd-form-body">
      <div class="sd-fgroup"><div class="sd-fgroup-title"><i>1</i>Question</div>
        <div class="field"><label for="fqQ">Question</label><input id="fqQ" value="${escapeHtml(f?.question || '')}" required></div>
        <div class="field"><label for="fqC">Category</label><input id="fqC" list="fqCats" value="${escapeHtml(f?.category || 'general')}"><datalist id="fqCats">${cats.map(c => `<option value="${escapeHtml(c)}">`).join('')}</datalist></div></div>
      <div class="sd-fgroup"><div class="sd-fgroup-title"><i>2</i>Answer</div>
        <div class="field"><label for="fqA">Answer</label><textarea id="fqA" rows="5" required>${escapeHtml(f?.answer || '')}</textarea></div></div>
    </div>
    <div class="sd-form-foot"><button type="button" class="btn btn-ghost btn-sm" data-act="modal-close">Cancel</button><button type="submit" class="btn btn-primary btn-sm">${f ? 'Save changes' : 'Add FAQ'}</button></div></form>`);
  $('#faqForm').addEventListener('submit', e => {
    e.preventDefault();
    adWrite(
      API.Admin.saveFaq({
        id: f ? f.id : undefined,
        question: $('#fqQ').value.trim(),
        answer: $('#fqA').value.trim(),
        category: ($('#fqC').value || 'general').trim().toLowerCase(),
        sort_order: f ? f.sort_order : undefined
      }),
      { onOk: () => closeDashModal() }
    );
    return;
    if (!rec){ toast('Question and answer are both required'); return; }
    closeDashModal(); toast(f ? 'FAQ updated' : 'FAQ added'); render();
  });
}

/* ============================================================
   SETTINGS — same form-section pattern as the seller Settings tab
   ============================================================ */
function settingsView(){
  const s = ADB.settings();
  const u = AD.me;
  const inUse = new Set(ADB.products().map(p => p.category));
  /* undefined = untouched (show what is stored), null = being removed,
     anything else = a freshly picked photo, shown from its preview. */
  const shownAvatar = AD.pendingAvatar === undefined
    ? getAvatar()
    : (AD.pendingAvatar === null ? null : AD.avatarPreview);
  const custom = ADB.sellers().filter(x => x.seller.commission_rate != null).length;
  const toggle = (k, title, sub) => `<div class="sd-toggle-row"><div><strong>${title}</strong><span>${sub}</span></div>
    <label class="dswitch"><input type="checkbox" data-act="toggle-setting" data-key="${k}" ${s[k] ? 'checked' : ''} aria-label="${escapeHtml(title)}"><i></i></label></div>`;

  return `<div class="sd-settings">
    <div class="sd-card sd-subnav" role="navigation" aria-label="Settings sections">
      <a href="#set-profile" data-act="set-jump">${SI.user} Your profile</a>
      <a href="#set-commission" data-act="set-jump">${SI.percent} Commission</a>
      <a href="#set-categories" data-act="set-jump">${SI.tag} Categories</a>
      <a href="#set-toggles" data-act="set-jump">${SI.toggle} Feature toggles</a>
    </div>
    <div class="sd-set-stack">
      <form class="sd-card sd-set-sec" id="set-profile" autocomplete="off" novalidate>
        <div class="sd-card-head"><div><h2>Your profile</h2><div class="sd-card-sub">How you appear across the console and in the audit trail</div></div></div>
        <div class="sd-avatar-edit">
          <span class="js-av-big">${avatarHtml('xl', shownAvatar)}</span>
          <div class="copy">
            <strong>Profile photo</strong>
            <p>A clear, square photo works best. JPG or PNG, up to 2 MB — we'll resize it for you.</p>
            <div class="sd-avatar-btns">
              <label class="btn btn-dark sd-btn-ico" for="adAvatarFile" style="cursor:pointer">${SI.upload} ${shownAvatar ? 'Change photo' : 'Upload photo'}</label>
              <button type="button" class="btn btn-ghost" data-act="avatar-remove" ${shownAvatar ? '' : 'disabled'}>Remove</button>
              <input type="file" id="adAvatarFile" accept="image/png,image/jpeg,image/webp" hidden>
            </div>
            <span class="sd-avatar-pending js-av-note" ${AD.pendingAvatar === undefined ? 'hidden' : ''}>Preview — save to apply</span>
          </div>
          <div class="sd-avatar-preview">
            <span class="sd-me js-av-chip">${avatarHtml('sm', shownAvatar)}<span class="sd-me-copy" style="display:block"><strong>${escapeHtml(u.name || u.email)}</strong><span>Platform admin</span></span></span>
            Top bar preview
          </div>
        </div>
        <div class="sd-set-grid">
          <div class="field"><label for="setName">Full name</label><input id="setName" value="${escapeHtml(u.name || '')}" required></div>
          <div class="field"><label for="setPhone">Phone</label><input id="setPhone" value="${escapeHtml(u.phone || '')}" placeholder="07XXXXXXXX"></div>
          <div class="field span2"><label for="setEmail">Email</label><input id="setEmail" value="${escapeHtml(u.email || '')}" readonly title="Sign-in email can't be changed here"></div>
        </div>
        <div class="dmodal-foot" style="margin-top:4px"><button type="button" class="btn btn-ghost btn-sm" data-act="profile-reset">Discard</button><button type="submit" class="btn btn-primary btn-sm">Save profile</button></div>
      </form>

      <form class="sd-card sd-set-sec" id="set-commission" autocomplete="off">
        <div class="sd-card-head"><div><h2>Commission</h2><div class="sd-card-sub">Applies to every seller without a negotiated rate</div></div></div>
        <div class="sd-set-grid">
          <div class="field"><label for="adRate">Platform default rate (%)</label><input id="adRate" type="number" min="0" max="50" step="0.5" value="${s.commission_rate}"></div>
          <div class="field"><label>Sellers on a custom rate</label><input value="${custom} — change those in Seller verification" readonly></div>
        </div>
        <div class="dmodal-foot" style="margin-top:4px"><button type="submit" class="btn btn-primary btn-sm">Save rate</button></div>
      </form>

      <section class="sd-card sd-set-sec" id="set-categories">
        <div class="sd-card-head"><div><h2>Product categories</h2><div class="sd-card-sub">What sellers can pick when listing; the storefront filters follow whatever's in use</div></div></div>
        <div class="chips">${s.categories.map(c => `<span class="chip"><span class="mono">${escapeHtml(c)}</span>${inUse.has(c) ? `<span class="muted" style="font-size:10px">· in use</span>` : `<button type="button" data-act="cat-remove" data-cat="${escapeHtml(c)}" title="Remove">×</button>`}</span>`).join('')}</div>
        <form id="catForm" class="chip-add"><input id="adNewCat" placeholder="new category, e.g. wearable" maxlength="30" aria-label="New category"><button class="btn btn-ghost btn-sm sd-btn-ico" type="submit">${DI.plus} Add</button></form>
        <p class="dhint" style="margin:12px 0 0">Categories with listings can't be removed — move or delete those listings first. Renaming is a remove + add, and existing listings keep their old value, so treat it as "retire and replace".</p>
      </section>

      <section class="sd-card sd-set-sec" id="set-toggles">
        <div class="sd-card-head"><div><h2>Feature toggles</h2><div class="sd-card-sub">Save instantly${s.updated_at ? ` · last changed ${timeAgo(s.updated_at)}` : ''}</div></div></div>
        ${toggle('seller_signups_open', 'Seller sign-ups open', 'Off blocks seller-register.html with a notice')}
        ${toggle('customer_signups_open', 'Customer sign-ups open', 'Off blocks register.html')}
      </section>
    </div>
  </div>`;
}
function previewAvatar(src){
  $$('.js-av-big').forEach(el => el.innerHTML = avatarHtml('xl', src));
  $$('.js-av-chip .sd-av').forEach(el => el.outerHTML = avatarHtml('sm', src));
  const note = $('.js-av-note'); if (note) note.hidden = false;
  const rm = $('[data-act="avatar-remove"]'); if (rm) rm.disabled = !src;
  const up = $('label[for="adAvatarFile"]'); if (up) up.innerHTML = `${SI.upload} ${src ? 'Change photo' : 'Upload photo'}`;
}
/* read → downscale to 256px so a data URL fits comfortably in localStorage */
function readAvatarFile(file){
  if (!file) return;
  if (!/^image\/(png|jpe?g|webp)$/.test(file.type)){ toast('Choose a JPG, PNG or WebP image'); return; }
  if (file.size > 2 * 1024 * 1024){ toast('That image is over 2 MB'); return; }
  const reader = new FileReader();
  reader.onload = () => {
    const img = new Image();
    img.onload = () => {
      const S = 256, c = document.createElement('canvas');
      c.width = c.height = S;
      const side = Math.min(img.width, img.height);
      c.getContext('2d').drawImage(img, (img.width - side) / 2, (img.height - side) / 2, side, side, 0, 0, S, S);
      /* Cropped and downscaled BEFORE upload, so a 6MB phone photo becomes
         a ~20KB JPEG. AD.pendingAvatar is the File to post; AD.avatarPreview
         is only for showing it immediately. The server re-checks the type
         and size regardless. */
      AD.avatarPreview = c.toDataURL('image/jpeg', 0.86);
      previewAvatar(AD.avatarPreview);

      if (c.toBlob){
        c.toBlob(blob => {
          AD.pendingAvatar = blob
            ? new File([blob], 'avatar.jpg', { type: 'image/jpeg' })
            : file;
        }, 'image/jpeg', 0.86);
      } else {
        AD.pendingAvatar = file;
      }
    };
    img.onerror = () => toast("That file couldn't be read as an image");
    img.src = reader.result;
  };
  reader.readAsDataURL(file);
}
function saveProfile(){
  const name = ($('#setName')?.value || '').trim(), phone = ($('#setPhone')?.value || '').trim().replace(/\s/g, '');
  if (!name){ toast('Your name is required'); $('#setName').focus(); return; }
  // admins may use an office landline (the seeded one does), so accept any
  // 10-digit Sri Lankan number, not just PHONE_RE's 07x mobiles
  if (phone && !/^(0\d{9}|\+94\d{9})$/.test(phone)){ toast('Enter a Sri Lankan phone number, e.g. 0112345678 or 0771234567'); $('#setPhone').focus(); return; }
  /* The photo first and separately — it is a multipart upload, not part of
     the JSON profile body. AD.pendingAvatar is a File to upload, null to
     remove, or undefined for "not touched". */
  const photo = AD.pendingAvatar;
  AD.pendingAvatar = undefined;

  const photoStep = photo === undefined
    ? Promise.resolve()
    : (photo === null ? API.Auth.removeAvatar() : API.Auth.uploadAvatar(photo));

  photoStep
    .catch(err => {
      console.error('[admin] avatar update failed:', err);
      toast(err.message || 'The photo could not be saved — your other details were kept.');
    })
    .then(() => adWrite(
      /* The shared own-profile endpoint, not an admin one: an admin editing
         their OWN name is not an admin action, and it takes the user id
         from the session rather than the request. The email is sent back
         unchanged because that endpoint treats it as the login identity. */
      API.Auth.updateProfile({ name: name, email: AD.me.email, phone: phone }),
      {
        onOk: res => {
          if (res && res.user){
            AD.me = Object.assign({}, AD.me, res.user);
            Session.adopt(res.user);   // updates the nav and the snapshot
          }
          const note = $('.js-save-note');
          if (note) note.textContent = 'All changes saved';
        }
      }
    ));
}

/* ============================================================
   ACTIONS
   ============================================================ */
function setView(v, extra = {}){
  AD.view = VIEW_IDS.includes(v) ? v : 'overview';
  AD.drawer = false;
  Object.assign(AD, extra);
  if (location.hash !== '#' + AD.view) history.replaceState(null, '', '#' + AD.view);
  closePops();
  render();
  window.scrollTo({ top: 0, behavior: 'smooth' });
}
function wire(){
  // body, not #dash: the modal shell lives outside the container and its
  // Cancel / add-row buttons use the same data-act delegation
  const root = document.body;
  root.addEventListener('click', e => {
    if (!e.target.closest('.sd-pop-wrap')) closePops();
    const el = e.target.closest('[data-act]');
    if (!el || !root.contains(el)) return;
    const act = el.dataset.act, id = el.dataset.id, key = el.dataset.key, me = AD.me.id;
    const fromModal = !!el.closest('#dashModal');

    switch (act){
      case 'view':         if (fromModal) closeDashModal(); setView(el.dataset.view, el.dataset.view === 'users' ? { userDetail: null } : {}); return;
      case 'goto':         setView(el.dataset.view, el.dataset.view === 'orders' ? { otab: el.dataset.tab || 'all', opage: 1 } : {}); return;
      case 'toggle-side':  AD.collapsed = !AD.collapsed; try { localStorage.setItem('ad_sidebar_collapsed', AD.collapsed ? '1' : '0'); } catch(e){} render(); return;
      case 'drawer-open':  AD.drawer = true;  $('#dash').classList.add('drawer-open'); return;
      case 'drawer-close': AD.drawer = false; $('#dash').classList.remove('drawer-open'); return;
      case 'modal-close':  closeDashModal(); AD.openKey = null; return;
      case 'logout':       closePops(); openLogoutModal(); return;
      case 'pop': {
        const pop = $('#pop-' + el.dataset.pop);
        const open = !pop.classList.contains('open');
        closePops(pop);
        pop.classList.toggle('open', open);
        el.setAttribute('aria-expanded', String(open));
        return;
      }
      case 'chart-days': AD.chartDays = Number(el.dataset.days) || 30; render(); return;
      case 'tip':        AD.tipIdx = Number(el.dataset.i) || 0; render(); return;

      /* sellers */
      case 'seller-approve': {
        const u = ADB.user(id); if (!u) return;
        modalClass('');
        confirmDash({ title: 'Approve this seller?', body: `<strong>${escapeHtml(u.seller.business_name)}</strong> gets full access to the seller dashboard and can list products immediately.`, confirmLabel: 'Approve' },
          () => adWrite(API.Admin.approveSeller(u.id)));
        return;
      }
      case 'seller-reject': {
        const u = ADB.user(id); if (!u) return;
        modalClass('');
        confirmDash({ title: 'Reject this application?', body: `The reason is shown to <strong>${escapeHtml(u.name)}</strong> on their dashboard so they can fix it and reapply.`, confirmLabel: 'Reject', danger: true, withReason: true, reasonLabel: 'Reason for rejection' },
          reason => adWrite(API.Admin.rejectSeller(u.id, reason)));
        return;
      }
      case 'seller-commission': {
        const u = ADB.user(id); if (!u) return;
        const cur = u.seller.commission_rate;
        modalClass('');
        openDashModal(`<h3>Commission for ${escapeHtml(u.seller.business_name)}</h3><form id="rateForm">
          <div class="field"><label for="adSellerRate">Custom rate (%)</label><input id="adSellerRate" type="number" min="0" max="50" step="0.5" value="${cur == null ? '' : cur}" placeholder="platform default: ${ADB.settings().commission_rate}%"></div>
          <p class="dhint">Leave empty to use the platform default.</p>
          <div class="dmodal-foot"><button type="button" class="btn btn-ghost btn-sm" data-act="modal-close">Cancel</button><button type="submit" class="btn btn-primary btn-sm">Save</button></div></form>`);
        $('#rateForm').addEventListener('submit', ev => {
          ev.preventDefault();
          const v = $('#adSellerRate').value.trim();
          if (v !== '' && (isNaN(Number(v)) || Number(v) < 0 || Number(v) > 50)){ toast('Enter a rate between 0 and 50'); return; }
          adWrite(
            API.Admin.setCommission(u.id, v === '' ? null : Number(v)),
            { onOk: () => closeDashModal() }
          );
        });
        return;
      }

      /* products */
      case 'product-preview': productPreview(id); return;
      case 'product-approve': {
        const p = ADB.product(id); if (!p) return;
        adWrite(API.Admin.approveProduct(p.id), {
          onOk: () => { if (fromModal) closeDashModal(); }
        });
        return;
      }
      case 'product-reject':
      case 'product-takedown': {
        const p = ADB.product(id); if (!p) return;
        const down = act === 'product-takedown';
        modalClass('');
        confirmDash({ title: down ? 'Take this listing down?' : 'Reject this listing?',
            body: down ? `<strong>${escapeHtml(p.title)}</strong> leaves the storefront immediately. The seller sees your reason; editing the listing sends it back to moderation.` : `The seller sees the reason on their Products page; editing the listing re-submits it.`,
            confirmLabel: down ? 'Take down' : 'Reject', danger: true, withReason: true, reasonLabel: 'What needs to change' },
          /* Two endpoints, not one with a flag: 'rejected' is for something
             that was never public and 'taken down' is for something that
             was, and the audit trail records them as different actions. */
          reason => adWrite(
            down ? API.Admin.takedownProduct(p.id, reason)
                 : API.Admin.rejectProduct(p.id, reason),
            { onOk: () => { if (fromModal) closeDashModal(); } }
          ));
        return;
      }

      /* users */
      case 'urole':      AD.urole = el.dataset.f; AD.userDetail = null; if (el.dataset.clear) AD.uquery = ''; if (AD.view !== 'users') setView('users'); else render(); return;
      case 'user-open':  if (fromModal) closeDashModal(); setView('users', { userDetail: id }); return;
      case 'user-close': AD.userDetail = null; render(); return;
      case 'user-suspend': {
        // Same loose comparison: `me` is a number, `id` came from the DOM.
        const u = ADB.user(id); if (!u || String(u.id) === String(me)) return;
        modalClass('');
        confirmDash({ title: `Suspend ${escapeHtml(u.name)}?`, body: `They're signed out on their next page load and can't sign back in until reactivated. ${u.role === 'seller' ? 'Their listings stay live — pause those separately if needed.' : ''}`, confirmLabel: 'Suspend', danger: true, withReason: true, reasonLabel: 'Internal note' },
          reason => adWrite(API.Admin.suspendUser(u.id, reason)));
        return;
      }
      case 'user-reactivate': {
        const u = ADB.user(id); if (!u) return;
        adWrite(API.Admin.reactivateUser(u.id));
        return;
      }

      /* orders */
      case 'otab':        AD.otab = el.dataset.f; AD.opage = 1; if (AD.view !== 'orders') setView('orders'); else render(); return;
      case 'opage':       AD.opage = Number(el.dataset.p) || 1; render(); return;
      case 'order-open':  openOrder(key); return;
      case 'order-override': {
        const o = allSubOrders().find(x => x.key === key); if (!o) return;
        const status = $('#adOverride').value;
        if (status === o.status){ toast('Already ' + status); return; }
        adWrite(
          API.Admin.overrideStatus(o.key, status, 'admin override'),
          { onOk: () => afterOrderAction(key) }
        );
        return;
      }
      case 'order-dispute': {
        const o = allSubOrders().find(x => x.key === key); if (!o) return;
        modalClass('');
        confirmDash({ title: 'Flag as disputed?', body: 'Payout is held and the seller sees the dispute on their order until you resolve it.', confirmLabel: 'Flag', danger: true, withReason: true, reasonLabel: 'Dispute reason' },
          reason => adWrite(
            API.Admin.flagDispute(o.key, reason),
            { onOk: () => afterOrderAction(key) }
          ));
        return;
      }
      case 'order-refund': {
        const o = allSubOrders().find(x => x.key === key); if (!o) return;
        modalClass('');
        confirmDash({ title: `Refund ${fmt(o.total || 0)} to the buyer?`, body: `This cancels <strong>${escapeHtml(o.id)}</strong> and returns the full amount. The seller receives nothing for it. This can't be undone.`, confirmLabel: 'Refund', danger: true, withReason: true, reasonLabel: 'Reason' },
          reason => adWrite(
            API.Admin.refundOrder(o.key, reason),
            { onOk: () => afterOrderAction(key) }
          ));
        return;
      }
      case 'order-release': {
        const o = allSubOrders().find(x => x.key === key); if (!o) return;
        /* The dedicated resolve action, not a bare override: it records the
           resolution on the dispute and releases the escrow through the
           same path the customer's own confirmation uses. */
        adWrite(
          API.Admin.resolveForSeller(o.key, 'Dispute resolved in the seller\'s favour'),
          { onOk: () => afterOrderAction(key) }
        );
        return;
      }

      /* finance */
      case 'export-finance': {
        /* Built by the SERVER (api/admin/finance.php?format=csv), not from
           what happens to be loaded in the browser: the export then covers
           the full range rather than the 500-row page the console holds,
           carries a UTF-8 BOM so Excel reads Sinhala names correctly, and
           is itself recorded in the audit trail. */
        const url = API.Admin.financeCsvUrl({
          range: AD.frange || 'all',
          seller: AD.fseller !== 'all' ? AD.fseller : undefined
        });
        window.location.href = url;
        toast('Preparing your CSV download…');
        return;
      }

      /* faqs */
      case 'faq-new':  faqForm(null); return;
      // data-id reads back as a string, faqs.id is an int — compare loosely.
      case 'faq-edit': faqForm(ADB.faqs().find(f => String(f.id) === String(id))); return;
      case 'faq-delete': {
        const f = ADB.faqs().find(x => String(x.id) === String(id)); if (!f) return;
        modalClass('');
        confirmDash(
          { title: 'Delete this FAQ?', body: escapeHtml(f.question), confirmLabel: 'Delete', danger: true },
          () => adWrite(API.Admin.deleteFaq(f.id))
        );
        return;
      }

      /* settings */
      case 'set-jump':      e.preventDefault(); $(el.getAttribute('href'))?.scrollIntoView({ behavior: 'smooth', block: 'start' }); return;
      case 'avatar-remove': AD.pendingAvatar = null; AD.avatarPreview = null; previewAvatar(null); return;
      case 'profile-reset': AD.pendingAvatar = undefined; render(); toast('Changes discarded'); return;
      case 'cat-remove': {
        const s = ADB.settings();
        /* The server refuses to remove a category that listings still use,
           and says which — the audit entry is written there too, so there
           is no separate logging call to forget. */
        adWrite(API.Admin.saveSettings({
          categories: s.categories.filter(c => c !== el.dataset.cat)
        }));
        toast(`Category "${el.dataset.cat}" removed`); render(); return;
      }
    }
  });

  root.addEventListener('submit', e => {
    if (e.target.id === 'set-profile'){ e.preventDefault(); saveProfile(); }
    if (e.target.id === 'set-commission'){
      e.preventDefault();
      const v = Number($('#adRate').value);
      if (isNaN(v) || v < 0 || v > 50){ toast('Enter a rate between 0 and 50'); return; }
      adWrite(API.Admin.saveSettings({ commission_rate: v }));
      toast(`Platform commission set to ${v}%`); render();
    }
    if (e.target.id === 'catForm'){
      e.preventDefault();
      const c = $('#adNewCat').value.trim().toLowerCase().replace(/[^a-z0-9-]/g, '');
      if (!c){ toast('Enter a category name (letters, numbers, dashes)'); return; }
      const s = ADB.settings();
      if (s.categories.includes(c)){ toast('Already exists'); return; }
      adWrite(API.Admin.saveSettings({ categories: s.categories.concat([c]) }));
      toast(`Category "${c}" added`); render();
    }
  });

  root.addEventListener('change', e => {
    const el = e.target, act = el.dataset.act;
    if (el.id === 'adAvatarFile'){ readAvatarFile(el.files && el.files[0]); el.value = ''; return; }
    if (act === 'toggle-setting'){
      adWrite(API.Admin.saveSettings({ [el.dataset.key]: el.checked }));
      toast(`${el.dataset.key.replace(/_/g, ' ')} ${el.checked ? 'enabled' : 'disabled'}`); render();
    }
    if (act === 'frange'){ AD.frange = el.value; render(); }
    if (act === 'fseller'){ AD.fseller = el.value; render(); }
  });

  let t;
  root.addEventListener('input', e => {
    const id = e.target.id;
    if (id === 'adGlobal'){ clearTimeout(t); t = setTimeout(() => globalSearch(e.target.value), 150); return; }
    if (id !== 'adUSearch' && id !== 'adOSearch') return;
    const pos = e.target.selectionStart;
    if (id === 'adUSearch') AD.uquery = e.target.value; else { AD.oquery = e.target.value; AD.opage = 1; }
    clearTimeout(t);
    t = setTimeout(() => { render(); const again = $('#' + id); if (again){ again.focus(); try { again.setSelectionRange(pos, pos); } catch(err){} } }, 220);
  });
  root.addEventListener('keydown', e => {
    if (e.key === 'Escape'){ closePops(); AD.drawer = false; $('#dash')?.classList.remove('drawer-open'); }
    if (e.key === '/' && !/^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName) && !e.target.isContentEditable){ const g = $('#adGlobal'); if (g){ e.preventDefault(); g.focus(); } }
  });

  /* chart tooltip */
  root.addEventListener('mouseover', e => {
    const tip = $('#sdTip');
    if (!tip) return;
    const h = e.target.closest && e.target.closest('.sd-chart .hit');
    if (!h){ if (!e.target.closest || !e.target.closest('.sd-chart')) tip.classList.remove('show'); return; }
    const n = h.dataset.n;
    tip.innerHTML = `${escapeHtml(h.dataset.label)} · <b>${fmt(Number(h.dataset.v))}</b>${n !== '' ? ' · ' + plural(Number(n), 'order') : ''}`;
    tip.style.left = h.dataset.x + '%'; tip.style.top = h.dataset.y + '%';
    tip.classList.add('show');
  });

  window.addEventListener('hashchange', () => { const v = location.hash.slice(1); if (VIEW_IDS.includes(v) && v !== AD.view) setView(v); });
}

/* ---------- visible fallbacks ----------
   The nav and footer are static HTML, so any failure before the first
   render() used to leave a blank middle with no hint why. Every early exit
   now paints a message into #adminBody instead. */
function adminNotice({ icon = 'flag', title, body, actions = '', detail = '' }){
  const box = $('#adminBody');
  if (!box) return;
  box.setAttribute('data-ready', '1');
  $('#dash')?.classList.add('is-gated');
  const side = $('#sdSide'); if (side) side.innerHTML = '';
  const top = $('#sdTop');
  if (top) top.innerHTML = `<div class="sd-top-title"><div class="sd-crumb">Web-master / <b>Console</b></div><h1>Admin console</h1></div>`;
  box.innerHTML = `<div class="sd-card gate ad-notice">
    <span class="dempty-ico">${(typeof DI !== 'undefined' && DI[icon]) || ''}</span>
    <h2>${escapeHtml(title)}</h2>
    <p class="muted">${body}</p>
    ${detail ? `<pre class="ad-notice-detail">${escapeHtml(detail)}</pre>` : ''}
    <div class="dempty-actions">${actions}</div>
  </div>`;
}
function adminFailed(err){
  console.error('Admin dashboard failed to load:', err);
  adminNotice({
    title: 'Something went wrong loading the dashboard',
    body: 'The console hit an error while starting up. Reloading usually fixes it; if it keeps happening, resetting the demo data will.',
    detail: String((err && (err.message || err)) || 'Unknown error'),
    actions: `<button class="btn btn-primary btn-sm" onclick="location.reload()">Reload page</button>
      <button class="btn btn-ghost btn-sm" onclick="if (confirm('Reset all demo data in this browser? Orders, listings and accounts return to the seeded state.')){ Object.keys(localStorage).filter(k => k.startsWith('consult_') || k.startsWith('ad_') || k.startsWith('sd_')).forEach(k => localStorage.removeItem(k)); location.href = 'login.html'; }">Reset demo data</button>`
  });
}
/* signed out or the wrong role: say so on the page instead of bouncing silently */
function adminAccessCheck(){
  const u = Session.isLoggedIn ? Session.user : null;
  if (u && u.role === 'admin') return true;
  adminNotice({
    icon: 'shield',
    title: u ? "You don't have admin access" : 'Sign in to open the admin console',
    body: u
      ? `You're signed in as <strong>${escapeHtml(u.name || u.email || 'this account')}</strong> (${escapeHtml(u.role || 'no role')}). The web-master console is only for platform admins.`
      : 'The web-master console is only available to platform admins. Sign in with an admin account to continue.',
    actions: u
      ? `${u.role === 'seller' ? '<a class="btn btn-primary btn-sm" href="seller-dashboard.html">Go to seller dashboard</a>' : '<a class="btn btn-primary btn-sm" href="index.html">Back to the store</a>'}
         <button class="btn btn-ghost btn-sm" onclick="openLogoutModal()">Sign in as admin</button>`
      : `<a class="btn btn-primary btn-sm" href="login.html?return=admin-dashboard.html">Sign in</a><a class="btn btn-ghost btn-sm" href="index.html">Back to the store</a>`
  });
  return false;
}

/* ---------- boot ----------
   The three visible failure modes above are all still wired:
     a blocked script      → adminFailed(), with the missing file named
     signed out / wrong role → adminNotice() via adminAccessCheck()
     anything else at startup → adminFailed(), with the real message

   adminAccessCheck() gates on the session SNAPSHOT so the page does not
   flash; the real gate is require_admin() on every endpoint, and a
   FORBIDDEN from the server re-runs the same notice below.
   ---------------------------------------------------------------- */
document.addEventListener('DOMContentLoaded', () => {
  try {
    // db.js is no longer used by this console; what it needs is api.js
    // (the data gateway) and dash-common.js (the DI icons and date helpers).
    if (typeof API === 'undefined' || typeof DI === 'undefined'){
      throw new Error('A required script (' + (typeof API === 'undefined' ? 'js/api.js' : 'js/dash-common.js') + ') did not load.');
    }
    if (!adminAccessCheck()) return;

    AD.me = Session.user;
    try { AD.collapsed = localStorage.getItem('ad_sidebar_collapsed') === '1'; } catch(e){}
    const h = location.hash.slice(1);
    if (VIEW_IDS.includes(h)) AD.view = h;

    wire();
    render();                                   // skeleton, while the data is in flight
  } catch(err){ adminFailed(err); return; }

  Session.ready
    .then(user => {
      /* The server's answer, which overrides the snapshot: an account
         demoted since the last page load fails here, not silently. */
      if (!user || user.role !== 'admin'){
        adminAccessCheck();
        return null;
      }
      AD.me = user;
      return ADB.load();
    })
    .then(data => {
      if (!data) return;
      AD.loading = false;
      render();

      // The same last line of defence as before: never a blank middle.
      if (!$('#adminBody').innerHTML.trim()){
        adminFailed(new Error('The console rendered no content.'));
      }
    })
    .catch(err => {
      AD.loading = false;
      if (err && err.code === 'AUTH'){ adminAccessCheck(); return; }
      if (err && err.code === 'FORBIDDEN'){ adminAccessCheck(); return; }
      adminFailed(err);
    });
});
