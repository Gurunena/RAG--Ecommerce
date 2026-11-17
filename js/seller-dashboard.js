/* ============================================================
   seller-dashboard.js — seller-dashboard.html

   Shell: sidebar (#sdSide) + sticky top bar (#sdTop) + section
   content (#sellerBody), plus a docked AI assistant (#sdAi) that
   lives outside the content so its thread survives section switches.

   Six views: Overview, Orders, Products, Inventory, Payouts, Settings.
   Everything comes from api/seller/dashboard.php and is scoped
   SERVER-side to the signed-in seller: seller_id is in the WHERE
   clause of every query (api/seller/_seller.php), not checked after
   fetching. A seller whose kyc_status isn't `verified` sees a review
   gate instead — the admin's verification queue is what opens the
   dashboard, and the write endpoints refuse them regardless.

   All interaction is delegated from one listener on document.body, keyed
   by data-act attributes, so a re-render can never leave a button
   pointing at a stale row. Status writes address a sub-order by
   (order id + sub-order id), never by sub-order id alone.
   ============================================================ */

const STALE_HOURS = 24;          // open order sitting this long in one status = needs attention
const LOW_STOCK = 5;             // at or below = "running low"
const PAGE_SIZE = 8;             // orders table rows per page

const SIDE = [
  { group: 'Menu' },
  { id: 'overview',  label: 'Overview',  icon: 'home' },
  { id: 'orders',    label: 'Orders',    icon: 'orders' },
  { id: 'products',  label: 'Products',  icon: 'box' },
  { id: 'inventory', label: 'Inventory', icon: 'layers' },
  { id: 'payouts',   label: 'Payouts',   icon: 'payout' },
  { group: 'Account' },
  { id: 'settings',  label: 'Settings',  icon: 'gear' }
];
const VIEWS = SIDE.filter(s => s.id);
const VIEW_IDS = VIEWS.map(s => s.id);

/* icons only this page needs (same inline-SVG idiom as DI in dash-common.js) */
const SI = {
  sparkle: `<svg viewBox="0 0 24 24" fill="none"><path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z" fill="currentColor"/><path d="M18.5 15.5l.7 2 2 .7-2 .7-.7 2-.7-2-2-.7 2-.7z" fill="currentColor"/></svg>`,
  chev:    `<svg viewBox="0 0 24 24" fill="none"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>`,
  collapse:`<svg viewBox="0 0 24 24" fill="none"><path d="M15 6l-6 6 6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 4v16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>`,
  store:   `<svg viewBox="0 0 24 24" fill="none"><path d="M4 9l1.5-5h13L20 9M4 9v11h16V9M4 9h16M9 20v-6h6v6" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>`,
  logout:  `<svg viewBox="0 0 24 24" fill="none"><path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h10" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>`,
  user:    `<svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="1.6"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>`,
  phone:   `<svg viewBox="0 0 24 24" fill="none"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>`,
  pin:     `<svg viewBox="0 0 24 24" fill="none"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0112 3a7 7 0 017 6.5C19 14.8 12 21 12 21z" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="9.5" r="2.5" stroke="currentColor" stroke-width="1.6"/></svg>`,
  note:    `<svg viewBox="0 0 24 24" fill="none"><path d="M5 4h14v12l-4 4H5z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M9 9h6M9 13h4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>`,
  grid:    `<svg viewBox="0 0 24 24" fill="none"><rect x="4" y="4" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="13" y="4" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="4" y="13" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/><rect x="13" y="13" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/></svg>`,
  upload:  `<svg viewBox="0 0 24 24" fill="none"><path d="M12 16V4M7 9l5-5 5 5M4 20h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>`,
  bank:    `<svg viewBox="0 0 24 24" fill="none"><path d="M3 9l9-5 9 5M5 10v8M9.5 10v8M14.5 10v8M19 10v8M3 20h18" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>`,
  arrow:   `<svg viewBox="0 0 24 24" fill="none"><path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>`,
  eye:     `<svg viewBox="0 0 24 24" fill="none"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.6"/></svg>`,
  pause:   `<svg viewBox="0 0 24 24" fill="none"><path d="M9 6v12M15 6v12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>`,
  play:    `<svg viewBox="0 0 24 24" fill="none"><path d="M8 5.5v13l10-6.5z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>`,
  tag:     `<svg viewBox="0 0 24 24" fill="none"><path d="M3 12V4h8l10 10-8 8z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><circle cx="7.5" cy="8.5" r="1.5" fill="currentColor"/></svg>`,
  cart:    `<svg viewBox="0 0 24 24" fill="none"><path d="M3 4h2.2l2.3 11.5a1.5 1.5 0 001.5 1.2h8.6a1.5 1.5 0 001.5-1.2L20.5 8H6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><circle cx="10" cy="20" r="1.3" fill="currentColor"/><circle cx="17" cy="20" r="1.3" fill="currentColor"/></svg>`
};

/* The shape SD.data holds before the first load lands, so every reader
   below works against an empty workspace instead of throwing while the
   skeleton is on screen. */
const SD_EMPTY = {
  seller:   null,
  stats:    {},
  series:   [],
  payouts:  { escrow: 0, released: 0, held: 0 },
  products: [],
  orders:   [],
  settings: { categories: [], commission_rate: 8, commission_is_own: false, low_stock_at: 5 }
};

const SD = {
  me: null,
  data: SD_EMPTY,          // the whole workspace, from api/seller/dashboard.php
  view: 'overview',
  loading: true,
  collapsed: false,
  // overview
  chartDays: 30, tipIdx: 0,
  // orders
  tab: 'all', query: '', range: 'all', mode: 'list', selected: new Set(), justChanged: new Set(), drag: null,
  osort: { k: 'date', dir: -1 }, opage: 1, openKey: null,
  // products / inventory
  pquery: '', pmode: 'grid', pfilter: 'all', invSelected: new Set(), ifilter: 'all',
  // payouts
  ptab: 'all',
  // settings — undefined = untouched, null = remove photo, File = upload it
  pendingAvatar: undefined,
  avatarPreview: null,     // data URL, for showing a freshly picked photo
  busy: false,             // a write is in flight
  // assistant
  ai: { open: false, thread: [], busy: false }
};

/* ============================================================
   DATA — one load from api/seller/dashboard.php, held in SD.data

   This dashboard renders synchronously: six views, all redrawn from
   scratch on every click. So it cannot await a promise per read —
   the whole workspace arrives in ONE request on boot and lives in
   SD.data, and the readers below are synchronous views over it,
   exactly as the old db.js ones were.

   Every mutation posts to its endpoint and then re-reads from the
   server rather than patching SD.data by hand, so what is on screen
   is always what the database actually holds.

   NOTHING HERE IS A PERMISSION CHECK. The server decides who owns a
   row and which status may follow which (api/seller/_seller.php and
   api/orders/_orders.php). The `can` flags and the FLOW array below
   only keep the page from drawing a button that would be refused.
   ============================================================ */

/* Mirrors ORDER_FLOW / ORDER_TERMINAL in api/orders/_orders.php. */
const FLOW     = ['pending', 'packed', 'shipped', 'delivered'];
const TERMINAL = ['cancelled', 'disputed', 'refunded'];

/* The seller's authority stops at 'shipped': 'delivered' is the customer's
   to confirm, because that is what releases the money. nextOf() therefore
   never returns 'delivered', and the server refuses it anyway. */
const nextStatusOf = s => FLOW[FLOW.indexOf(s) + 1] || null;

const SDB = {
  /* ---------- synchronous reads over the loaded workspace ---------- */
  orders:   () => SD.data.orders,
  products: () => SD.data.products,
  product:  id  => SD.data.products.find(p => p.id === id) || null,
  order:    key => SD.data.orders.find(o => o.key === key) || null,
  settings: () => SD.data.settings,
  stats:    () => SD.data.stats,

  commissionRate:  () => SD.data.settings.commission_rate,
  commissionIsOwn: () => !!SD.data.settings.commission_is_own,

  /* Today's effective price. `sale.active` was computed by the database
     (CURDATE() BETWEEN start_date AND end_date), so a sale scheduled for
     next week correctly reads as not running. */
  effectivePrice(p){
    const s = p && p.sale;
    if (s && s.sale_price && s.active) return { price: s.sale_price, oldPrice: p.price, onSale: true };
    return { price: p ? p.price : 0, oldPrice: (p && p.old_price) || null, onSale: false };
  },

  /* The payout the SERVER computed for this sub-order — gross, the
     snapshotted commission rate, net, and whether it is still in escrow. */
  payoutFor: o => (o && o.payout) || { gross: 0, rate: 0, commission: 0, net: 0, state: 'escrow' },

  /* Load, or reload, everything. */
  load(){
    return API.Seller.dashboard().then(d => {
      SD.data = Object.assign({}, SD_EMPTY, d);
      // The seller block doubles as the profile the Settings view edits.
      if (d.seller) SD.me = Object.assign({}, SD.me, { seller: d.seller, avatar_url: d.seller.avatar_url });
      return SD.data;
    });
  }
};

/* Generic day bucketing for the SVG charts. The headline KPIs come from
   SQL (SUM/COUNT/GROUP BY in dashboard.php — see SD.data.stats); this
   buckets the same real rows client-side so the chart's range switch and
   its week-over-week comparison do not need a round trip each. */
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
    // parseWhen (dash-common.js) handles the MySQL 'YYYY-MM-DD HH:MM:SS' form.
    const when = parseWhen(dateOf(r));
    if (!when) return;
    const t = when.getTime();
    const b = buckets.find(x => t >= x.start && t < x.end);
    if (b) b.total += valueOf(r);
  });
  return buckets;
}

/**
 * Every write goes through here: post, then RE-READ the whole workspace and
 * repaint from it.
 *
 * Re-reading rather than patching SD.data by hand is the point — a stock
 * change moves the KPI strip, the low-stock count and the inventory bars,
 * and a status change moves the revenue chart and the escrow total. Those
 * are computed in SQL, so asking the server again is the only way to be
 * sure the screen agrees with the database.
 *
 * On failure it re-reads too: if the server refused, the screen must show
 * what the server actually holds, not the change that did not happen.
 */
function sdWrite(promise, opts){
  opts = opts || {};
  SD.busy = true;

  const finish = () => SDB.load()
    .then(() => { SD.busy = false; render(); })
    .catch(err => {
      SD.busy = false;
      console.error('[seller] could not reload the dashboard:', err);
      toast('Saved, but the dashboard could not refresh. Reload the page.');
    });

  return promise
    .then(res => {
      if (opts.onOk) opts.onOk(res);
      const msg = opts.message || (res && res.message);
      if (msg) toast(msg);
      return finish();
    })
    .catch(err => {
      SD.busy = false;
      console.error('[seller] write failed:', err);

      if (err.code === 'AUTH') return;            // api.js is already redirecting

      // Per-field errors from the server: show the first, since these
      // dashboard modals have no inline error slots.
      toast(err.fields ? Object.values(err.fields)[0] : (err.message || 'That change could not be saved.'));

      if (opts.onFail) opts.onFail(err);
      return finish();
    });
}

const myOrders   = () => SDB.orders();
const myProducts = () => SDB.products();

const isOpen  = o => FLOW.includes(o.status) && o.status !== 'delivered';
const isStale = o => isOpen(o) && hoursSince(o.status_updated_at) > STALE_HOURS;
const isLive  = o => !o.refunded_at && o.status !== 'cancelled';           // counts toward revenue
const nextOf  = s => FLOW.includes(s) ? nextStatusOf(s) : null;
const stockTag = p => p.stock <= 0 ? 'out' : p.stock <= LOW_STOCK ? 'low' : 'ok';
const plural = (n, w) => `${n} ${w}${n === 1 ? '' : 's'}`;
const unitsOf = o => (o.items || []).reduce((s, l) => s + (l.qty || 1), 0);
/* order_items carries the category on its nested product; the catalogue
   lookup is only a fallback. */
const catOf = l => (l.product && l.product.category)
  || (SDB.product(l.product && l.product.id) || {}).category || 'other';

function inRange(o){
  if (SD.range === 'all') return true;
  const days = { today: 1, '7d': 7, '30d': 30 }[SD.range] || 0;
  return hoursSince(o.placed_at) <= days * 24;
}
function withinDays(iso, days){ return iso && hoursSince(iso) <= days * 24; }

/* big numbers read better with the currency set small */
const kpiMoney = n => `<small>LKR</small>${Math.round(n || 0).toLocaleString('en-LK')}`;

/* 14 daily values → last 7 vs the 7 before */
function trendInfo(series){
  const recent = series.slice(-7).reduce((a, b) => a + b, 0);
  const prior  = series.slice(-14, -7).reduce((a, b) => a + b, 0);
  if (!prior && !recent) return { cls: 'flat', text: '—', note: 'no activity yet' };
  if (!prior) return { cls: 'up', text: '▲ new', note: 'vs last week' };
  const p = Math.round(((recent - prior) / prior) * 100);
  if (!p) return { cls: 'flat', text: '0%', note: 'vs last week' };
  return { cls: p > 0 ? 'up' : 'down', text: `${p > 0 ? '▲' : '▼'} ${Math.abs(p)}%`, note: 'vs last week' };
}

/* ============================================================
   AVATAR — photo if the seller uploaded one, else initials on a
   palette colour picked from the id (so it's stable per seller).
   ============================================================ */
/* The photo is users.avatar_url — a file under uploads/, uploaded through
   api/seller/upload.php. It used to be a data URL in localStorage, which
   meant the seller's own photo vanished on another device and could blow
   the storage quota. */
function getAvatar(){ return (SD.me && SD.me.avatar_url) || null; }
function initials(name){
  const w = String(name || '').trim().split(/\s+/).filter(Boolean);
  return ((w[0] || '?').charAt(0) + (w.length > 1 ? w[w.length - 1].charAt(0) : '')).toUpperCase();
}
function colorIdx(seed){ let h = 0; for (const c of String(seed || '')) h = (h * 31 + c.charCodeAt(0)) >>> 0; return h % 3; }
function avatarHtml(size = '', src){
  const u = SD.me;
  const photo = src === undefined ? getAvatar() : src;
  return `<span class="sd-av ${size} c${colorIdx(u.id)}" aria-hidden="true">${photo
    ? `<img src="${escapeHtml(photo)}" alt="">`
    : escapeHtml(initials(u.name || u.email))}</span>`;
}
/* customers get the same treatment, keyed by their name */
function personAv(name){ return `<span class="sd-av c${colorIdx(name)}" aria-hidden="true">${escapeHtml(initials(name || '?'))}</span>`; }

const storeName = () => ((SD.me.seller || {}).business_name || 'Your store');
const firstName = () => String(SD.me.name || SD.me.email || 'there').trim().split(/\s+/)[0];

/* ============================================================
   RENDER
   ============================================================ */
function render(){
  const box = $('#sellerBody');
  if (!box) return;
  try {
    const orders = myOrders();
    const products = myProducts();
    const counts = sideCounts(orders, products);
    $('#dash').classList.toggle('is-collapsed', SD.collapsed);
    renderSide(counts);
    renderTop(orders, products);

    if (SD.loading){ box.innerHTML = skeleton(); return; }

    box.innerHTML = {
      overview:  overviewView,
      orders:    ordersView,
      products:  productsView,
      inventory: inventoryView,
      payouts:   payoutsView,
      settings:  settingsView
    }[SD.view](orders, products);
    SD.justChanged.clear();
  } catch(err){
    console.error('Seller dashboard render failed:', err);
    renderErrorState(box, 'reload');
  }
}

function skeleton(){
  return `<div class="skeleton" style="height:56px; width:50%; margin-bottom:20px; border-radius:var(--r-md)"></div>
    <div class="sd-skel-row">${'<div class="skeleton"></div>'.repeat(4)}</div>
    <div class="skeleton" style="height:300px; border-radius:var(--r-lg)"></div>`;
}

function sideCounts(orders, products){
  const open = orders.filter(isOpen).length;
  const low  = products.filter(p => p.status === 'active' && stockTag(p) !== 'ok').length;
  const rej  = products.filter(p => p.status === 'rejected').length;
  return {
    orders:    open ? { n: open, title: open + ' awaiting action' } : null,
    inventory: low  ? { n: low,  title: low + ' low or out of stock', warn: true } : null,
    products:  rej  ? { n: rej,  title: plural(rej, 'listing') + ' rejected' } : null
  };
}

/* ---------- sidebar ---------- */
function renderSide(counts){
  const s = SD.me.seller || {};
  $('#sdSide').innerHTML = `
    <div class="sd-store" title="${escapeHtml(storeName())}">
      ${avatarHtml('sm')}
      <div class="sd-store-copy">
        <div class="sd-store-name">${escapeHtml(storeName())}</div>
        <div class="sd-store-meta">${s.kyc_status === 'verified' ? `${DI.shield} Verified seller` : 'Seller workspace'}</div>
      </div>
    </div>
    ${SIDE.map(it => it.group
      ? `<div class="sd-side-label">${it.group}</div>`
      : (() => {
          const c = counts[it.id];
          const on = SD.view === it.id;
          return `<button class="sd-nav${on ? ' on' : ''}" data-act="view" data-view="${it.id}" title="${it.label}" ${on ? 'aria-current="page"' : ''}>
            <span class="sd-nav-ico">${DI[it.icon]}</span><span class="sd-nav-label">${it.label}</span>
            ${c ? `<span class="sd-nav-badge${c.warn ? ' warn' : ''}" title="${escapeHtml(c.title)}">${c.n}</span>` : ''}
          </button>`;
        })()).join('')}
    <div class="sd-side-foot">
      <div class="sd-help">
        <strong>Need a second opinion?</strong>
        <p>Ask the assistant about sales, restocking or listing copy.</p>
        <button class="btn btn-primary sd-btn-ico" data-act="ai-open">${SI.sparkle} Ask Consult AI</button>
      </div>
      <button class="sd-collapse" data-act="toggle-side" aria-label="${SD.collapsed ? 'Expand sidebar' : 'Collapse sidebar'}" title="${SD.collapsed ? 'Expand sidebar' : 'Collapse sidebar'}">${SI.collapse}<span>Collapse</span></button>
    </div>`;
}

/* ---------- top bar ---------- */
function renderTop(orders, products){
  const meta = VIEWS.find(v => v.id === SD.view) || VIEWS[0];
  const alerts = attentionItems(orders, products);
  const u = SD.me;
  $('#sdTop').innerHTML = `
    <button class="sd-icon-btn sd-burger" data-act="open-drawer" aria-label="Open menu">${DI.menu}</button>
    <div class="sd-top-title">
      <div class="sd-crumb">Seller Hub / <b>${meta.label}</b></div>
      <h1>${meta.label}</h1>
    </div>
    <div class="sd-top-search sd-pop-wrap">
      <div class="dsearch"><span class="dsearch-ico">${DI.search}</span>
        <input id="sdGlobal" type="search" placeholder="Search orders, customers, listings…" aria-label="Search the dashboard" autocomplete="off">
        <kbd>/</kbd></div>
      <div class="sd-pop search" id="sdSearchPop"></div>
    </div>
    <div class="sd-top-actions">
      <a class="btn btn-ghost sd-store-btn" href="products.html" title="View your storefront">${SI.store}<span>View store</span></a>
      <div class="sd-pop-wrap">
        <button class="sd-icon-btn" data-act="pop" data-pop="notif" aria-label="Notifications${alerts.length ? ` (${alerts.length})` : ''}" aria-expanded="false">${DI.bell}${alerts.length ? `<span class="dot">${alerts.length}</span>` : ''}</button>
        <div class="sd-pop" id="pop-notif">
          <div class="sd-pop-head"><strong>Notifications</strong><span>${alerts.length ? plural(alerts.length, 'item') + ' need you' : 'all clear'}</span></div>
          ${alerts.length ? alerts.map(a => `
            <button class="sd-pop-item${a.bad ? ' danger' : ''}" data-act="${a.act}" ${a.view ? `data-view="${a.view}"` : ''} ${a.tab ? `data-tab="${a.tab}"` : ''}>
              ${DI[a.icon]}<span><strong style="font-weight:600">${escapeHtml(a.title)}</strong><small>${escapeHtml(a.sub)}</small></span>
            </button>`).join('') : `<div class="sd-pop-empty">${DI.check.replace('<svg', '<svg style="width:22px;height:22px;color:var(--olive);display:block;margin:0 auto 6px"')}Nothing needs you right now.</div>`}
        </div>
      </div>
      <div class="sd-pop-wrap">
        <button class="sd-me" data-act="pop" data-pop="me" aria-haspopup="true" aria-expanded="false" aria-label="Account menu">
          ${avatarHtml('sm')}
          <span class="sd-me-copy"><strong>${escapeHtml(u.name || u.email)}</strong><span>${escapeHtml(storeName())}</span></span>
          ${SI.chev}
        </button>
        <div class="sd-pop" id="pop-me" style="width:250px">
          <div class="sd-pop-head" style="justify-content:flex-start; gap:10px">${avatarHtml('')}<div style="min-width:0"><strong style="display:block">${escapeHtml(u.name || '')}</strong><span style="display:block; overflow:hidden; text-overflow:ellipsis">${escapeHtml(u.email || '')}</span></div></div>
          <a class="sd-pop-item" href="products.html">${SI.store} View store</a>
          <button class="sd-pop-item" data-act="view" data-view="settings">${DI.gear} Settings</button>
          <button class="sd-pop-item" data-act="ai-open">${SI.sparkle} Ask Consult AI</button>
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
  const pop = $('#sdSearchPop');
  if (!pop) return;
  q = q.trim().toLowerCase();
  if (!q){ pop.classList.remove('open'); pop.innerHTML = ''; return; }
  const orders = myOrders().filter(o =>
    (o.id || '').toLowerCase().includes(q) || (o.order_id || '').toLowerCase().includes(q) ||
    (o.shipping.name || '').toLowerCase().includes(q) ||
    (o.items || []).some(l => safeProduct(l.product).title.toLowerCase().includes(q))).slice(0, 4);
  const products = myProducts().filter(p => p.title.toLowerCase().includes(q) || (p.category || '').includes(q)).slice(0, 4);
  pop.innerHTML = (!orders.length && !products.length) ? `<div class="sd-pop-empty">No orders or listings match “${escapeHtml(q)}”</div>` : `
    ${orders.length ? `<div class="sd-pop-label">Orders</div>${orders.map(o => `<button class="sd-pop-item" data-act="order-open" data-key="${escapeHtml(o.key)}">${DI.orders}<span>${escapeHtml(o.id)}<small>${escapeHtml(o.shipping.name || '—')} · ${itemsLine(o)}</small></span></button>`).join('')}` : ''}
    ${products.length ? `<div class="sd-pop-label">Listings</div>${products.map(p => `<button class="sd-pop-item" data-act="product-edit" data-id="${escapeHtml(p.id)}">${DI.box}<span>${escapeHtml(p.title)}<small>${fmt(SDB.effectivePrice(p).price)} · ${p.stock} in stock</small></span></button>`).join('')}` : ''}
    <div class="sd-pop-sep"></div>
    <button class="sd-pop-item" data-act="search-all">${DI.search}<span>Search all orders for “${escapeHtml(q)}”</span></button>`;
  pop.classList.add('open');
}

/* things that need the seller — shown in the bell, used by the AI too */
function attentionItems(orders, products){
  const items = [];
  const stale = orders.filter(isStale);
  if (stale.length){
    const oldest = stale.reduce((a, b) => hoursSince(a.status_updated_at) > hoursSince(b.status_updated_at) ? a : b);
    items.push({ icon: 'clock', act: 'goto-orders', tab: '__stale',
      title: `${plural(stale.length, 'order')} waiting over ${STALE_HOURS}h`,
      sub: `Oldest: ${oldest.id} — ${oldest.status} ${timeAgo(oldest.status_updated_at)}` });
  }
  const fresh = orders.filter(o => o.status === 'pending' && !isStale(o));
  if (fresh.length) items.push({ icon: 'orders', act: 'goto-orders', tab: 'pending',
    title: `${plural(fresh.length, 'new order')} to pack`, sub: 'Mark them packed once they are ready to hand over' });
  const disputed = orders.filter(o => o.status === 'disputed');
  if (disputed.length) items.push({ icon: 'flag', bad: true, act: 'goto-orders', tab: 'disputed',
    title: `${plural(disputed.length, 'dispute')} open`, sub: 'Payout is held until an admin resolves it' });
  const out = products.filter(p => p.status === 'active' && p.stock <= 0);
  const low = products.filter(p => p.status === 'active' && p.stock > 0 && p.stock <= LOW_STOCK);
  if (out.length) items.push({ icon: 'layers', bad: true, act: 'view', view: 'inventory',
    title: `${plural(out.length, 'listing')} out of stock`, sub: out.map(p => p.title).slice(0, 2).join(', ') + (out.length > 2 ? '…' : '') });
  if (low.length) items.push({ icon: 'layers', act: 'view', view: 'inventory',
    title: `${plural(low.length, 'listing')} running low`, sub: low.map(p => `${p.title} (${p.stock})`).slice(0, 2).join(', ') + (low.length > 2 ? '…' : '') });
  const rejected = products.filter(p => p.status === 'rejected');
  if (rejected.length) items.push({ icon: 'box', bad: true, act: 'view', view: 'products',
    title: `${plural(rejected.length, 'listing')} rejected`, sub: 'Read the reason, edit, and it goes back into review' });
  const pending = products.filter(p => p.status === 'pending');
  if (pending.length) items.push({ icon: 'shield', act: 'view', view: 'products',
    title: `${plural(pending.length, 'listing')} awaiting approval`, sub: 'Usually reviewed within a day' });
  return items;
}

/* ============================================================
   OVERVIEW
   ============================================================ */
/**
 * A KPI number from the figure SQL computed over the WHOLE table.
 *
 * SD.data.orders is capped at 500 rows (seller_orders), so reducing over
 * it measures a window. SD.data.stats is computed in SQL over every row.
 * $fallback keeps an older payload rendering.
 */
function statNum(key, fallback){
  const v = (SD.data.stats || {})[key];
  return typeof v === 'number' ? v : fallback;
}

function overviewView(orders, products){
  const live = orders.filter(isLive);
  const live30 = live.filter(o => withinDays(o.placed_at, 30));
  const revenue30 = live30.reduce((s, o) => s + (o.total || 0), 0);
  const units30 = live30.reduce((s, o) => s + unitsOf(o), 0);
  const escrow = orders.map(SDB.payoutFor).filter(p => p.state === 'escrow');
  const pendingNet = escrow.reduce((s, p) => s + p.net, 0);

  const rev14   = daySeries(live, 14, o => o.placed_at, o => o.total || 0).map(b => b.total);
  const ord14   = daySeries(live, 14, o => o.placed_at, () => 1).map(b => b.total);
  const esc14   = daySeries(orders.filter(o => SDB.payoutFor(o).state === 'escrow'), 14, o => o.placed_at, o => SDB.payoutFor(o).net).map(b => b.total);
  const unit14  = daySeries(live, 14, o => o.placed_at, unitsOf).map(b => b.total);

  const kpis = [
    { view: 'payouts',  tone: '',          icon: 'cash',   label: 'Revenue · 30 days',   value: kpiMoney(statNum('revenue_30d', revenue30)), series: rev14 },
    { view: 'orders',   tone: 'k-mustard', icon: 'orders', label: 'Orders · 30 days',    value: statNum('orders_30d', live30.length), series: ord14 },
    { view: 'payouts',  tone: 'k-indigo',  icon: 'payout', label: 'Pending payout (net)', value: kpiMoney(pendingNet), series: esc14, note: `${plural(escrow.length, 'order')} in escrow` },
    { view: 'products', tone: '',          icon: 'box',    label: 'Units sold · 30 days', value: units30,            series: unit14 }
  ];
  const openCount = orders.filter(isOpen).length;
  const hour = new Date().getHours();
  const greet = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
  const prefs = getPrefs();

  return `
    <div class="sd-hello">
      <div class="sd-hello-main">
        ${avatarHtml('lg')}
        <div>
          <h2>${greet}, ${escapeHtml(firstName())}! 👋</h2>
          <p>${openCount
            ? `${plural(openCount, 'order')} at ${escapeHtml(storeName())} ${openCount === 1 ? 'is' : 'are'} waiting on you — here's the rest of your day at a glance.`
            : `Here's how ${escapeHtml(storeName())} is doing today.`}</p>
        </div>
      </div>
      <div class="sd-hello-actions">
        <button class="btn btn-ghost sd-btn-ico" data-act="goto-orders" data-tab="all">${DI.orders} View orders</button>
        <button class="btn btn-primary sd-btn-ico" data-act="product-new">${DI.plus} Add product</button>
      </div>
    </div>

    <div class="sd-kpis">
      ${kpis.map(k => { const t = trendInfo(k.series); return `
        <button class="sd-card sd-kpi ${k.tone}" data-act="view" data-view="${k.view}">
          <span class="sd-kpi-top"><span class="sd-kpi-label">${k.label}</span><span class="sd-kpi-ico">${DI[k.icon]}</span></span>
          <span class="sd-kpi-value">${k.value}</span>
          <span class="sd-kpi-foot">
            <span><span class="sd-trend ${t.cls}">${t.text}</span> <span class="sd-trend-note">${k.note || t.note}</span></span>
            ${sparkline(k.series.slice(-7))}
          </span>
        </button>`; }).join('')}
    </div>

    <div class="sd-ov">
      <div class="sd-ov-main">
        <div class="sd-charts">
          ${salesPanel(live)}
          ${categoryPanel(live30)}
        </div>
        ${recentOrdersPanel(orders)}
      </div>
      <aside class="sd-rail" aria-label="Insights">
        ${prefs.insights ? insightsPanel(orders, products) : ''}
        ${activityPanel(orders, products)}
        ${topProductsPanel(live30, products)}
      </aside>
    </div>`;
}

/* ---------- sales overview (hand-built SVG area chart) ---------- */
function salesPanel(live){
  const days = SD.chartDays;
  const rev = daySeries(live, days, o => o.placed_at, o => o.total || 0);
  const cnt = daySeries(live, days, o => o.placed_at, () => 1);
  const total = rev.reduce((s, b) => s + b.total, 0);
  const prev = daySeries(live, days * 2, o => o.placed_at, o => o.total || 0).slice(0, days).reduce((s, b) => s + b.total, 0);
  const d = prev ? Math.round(((total - prev) / prev) * 100) : null;
  return `<section class="sd-card">
    <div class="sd-card-head">
      <div><h2>Sales overview</h2><div class="sd-card-sub">Gross revenue, before commission</div></div>
      <div class="sd-seg" role="group" aria-label="Chart range">
        ${[7, 30].map(n => `<button class="${days === n ? 'on' : ''}" data-act="chart-days" data-days="${n}">${n}D</button>`).join('')}
      </div>
    </div>
    <div class="sd-chart-sum"><strong>${fmt(Math.round(total))}</strong>
      ${d === null ? `<span class="sd-trend-note">last ${days} days</span>` : `<span class="sd-trend ${d > 0 ? 'up' : d < 0 ? 'down' : 'flat'}">${d > 0 ? '▲' : d < 0 ? '▼' : ''} ${Math.abs(d)}%</span><span class="sd-trend-note">vs previous ${days} days</span>`}
    </div>
    ${areaChart(rev, cnt)}
    <div class="sd-legend"><span><i class="l-maroon"></i>Daily revenue</span><span>Hover a day for orders and revenue</span></div>
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
  // Catmull-Rom → cubic Bézier, clamped so the curve never dips under zero
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
        data-x="${(x(i) / W * 100).toFixed(2)}" data-y="${(y(vals[i]) / H * 100).toFixed(2)}" data-label="${escapeHtml(b.label)}" data-v="${vals[i]}" data-n="${counts[i].total}"/>
      <line class="guide" x1="${x(i).toFixed(1)}" x2="${x(i).toFixed(1)}" y1="${padT}" y2="${base}"/>
      <circle class="pt" cx="${x(i).toFixed(1)}" cy="${y(vals[i]).toFixed(1)}" r="4.5"/>
    </g>`).join('');
  return `<div class="sd-chart">
    <svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Daily gross revenue over the last ${n} days">
      <defs><linearGradient id="sdAreaGrad" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0" style="stop-color:var(--maroon); stop-opacity:.24"/>
        <stop offset="1" style="stop-color:var(--mustard); stop-opacity:.02"/>
      </linearGradient></defs>
      ${grid}
      ${empty ? '' : `<path d="${area}" fill="url(#sdAreaGrad)"/><path class="line" d="${d}"/>`}
      ${xl}${empty ? '' : hits}
      ${empty ? `<text class="empty" x="${padL + iw / 2}" y="${padT + ih / 2}" text-anchor="middle">No sales in this period yet — they'll chart here as orders arrive</text>` : ''}
    </svg>
    <div class="sd-tip" id="sdTip"></div>
  </div>`;
}

/* ---------- sales by category (donut) ---------- */
function categoryPanel(live30){
  const byCat = {};
  live30.forEach(o => (o.items || []).forEach(l => {
    const c = catOf(l);
    byCat[c] = (byCat[c] || 0) + (Number(l.product && l.product.price) || 0) * (l.qty || 1);
  }));
  let rows = Object.entries(byCat).sort((a, b) => b[1] - a[1]);
  if (rows.length > 4) rows = rows.slice(0, 3).concat([['other', rows.slice(3).reduce((s, r) => s + r[1], 0)]]);
  const total = rows.reduce((s, r) => s + r[1], 0);
  const R = 62, C = 2 * Math.PI * R;
  let off = 0;
  const segs = rows.map(([c, v], i) => {
    const len = total ? (v / total) * C : 0;
    const gap = rows.length > 1 ? Math.min(3, len / 3) : 0;
    const s = `<circle class="seg seg-${i}" cx="80" cy="80" r="${R}" stroke-width="22" stroke-dasharray="${Math.max(0, len - gap).toFixed(2)} ${C.toFixed(2)}" stroke-dashoffset="${(-off).toFixed(2)}" transform="rotate(-90 80 80)"><title>${escapeHtml(cap(c))}: ${fmt(Math.round(v))}</title></circle>`;
    off += len;
    return s;
  }).join('');
  return `<section class="sd-card">
    <div class="sd-card-head"><div><h2>Sales by category</h2><div class="sd-card-sub">Last 30 days</div></div></div>
    <div class="sd-donut">
      <svg viewBox="0 0 160 160" role="img" aria-label="Revenue split by category">
        <circle class="track" cx="80" cy="80" r="${R}" stroke-width="22"/>
        ${segs}
        <text class="c-v" x="80" y="78" text-anchor="middle">${total ? fmtAxis(total, true) : '0'}</text>
        <text class="c-l" x="80" y="96" text-anchor="middle">${total ? 'LKR total' : 'no sales yet'}</text>
      </svg>
      ${rows.length ? `<div class="sd-donut-legend">${rows.map(([c, v], i) => `
        <div><i class="sw-${i}"></i><span>${escapeHtml(c)}</span><b>${Math.round((v / total) * 100)}%</b></div>`).join('')}</div>`
        : `<p class="sd-card-sub" style="text-align:center">Your category mix appears after the first sale.</p>`}
    </div>
  </section>`;
}

/* ---------- recent orders ---------- */
function recentOrdersPanel(orders){
  const list = orders.slice().sort((a, b) => new Date(b.placed_at) - new Date(a.placed_at)).slice(0, 6);
  return `<section class="sd-card">
    <div class="sd-card-head">
      <div><h2>Recent orders</h2><div class="sd-card-sub">Your latest sub-orders — click one for details</div></div>
      <button class="sd-link" data-act="goto-orders" data-tab="all">View all ${SI.arrow}</button>
    </div>
    ${list.length ? `<div class="dtable"><table class="dt">
      <thead><tr><th>Order</th><th>Customer</th><th>Date</th><th>Status</th><th class="num">Total</th></tr></thead>
      <tbody>${list.map(o => `<tr class="is-click" data-act="order-open" data-key="${escapeHtml(o.key)}">
        <td><div class="pcell">${orderThumb(o)}<div style="min-width:0"><div class="name">${itemsLine(o)}</div><span class="sub sd-mono">${escapeHtml(o.id)}</span></div></div></td>
        <td><div class="sd-cust">${personAv(o.shipping.name)}<span>${escapeHtml(o.shipping.name || '—')}</span></div></td>
        <td style="white-space:nowrap">${shortDate(o.placed_at)}</td>
        <td>${statusPill(o.status)}</td>
        <td class="num"><strong>${fmt(o.total || 0)}</strong></td>
      </tr>`).join('')}</tbody></table></div>`
      : emptyBlock(DI.empty, 'No orders yet', 'When a shopper buys one of your listings it lands here with their shipping details.', `<a class="btn btn-ghost btn-sm" href="products.html">View storefront</a>`)}
  </section>`;
}

/* ---------- AI insights (rotating, computed from the store's own data) ---------- */
function insightTips(orders, products){
  const tips = [];
  const live = orders.filter(isLive);
  const rev14 = daySeries(live, 14, o => o.placed_at, o => o.total || 0).map(b => b.total);
  const t = trendInfo(rev14);
  if (t.cls === 'up' && t.text !== '▲ new') tips.push({ html: `Revenue is <b>up ${t.text.replace('▲ ', '')}</b> on last week. Keep stock healthy on your best sellers so the momentum isn't lost to "out of stock".`, ask: 'How are my sales trending?' });
  if (t.cls === 'down') tips.push({ html: `Revenue is <b>down ${t.text.replace('▼ ', '')}</b> week on week. A short scheduled sale on a slow-moving listing is the quickest lever you have.`, ask: 'Which listings should I put on sale?' });
  const low = products.filter(p => p.status === 'active' && stockTag(p) !== 'ok').sort((a, b) => a.stock - b.stock);
  if (low.length) tips.push({ html: `<b>${escapeHtml(low[0].title)}</b> is ${low[0].stock ? `down to ${low[0].stock} units` : 'out of stock'}. The AI consultant stops recommending listings shoppers can't buy.`, ask: 'Which product should I restock?' });
  const stale = orders.filter(isStale);
  if (stale.length) tips.push({ html: `<b>${plural(stale.length, 'order')}</b> ${stale.length === 1 ? 'has' : 'have'} sat for over ${STALE_HOURS}h. Fast dispatch releases your escrow sooner.`, ask: 'Which orders need attention?' });
  const thin = products.filter(p => p.status !== 'rejected' && Object.keys(p.specs || {}).length < 3);
  if (thin.length) tips.push({ html: `<b>${plural(thin.length, 'listing')}</b> ${thin.length === 1 ? 'has' : 'have'} fewer than 3 specs. Consult's shopping AI matches on specs — "16GB DDR5" beats "good RAM".`, ask: 'Write a description for my new listing' });
  const sold = new Set(); live.filter(o => withinDays(o.placed_at, 30)).forEach(o => (o.items || []).forEach(l => sold.add(l.product && l.product.id)));
  const idle = products.filter(p => p.status === 'active' && p.stock > LOW_STOCK && !sold.has(p.id) && !p.sale);
  if (idle.length) tips.push({ html: `<b>${escapeHtml(idle[0].title)}</b> hasn't sold in 30 days${idle.length > 1 ? ` (nor have ${idle.length - 1} others)` : ''}. Try a scheduled sale from Inventory.`, ask: 'Which listings should I put on sale?' });
  if (!tips.length) tips.push({ html: `Everything's in order. Listings with <b>clear, structured specs</b> get recommended more often by the shopping consultant.`, ask: 'How can I get more sales?' });
  return tips;
}
function insightsPanel(orders, products){
  const tips = insightTips(orders, products);
  const i = SD.tipIdx % tips.length;
  return `<section class="sd-card sd-insight">
    <div class="sd-insight-head"><span class="sd-spark-badge">${SI.sparkle}</span><div><strong>AI Insights</strong><span>Tip ${i + 1} of ${tips.length} · from your store data</span></div></div>
    <p class="sd-insight-tip">${tips[i].html}</p>
    ${tips.length > 1 ? `<div class="sd-insight-dots">${tips.map((_, k) => `<button class="${k === i ? 'on' : ''}" data-act="tip" data-i="${k}" aria-label="Tip ${k + 1}"></button>`).join('')}</div>` : '<div style="height:14px"></div>'}
    <div class="sd-insight-acts">
      <button class="btn btn-dark sd-btn-ico" data-act="ai-open" data-prompt="${escapeHtml(tips[i].ask)}">${SI.sparkle} Ask the assistant</button>
      ${tips.length > 1 ? `<button class="btn btn-ghost" data-act="tip" data-i="${(i + 1) % tips.length}">Next tip</button>` : ''}
    </div>
  </section>`;
}

/* ---------- recent activity: derived from the real records ---------- */
function activityEvents(orders, products){
  const ev = [];
  orders.forEach(o => {
    if (o.placed_at) ev.push({ t: o.placed_at, kind: 'order', icon: SI.cart, html: `New order <strong>${escapeHtml(o.id)}</strong> — ${fmt(o.total || 0)} from ${escapeHtml(o.shipping.name || 'a customer')}` });
    (o.status_log || []).forEach(l => {
      if (l.status === 'pending') return;
      const who = l.changed_by === 'admin' ? ' by an admin' : '';
      if (l.status === 'delivered') ev.push({ t: l.changed_at, kind: 'money', icon: DI.cash, html: `Payout released for <strong>${escapeHtml(o.id)}</strong> — ${fmt(SDB.payoutFor(o).net)} net` });
      else ev.push({ t: l.changed_at, kind: l.status === 'disputed' || l.status === 'cancelled' ? 'admin' : 'status', icon: l.status === 'disputed' ? DI.flag : DI.check, html: `<strong>${escapeHtml(o.id)}</strong> marked ${escapeHtml(l.status)}${who}` });
    });
  });
  products.forEach(p => {
    if (p.created_at) ev.push({ t: p.created_at, kind: 'product', icon: DI.box, html: `Listed <strong>${escapeHtml(p.title)}</strong> for review` });
    if (p.reviewed_at) ev.push({ t: p.reviewed_at, kind: p.status === 'rejected' ? 'admin' : 'status', icon: DI.shield,
      html: `<strong>${escapeHtml(p.title)}</strong> ${p.status === 'rejected' ? 'rejected' : 'approved'} by moderation` });
  });
  return ev.sort((a, b) => new Date(b.t) - new Date(a.t));
}
function activityPanel(orders, products){
  const ev = activityEvents(orders, products).slice(0, 6);
  return `<section class="sd-card">
    <div class="sd-card-head"><div><h2>Recent activity</h2><div class="sd-card-sub">Orders, payouts and moderation</div></div></div>
    ${ev.length ? `<div class="sd-feed">${ev.map(e => `
      <div class="sd-feed-item"><span class="sd-feed-ico ${e.kind}">${e.icon || SI.cart}</span>
        <div class="sd-feed-copy">${e.html}<span class="sd-feed-time">${timeAgo(e.t)}</span></div></div>`).join('')}</div>`
      : `<p class="sd-card-sub">Nothing yet — activity appears here as orders and listings move.</p>`}
  </section>`;
}

/* ---------- top products ---------- */
function topProductsPanel(live30, products){
  const agg = {};
  live30.forEach(o => (o.items || []).forEach(l => {
    const p = l.product || {}; if (!p.id) return;
    const a = agg[p.id] || (agg[p.id] = { p, units: 0, rev: 0 });
    a.units += l.qty || 1; a.rev += (Number(p.price) || 0) * (l.qty || 1);
  }));
  const rows = Object.values(agg).sort((a, b) => b.rev - a.rev).slice(0, 5);
  const max = rows.length ? rows[0].rev : 1;
  return `<section class="sd-card">
    <div class="sd-card-head"><div><h2>Top products</h2><div class="sd-card-sub">By revenue, last 30 days</div></div>
      <button class="sd-link" data-act="view" data-view="products">All ${SI.arrow}</button></div>
    ${rows.length ? `<div class="sd-top-list">${rows.map((r, i) => {
      const cur = products.find(p => p.id === r.p.id) || r.p;
      return `<div class="sd-top-item">
        <span class="sd-rank">${i + 1}</span>${smallThumb(cur)}
        <div style="min-width:0"><div class="sd-top-name" title="${escapeHtml(cur.title || '')}">${escapeHtml(cur.title || 'Listing')}</div><div class="sd-top-bar"><i style="width:${Math.max(6, Math.round(r.rev / max * 100))}%"></i></div></div>
        <div class="sd-top-meta"><b>${fmtAxis(r.rev, true)}</b><span>${plural(r.units, 'unit')}</span></div>
      </div>`; }).join('')}</div>`
      : `<p class="sd-card-sub">No sales in the last 30 days. Your best sellers will rank here.</p>`}
  </section>`;
}

function emptyBlock(ico, title, body, actions = ''){
  return `<div class="sd-empty"><span class="dempty-ico">${ico}</span><strong>${escapeHtml(title)}</strong><p>${body}</p>${actions ? `<div class="dempty-actions">${actions}</div>` : ''}</div>`;
}

/* ============================================================
   ORDERS — table (tabs / search / sort / pages / bulk) and board
   ============================================================ */
function orderThumb(o){
  const p = o.items && o.items[0] && o.items[0].product;
  return p ? smallThumb(p) : `<span class="pthumb-sm">${DI.box}</span>`;
}
function itemsLine(o){
  return (o.items || []).map(l => { const p = safeProduct(l.product); return `${escapeHtml(p.title)}${l.qty > 1 ? ` ×${l.qty}` : ''}`; }).join(', ') || 'Unknown product';
}

function ordersView(orders){
  const counts = { all: orders.length };
  [...FLOW, ...TERMINAL].forEach(s => { counts[s] = orders.filter(o => o.status === s).length; });

  let list = orders.slice();
  if (SD.tab === '__stale') list = list.filter(isStale);
  else if (SD.tab !== 'all') list = list.filter(o => o.status === SD.tab);
  list = list.filter(inRange);
  const q = SD.query.trim().toLowerCase();
  if (q) list = list.filter(o =>
    (o.id || '').toLowerCase().includes(q) || (o.order_id || '').toLowerCase().includes(q) ||
    (o.shipping.name || '').toLowerCase().includes(q) ||
    (o.items || []).some(l => safeProduct(l.product).title.toLowerCase().includes(q)));

  const tabs = [['all', 'All'], ...FLOW.map(s => [s, cap(s)]), ...TERMINAL.filter(s => counts[s]).map(s => [s, cap(s)])];
  const actionable = list.filter(o => nextOf(o.status));
  const sel = SD.selected.size;
  const rangeSelect = `<select class="dselect" data-act="range" aria-label="Date range">
      ${[['all', 'All time'], ['today', 'Today'], ['7d', 'Last 7 days'], ['30d', 'Last 30 days']].map(([k, l]) => `<option value="${k}"${SD.range === k ? ' selected' : ''}>${l}</option>`).join('')}
    </select>`;

  return `<section class="sd-card">
    <div class="sd-card-head">
      <div><h2>Orders</h2><div class="sd-card-sub">Only sub-orders for your listings · customer name, address and phone only</div></div>
      <div class="sd-card-actions">
        <div class="sd-seg" role="group" aria-label="Layout">
          <button class="${SD.mode === 'list' ? 'on' : ''}" data-act="mode" data-mode="list">${DI.list} Table</button>
          <button class="${SD.mode === 'board' ? 'on' : ''}" data-act="mode" data-mode="board">${DI.board} Board</button>
        </div>
      </div>
    </div>

    ${SD.mode === 'board' ? boardView(orders.filter(inRange).filter(o => !q || list.includes(o)), rangeSelect) : `
    <div class="dtabs" role="tablist">
      ${tabs.map(([k, label]) => `<button class="dtab${SD.tab === k ? ' on' : ''}" role="tab" aria-selected="${SD.tab === k}" data-act="tab" data-tab="${k}">${label}<span class="dtab-n">${counts[k]}</span></button>`).join('')}
      ${SD.tab === '__stale' ? `<button class="dtab on" role="tab" aria-selected="true" data-act="tab" data-tab="all">Needs attention<span class="dtab-n">${list.length}</span></button>` : ''}
    </div>
    <div class="sd-toolbar">
      <div class="dsearch"><span class="dsearch-ico">${DI.search}</span>
        <input id="sdSearch" type="search" placeholder="Search order ID, customer, or product…" value="${escapeHtml(SD.query)}" aria-label="Search orders"></div>
      ${rangeSelect}
      ${actionable.length ? `<label class="dcheck-label"><input type="checkbox" data-act="select-all" ${sel && sel === actionable.length ? 'checked' : ''}> Select all actionable</label>` : ''}
    </div>
    ${sel ? `<div class="dbulk"><span><strong>${sel}</strong> selected</span><span class="muted">each moves forward one stage</span>
      <button class="btn btn-primary btn-sm" data-act="bulk-advance">Advance ${plural(sel, 'order')}</button>
      <button class="btn btn-ghost btn-sm" style="color:#fff; border-color:rgba(255,255,255,.4)" data-act="bulk-clear">Clear</button></div>` : ''}
    ${list.length ? ordersTable(list) : ordersEmpty(orders.length, q || SD.tab !== 'all' || SD.range !== 'all')}`}
  </section>`;
}

function ordersTable(list){
  const { k, dir } = SD.osort;
  const key = {
    date: o => new Date(o.placed_at).getTime() || 0,
    total: o => o.total || 0,
    customer: o => (o.shipping.name || '').toLowerCase(),
    status: o => { const i = FLOW.indexOf(o.status); return i < 0 ? 9 : i; }
  }[k];
  list = list.slice().sort((a, b) => { const x = key(a), y = key(b); return (x > y ? 1 : x < y ? -1 : 0) * dir; });
  const pages = Math.max(1, Math.ceil(list.length / PAGE_SIZE));
  SD.opage = Math.min(Math.max(1, SD.opage), pages);
  const from = (SD.opage - 1) * PAGE_SIZE;
  const rows = list.slice(from, from + PAGE_SIZE);
  const th = (id, label, cls = '') => `<th class="${cls}"><button class="sd-sort${k === id ? ' on' : ''}" data-act="osort" data-k="${id}">${label}<i>${k === id ? (dir > 0 ? '▲' : '▼') : '▲▼'}</i></button></th>`;

  return `<div class="dtable"><table class="dt">
    <thead><tr><th style="width:28px"></th><th>Order</th>${th('customer', 'Customer')}${th('date', 'Placed')}${th('status', 'Status')}${th('total', 'Total', 'num')}<th class="acts">Actions</th></tr></thead>
    <tbody>${rows.map(orderRow).join('')}</tbody></table></div>
    <div class="sd-pager">
      <span>Showing ${from + 1}–${from + rows.length} of ${list.length}</span>
      ${pages > 1 ? `<div class="sd-pager-btns">
        <button data-act="opage" data-p="${SD.opage - 1}" ${SD.opage === 1 ? 'disabled' : ''} aria-label="Previous page">‹</button>
        ${Array.from({ length: pages }, (_, i) => i + 1).filter(p => p === 1 || p === pages || Math.abs(p - SD.opage) <= 1)
          .map((p, i, arr) => `${i && p - arr[i - 1] > 1 ? '<span style="padding:0 4px">…</span>' : ''}<button class="${p === SD.opage ? 'on' : ''}" data-act="opage" data-p="${p}">${p}</button>`).join('')}
        <button data-act="opage" data-p="${SD.opage + 1}" ${SD.opage === pages ? 'disabled' : ''} aria-label="Next page">›</button>
      </div>` : ''}
    </div>`;
}

function orderRow(o){
  const next = nextOf(o.status);
  const late = isStale(o);
  const pay = SDB.payoutFor(o);
  const ship = o.shipping || {};
  return `<tr class="is-click${SD.justChanged.has(o.key) ? ' just-changed' : ''}${SD.selected.has(o.key) ? ' is-active' : ''}" data-act="order-open" data-key="${escapeHtml(o.key)}">
    <td>${next ? `<input class="ocheck" type="checkbox" data-act="select" data-key="${escapeHtml(o.key)}" ${SD.selected.has(o.key) ? 'checked' : ''} aria-label="Select ${escapeHtml(o.id)}">` : ''}</td>
    <td><div class="pcell">${orderThumb(o)}<div style="min-width:0"><div class="name">${itemsLine(o)}</div><span class="sub sd-mono">${escapeHtml(o.id)} · ${escapeHtml(o.order_id)}</span></div></div></td>
    <td><div class="sd-cust">${personAv(ship.name)}<div style="min-width:0"><div>${escapeHtml(ship.name || '—')}</div><span class="sub">${escapeHtml(ship.city || '')}${ship.phone ? ' · ' + escapeHtml(ship.phone) : ''}</span></div></div></td>
    <td style="white-space:nowrap">${shortDate(o.placed_at)}<span class="sub">${timeAgo(o.placed_at)}</span></td>
    <td><div class="sd-status-cell">${statusPill(o.status)}<small class="${late ? 'late' : ''}">${late ? '<i class="sd-flag"></i>' : ''}${o.status} ${timeAgo(o.status_updated_at)}</small></div></td>
    <td class="num"><strong>${fmt(o.total || 0)}</strong><span class="sub">${fmt(pay.net)} net</span></td>
    <td class="acts"><div class="sd-row-acts">
      ${next ? `<button class="btn btn-primary" data-act="advance" data-key="${escapeHtml(o.key)}">Mark ${next}</button>`
        : o.status === 'delivered' ? `<span class="released">✓ paid out</span>`
        : `<span class="muted" style="font-size:11.5px">${o.refunded_at ? 'refunded' : 'no action'}</span>`}
      <button class="sd-sq" data-act="order-open" data-key="${escapeHtml(o.key)}" title="View details" aria-label="View ${escapeHtml(o.id)}">${SI.eye}</button>
    </div></td>
  </tr>`;
}

function ordersEmpty(total, filtered){
  if (filtered || total) return emptyBlock(DI.search, 'Nothing matches that filter', 'Try a different tab, range, or clear the search.', `<button class="btn btn-ghost btn-sm" data-act="reset-filters">Clear filters</button>`);
  return emptyBlock(DI.empty, 'No orders yet', 'When a shopper buys one of your listings, it lands here with their shipping details and a one-tap way to move it along.',
    `<button class="btn btn-dark btn-sm" data-act="view" data-view="products">Add a listing</button><a class="btn btn-ghost btn-sm" href="products.html">View storefront</a>`);
}

/* ---------- kanban board ---------- */
function boardView(orders, rangeSelect){
  const parked = orders.filter(o => TERMINAL.includes(o.status)).length;
  return `
    <div class="sd-toolbar">
      <div class="dsearch"><span class="dsearch-ico">${DI.search}</span>
        <input id="sdSearch" type="search" placeholder="Filter cards…" value="${escapeHtml(SD.query)}" aria-label="Search orders"></div>
      ${rangeSelect}
      <span class="muted" style="font-size:12px">Drag a card one column right to advance it · click for details</span>
    </div>
    <div class="kanban" id="kanban">
      ${FLOW.map(s => {
        const cards = orders.filter(o => o.status === s).sort((a, b) => new Date(a.status_updated_at) - new Date(b.status_updated_at));
        return `<div class="kcol" data-status="${s}">
          <div class="kcol-head"><i></i>${s}<span class="n">${cards.length}</span></div>
          <div class="kcol-body">${cards.length ? cards.map(kanbanCard).join('') : `<div class="kcol-empty">${s === 'pending' ? 'No new orders' : 'Drop here'}</div>`}</div>
        </div>`;
      }).join('')}
    </div>
    ${parked ? `<p class="muted" style="font-size:12px; margin-top:16px">${parked} cancelled or disputed order${parked === 1 ? ' is' : 's are'} only shown in the table view.</p>` : ''}`;
}
function kanbanCard(o){
  const next = nextOf(o.status);
  const late = isStale(o);
  return `<article class="dcard lift kcard${late ? ' is-stale' : ''}${SD.justChanged.has(o.key) ? ' just-changed' : ''}" draggable="${next ? 'true' : 'false'}" data-act="order-open" data-key="${escapeHtml(o.key)}" data-status="${o.status}" title="${next ? 'Drag to ' + next : 'Delivered — payout released'}">
    <div class="kcard-top"><div><div class="kcard-title">${itemsLine(o)}</div><div class="kcard-code">${escapeHtml(o.id)}</div></div></div>
    <div class="kcard-meta"><span>${escapeHtml(o.shipping.name || '—')} · ${escapeHtml(o.shipping.city || '')}</span><span class="pprice">${fmt(o.total || 0)}</span></div>
    <div class="kcard-foot"><span class="kcard-since${late ? ' late' : ''}">${timeAgo(o.status_updated_at)}</span>
      ${next ? `<button class="btn btn-ghost btn-sm" data-act="advance" data-key="${escapeHtml(o.key)}">→ ${next}</button>` : `<span class="released">✓</span>`}</div>
  </article>`;
}

/* ---------- order detail (modal) ---------- */
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
    return `<li class="${cls}"><strong>${STEP_LABEL[s]}</strong><span>${when ? `${shortDate(when)} · ${timeAgo(when)}${e && e.changed_by === 'admin' ? ' · by admin' : ''}` : cls === 'todo' ? 'Not yet' : ''}</span></li>`;
  });
  if (idx < 0){
    const e = at(o.status);
    rows.push(`<li class="bad"><strong>${escapeHtml(cap(o.status))}</strong><span>${e ? `${shortDate(e.changed_at)} · ${timeAgo(e.changed_at)}${e.note ? ' · ' + escapeHtml(e.note) : ''}` : ''}</span></li>`);
  }
  return `<ul class="sd-timeline">${rows.join('')}</ul>`;
}

function openOrder(key){
  const o = myOrders().find(x => x.key === key);
  if (!o){ toast("That order couldn't be found"); return; }
  closePops();
  SD.openKey = key;
  const next = nextOf(o.status);
  const pay = SDB.payoutFor(o);
  const ship = o.shipping || {};
  modalClass('sd-modal-wide');
  openDashModal(`
    <div class="sd-od-head">
      <div><h3>${escapeHtml(o.id)}</h3><div class="sd-card-sub">Part of ${escapeHtml(o.order_id)} · placed ${shortDate(o.placed_at)} (${timeAgo(o.placed_at)})</div></div>
      <div style="display:flex; gap:6px; align-items:center">${statusPill(o.status)}${payPill(pay.state)}</div>
    </div>
    <div class="sd-od-body">
      <div>
        <div class="sd-od-sec"><div class="sd-od-label">Items</div>
          <div class="sd-od-items">${(o.items || []).map(l => { const p = safeProduct(l.product); return `
            <div class="sd-od-item">${smallThumb(l.product || {})}<div class="grow"><strong>${escapeHtml(p.title)}</strong><span>${escapeHtml(p.subtitle)}</span></div>
              <div style="text-align:right"><div class="sd-price">${fmtOrNA(p.price === null ? null : p.price * (l.qty || 1))}</div><span class="sd-mono">${l.qty || 1} × ${fmtOrNA(p.price)}</span></div></div>`; }).join('')}</div></div>
        <div class="sd-od-sec"><div class="sd-od-label">Payout</div>
          <div class="sd-od-box sd-od-sum">
            <div><span>Gross</span><span>${fmt(pay.gross)}</span></div>
            <div><span>Platform commission (${pct(pay.rate)})</span><span>− ${fmt(pay.commission)}</span></div>
            <div class="tot"><span>Your net payout</span><span>${fmt(pay.net)}</span></div>
            <p class="sd-card-sub" style="margin-top:4px">${pay.state === 'escrow' ? 'Held in escrow until the buyer confirms delivery.' : pay.state === 'released' ? `Released ${shortDate(o.payout_released_at)}.` : pay.state === 'held' ? 'Held while the dispute is open.' : 'No payout for this sub-order.'}</p>
          </div></div>
        ${o.dispute ? `<div class="dispute-box"><span class="k">Dispute opened ${timeAgo(o.dispute.opened_at)}</span>${escapeHtml(o.dispute.reason || 'No reason recorded')}${o.dispute.resolution ? `<div class="muted" style="margin-top:4px">Resolved: ${escapeHtml(o.dispute.resolution)}</div>` : ''}</div>` : ''}
      </div>
      <div>
        <div class="sd-od-sec"><div class="sd-od-label">Ship to</div>
          <div class="sd-od-box">
            <div class="row" style="align-items:center">${personAv(ship.name)}<strong>${escapeHtml(ship.name || '—')}</strong></div>
            <div class="row">${SI.phone}<span>${ship.phone ? `<a href="tel:${escapeHtml(ship.phone)}" style="color:var(--maroon)">${escapeHtml(ship.phone)}</a>` : '—'}</span></div>
            <div class="row">${SI.pin}<span>${escapeHtml(ship.address || '—')}${ship.city ? ', ' + escapeHtml(ship.city) : ''} ${escapeHtml(ship.zip || '')}</span></div>
            ${ship.note ? `<div class="row">${SI.note}<span>${escapeHtml(ship.note)}</span></div>` : ''}
          </div></div>
        <div class="sd-od-sec"><div class="sd-od-label">Status timeline</div>${timelineHtml(o)}</div>
      </div>
    </div>
    <div class="sd-od-foot">
      <button type="button" class="btn btn-ghost btn-sm" data-act="modal-close">Close</button>
      ${next ? `<button type="button" class="btn btn-primary btn-sm" data-act="advance" data-key="${escapeHtml(o.key)}">Mark as ${next}</button>` : ''}
    </div>`);
}
function modalClass(extra){ const b = $('#dashModalBox'); if (b) b.className = 'modal-box dmodal' + (extra ? ' ' + extra : ''); }
const modalOpen = () => $('#dashModal')?.classList.contains('show');

/* ============================================================
   PRODUCTS — CRUD
   ============================================================ */
function productsView(orders, products){
  const q = SD.pquery.trim().toLowerCase();
  const n = { all: products.length, active: 0, pending: 0, inactive: 0, rejected: 0 };
  products.forEach(p => { n[p.status] = (n[p.status] || 0) + 1; });
  const list = products
    .filter(p => SD.pfilter === 'all' || p.status === SD.pfilter)
    .filter(p => !q || p.title.toLowerCase().includes(q) || (p.category || '').includes(q))
    .sort((a, b) => new Date(b.updated_at || b.created_at || 0) - new Date(a.updated_at || a.created_at || 0));
  const filters = [['all', 'All'], ['active', 'Live'], ['pending', 'In review'], ['inactive', 'Paused'], ['rejected', 'Rejected']].filter(([k]) => k === 'all' || n[k]);

  return `<section class="sd-card">
    <div class="sd-card-head">
      <div><h2>Products</h2><div class="sd-card-sub">${plural(products.length, 'listing')} · ${n.active} live · ${n.pending} in review${n.rejected ? ` · ${n.rejected} rejected` : ''}</div></div>
      <div class="sd-card-actions">
        <div class="sd-seg" role="group" aria-label="Layout">
          <button class="${SD.pmode === 'grid' ? 'on' : ''}" data-act="pmode" data-mode="grid">${SI.grid} Grid</button>
          <button class="${SD.pmode === 'table' ? 'on' : ''}" data-act="pmode" data-mode="table">${DI.list} Table</button>
        </div>
        <button class="btn btn-primary btn-sm sd-btn-ico" data-act="product-new">${DI.plus} Add product</button>
      </div>
    </div>
    <div class="dtabs">${filters.map(([k, l]) => `<button class="dtab${SD.pfilter === k ? ' on' : ''}" data-act="pfilter" data-f="${k}">${l}<span class="dtab-n">${n[k] || 0}</span></button>`).join('')}</div>
    <div class="sd-toolbar"><div class="dsearch"><span class="dsearch-ico">${DI.search}</span><input id="sdPSearch" type="search" placeholder="Search your listings…" value="${escapeHtml(SD.pquery)}" aria-label="Search products"></div></div>
    ${!list.length
      ? emptyBlock(DI.box, q || SD.pfilter !== 'all' ? 'No listings match' : 'No listings yet',
          q || SD.pfilter !== 'all' ? 'Try another search or filter.' : 'Add your first product — it goes to moderation, then straight onto the storefront once approved.',
          q || SD.pfilter !== 'all' ? `<button class="btn btn-ghost btn-sm" data-act="pfilter" data-f="all" data-clear="1">Clear filters</button>` : `<button class="btn btn-dark btn-sm" data-act="product-new">Add product</button>`)
      : SD.pmode === 'grid'
        ? `<div class="sd-pgrid">${list.map(productCard).join('')}</div>`
        : `<div class="dtable"><table class="dt">
            <thead><tr><th>Listing</th><th>Category</th><th class="num">Price</th><th class="num">Stock</th><th>Status</th><th class="acts">Actions</th></tr></thead>
            <tbody>${list.map(productRow).join('')}</tbody></table></div>`}
  </section>`;
}
function saleTag(p){
  const eff = SDB.effectivePrice(p);
  if (eff.onSale) return `<span class="sd-sale-tag" title="until ${escapeHtml(p.sale.end_date)}">On sale</span>`;
  if (p.sale && p.sale.start_date && p.sale.start_date > new Date().toISOString().slice(0, 10)) return `<span class="sd-sale-tag soon" title="from ${escapeHtml(p.sale.start_date)}">Sale soon</span>`;
  return '';
}
function priceHtml(p){
  const eff = SDB.effectivePrice(p);
  return `<span class="sd-price">${fmt(eff.price)}${eff.oldPrice ? `<s>${fmt(eff.oldPrice)}</s>` : ''}</span>`;
}
function productActs(p){
  const canToggle = p.status === 'active' || p.status === 'inactive';
  return `
    <button class="sd-sq" data-act="product-edit" data-id="${escapeHtml(p.id)}" title="Edit" aria-label="Edit ${escapeHtml(p.title)}">${DI.edit}</button>
    ${canToggle ? `<button class="sd-sq" data-act="product-toggle" data-id="${escapeHtml(p.id)}" title="${p.status === 'active' ? 'Pause listing' : 'Resume listing'}" aria-label="${p.status === 'active' ? 'Pause' : 'Resume'} ${escapeHtml(p.title)}">${p.status === 'active' ? SI.pause : SI.play}</button>` : ''}
    <button class="sd-sq danger" data-act="product-delete" data-id="${escapeHtml(p.id)}" title="Delete" aria-label="Delete ${escapeHtml(p.title)}">${DI.trash}</button>`;
}
function productCard(p){
  const src = (p.images && p.images[0]) || p.image || '';
  return `<article class="sd-card sd-pcard" style="padding:0">
    <div class="sd-pcard-img">${src
      ? `<img src="${escapeHtml(src)}" alt="" onerror="this.outerHTML = window.iconMarkup('${escapeHtml(p.icon || 'laptop')}', 56)">`
      : icon(p.icon || 'laptop', 56)}
      ${statusPill(p.status)}${saleTag(p)}</div>
    <div class="sd-pcard-body">
      <span class="sd-pcard-cat">${escapeHtml(p.category || '')}</span>
      <div class="sd-pcard-name">${escapeHtml(p.title)}</div>
      <div class="sd-pcard-sub">${escapeHtml(p.subtitle || '')}</div>
      <div class="sd-pcard-row">${priceHtml(p)}<span class="stock-tag ${stockTag(p)}">${p.stock <= 0 ? 'out' : p.stock + ' in stock'}</span></div>
    </div>
    ${p.status === 'rejected' ? `<div class="reason-box"><span class="k">Rejected — reason</span>${escapeHtml(p.rejection_reason || 'No reason given')}</div>` : ''}
    <div class="sd-pcard-foot">
      <button class="btn btn-ghost" data-act="product-edit" data-id="${escapeHtml(p.id)}">${p.status === 'rejected' ? 'Fix & resubmit' : 'Edit'}</button>
      ${p.status === 'active' || p.status === 'inactive' ? `<button class="sd-sq" data-act="product-toggle" data-id="${escapeHtml(p.id)}" title="${p.status === 'active' ? 'Pause listing' : 'Resume listing'}" aria-label="${p.status === 'active' ? 'Pause' : 'Resume'}">${p.status === 'active' ? SI.pause : SI.play}</button>` : ''}
      <button class="sd-sq danger" data-act="product-delete" data-id="${escapeHtml(p.id)}" title="Delete" aria-label="Delete ${escapeHtml(p.title)}">${DI.trash}</button>
    </div>
  </article>`;
}
function productRow(p){
  const eff = SDB.effectivePrice(p);
  return `<tr>
    <td><div class="pcell">${smallThumb(p)}<div style="min-width:0"><div class="name">${escapeHtml(p.title)}</div><span class="sub">${escapeHtml(p.subtitle || '')}</span>
      ${p.status === 'rejected' ? `<div class="reason-box"><span class="k">Rejected — reason</span>${escapeHtml(p.rejection_reason || 'No reason given')}</div>` : ''}</div></div></td>
    <td><span class="mono" style="font-size:11.5px">${escapeHtml(p.category)}</span></td>
    <td class="num">${fmt(eff.price)}${eff.onSale ? `<span class="sub" style="color:var(--maroon)">sale until ${escapeHtml(p.sale.end_date)}</span>` : p.sale ? `<span class="sub">sale scheduled</span>` : ''}</td>
    <td class="num"><span class="stock-tag ${stockTag(p)}">${p.stock}</span></td>
    <td>${statusPill(p.status)}</td>
    <td class="acts"><div class="sd-row-acts">${productActs(p)}</div></td></tr>`;
}

function productForm(p){
  closePops();
  const cats = SDB.settings().categories;
  const specs = Object.entries(p?.specs || {});
  const sale = p?.sale || {};
  const specRow = (k = '', v = '') => `<div class="spec-row"><input placeholder="e.g. RAM" value="${escapeHtml(k)}" data-spec="k" aria-label="Spec name"><input placeholder="e.g. 16GB DDR5" value="${escapeHtml(v)}" data-spec="v" aria-label="Spec value"><button type="button" data-act="spec-remove" title="Remove">×</button></div>`;
  modalClass('sd-form-modal');
  openDashModal(`
    <form id="productForm" autocomplete="off">
      <div class="sd-form-head"><h3>${p ? 'Edit listing' : 'Add a new product'}</h3>
        <p>${p ? escapeHtml(p.title) + (p.status !== 'active' ? ` · ${p.status === 'rejected' ? 'saving sends this back to moderation' : p.status === 'pending' ? 'still in review' : 'paused'}` : '') : 'New listings are reviewed by Consult before they appear on the storefront.'}</p></div>
      <div class="sd-form-body">
        <div class="sd-fgroup"><div class="sd-fgroup-title"><i>1</i>Basics</div>
          <div class="dform-grid">
            <div class="field span2"><label for="pfTitle">Title</label><input id="pfTitle" value="${escapeHtml(p?.title || '')}" placeholder="Lenovo ThinkPad E14 Gen 5" required></div>
            <div class="field"><label for="pfSubtitle">Subtitle</label><input id="pfSubtitle" value="${escapeHtml(p?.subtitle || '')}" placeholder="Ryzen 7 · 16GB · 512GB"></div>
            <div class="field"><label for="pfCategory">Category</label><select id="pfCategory">${cats.map(c => `<option value="${escapeHtml(c)}"${(p?.category || cats[0]) === c ? ' selected' : ''}>${escapeHtml(cap(c))}</option>`).join('')}</select></div>
            <div class="field span2"><label for="pfDesc">Description</label><textarea id="pfDesc" rows="3" placeholder="Why would someone pick this over the alternatives?">${escapeHtml(p?.description || '')}</textarea></div>
          </div></div>
        <div class="sd-fgroup"><div class="sd-fgroup-title"><i>2</i>Pricing &amp; stock</div>
          <div class="dform-grid" style="grid-template-columns:repeat(3,minmax(0,1fr))">
            <div class="field"><label for="pfPrice">Price (LKR)</label><input id="pfPrice" type="number" min="1" step="1" value="${p?.price || ''}" required></div>
            <div class="field"><label for="pfOld">Was price <span class="muted">(optional)</span></label><input id="pfOld" type="number" min="0" step="1" value="${p?.old_price || ''}"></div>
            <div class="field"><label for="pfStock">Stock</label><input id="pfStock" type="number" min="0" step="1" value="${p ? p.stock : 1}"></div>
          </div></div>
        <div class="sd-fgroup"><div class="sd-fgroup-title"><i>3</i>Specs<small>what the AI consultant reasons over</small></div>
          <div class="spec-rows" id="specRows">${specs.length ? specs.map(([k, v]) => specRow(k, v)).join('') : specRow()}</div>
          <button type="button" class="btn btn-ghost btn-sm sd-btn-ico" data-act="spec-add">${DI.plus} Add spec</button>
          <p class="dhint" style="margin-top:8px">"16GB DDR5" beats "good RAM".</p></div>
        <div class="sd-fgroup"><div class="sd-fgroup-title"><i>4</i>Images</div>
          <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:10px">
            <label class="btn btn-dark btn-sm sd-btn-ico" for="pfImageFile" style="cursor:pointer">${SI.upload} Upload an image</label>
            <input type="file" id="pfImageFile" accept="image/png,image/jpeg,image/webp" hidden multiple>
            <span class="muted" style="font-size:11.5px" id="pfImageNote">JPG, PNG or WebP · up to 2 MB each</span>
          </div>
          <div class="field"><label for="pfImages">Image paths (one per line)</label><textarea id="pfImages" rows="3" placeholder="uploads/product-....webp">${escapeHtml((p?.images || []).join('\n'))}</textarea></div>
          <p class="dhint">Uploading adds the stored path here. Only paths under
            <span class="mono">uploads/</span> or <span class="mono">images/</span> are accepted —
            the server drops anything else.</p></div>
        <div class="sd-fgroup"><div class="sd-fgroup-title"><i>5</i>Scheduled sale<small>optional · runs date to date</small></div>
          <div class="dform-grid" style="grid-template-columns:repeat(3,minmax(0,1fr))">
            <div class="field"><label for="pfSalePrice">Sale price</label><input id="pfSalePrice" type="number" min="1" step="1" value="${sale.sale_price || ''}"></div>
            <div class="field"><label for="pfSaleFrom">From</label><input id="pfSaleFrom" type="date" value="${sale.start_date || ''}"></div>
            <div class="field"><label for="pfSaleTo">Until</label><input id="pfSaleTo" type="date" value="${sale.end_date || ''}"></div>
          </div></div>
      </div>
      <div class="sd-form-foot">
        <button type="button" class="btn btn-ghost btn-sm" data-act="modal-close">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">${p ? 'Save changes' : 'Submit for review'}</button>
      </div>
    </form>`);
  $('#productForm').addEventListener('submit', e => { e.preventDefault(); saveProductFromForm(p ? p.id : null); });
}
/**
 * Upload the chosen images and append each stored path to the form's
 * textarea.
 *
 * The upload happens NOW but the listing is not touched until the seller
 * saves the form — api/seller/upload.php only stores the file and hands back
 * its path. So cancelling the form leaves a live listing's gallery alone;
 * the cost is an orphaned file in uploads/, which is the right way round.
 *
 * Sequential rather than parallel: three 2MB uploads at once on a slow
 * connection is how you get one of them timing out.
 */
function uploadProductImages(files){
  const chosen = [...(files || [])];
  if (!chosen.length) return;

  const note = $('#pfImageNote');
  const box  = $('#pfImages');
  if (!box) return;

  const tooBig = chosen.filter(f => f.size > 2 * 1024 * 1024);
  if (tooBig.length){
    toast(`${tooBig.length === 1 ? 'That image is' : `${tooBig.length} images are`} over 2 MB`);
  }
  const queue = chosen.filter(f => f.size <= 2 * 1024 * 1024);
  if (!queue.length) return;

  let done = 0;
  const setNote = msg => { if (note) note.textContent = msg; };
  setNote(`Uploading 1 of ${queue.length}...`);

  queue.reduce(
    (chain, file, i) => chain.then(() => {
      setNote(`Uploading ${i + 1} of ${queue.length}...`);
      return API.Seller.uploadImage(file)
        .then(res => {
          // Append rather than replace: a listing can have several images.
          const lines = box.value.split('\n').map(x => x.trim()).filter(Boolean);
          lines.push(res.url);
          box.value = lines.join('\n');
          done++;
        })
        .catch(err => {
          console.error('[seller] image upload failed:', err);
          toast(err.message || 'That image could not be uploaded.');
        });
    }),
    Promise.resolve()
  ).then(() => {
    setNote(done
      ? `${done} image${done === 1 ? '' : 's'} uploaded - save the listing to attach ${done === 1 ? 'it' : 'them'}`
      : 'JPG, PNG or WebP - up to 2 MB each');
    if (done) toast(`${done} image${done === 1 ? '' : 's'} uploaded`);
  });
}

function saveProductFromForm(id){
  const box = $('#dashModalBox');
  const v = sel => box.querySelector(sel).value;
  const specs = {};
  box.querySelectorAll('.spec-row').forEach(r => {
    const k = r.querySelector('[data-spec="k"]').value.trim(), val = r.querySelector('[data-spec="v"]').value.trim();
    if (k && val) specs[k] = val;
  });
  const salePrice = Number(v('#pfSalePrice')), from = v('#pfSaleFrom'), to = v('#pfSaleTo');
  if (salePrice && (!from || !to || to < from)){ toast('A sale needs a valid date range'); return; }
  if (salePrice && salePrice >= Number(v('#pfPrice'))){ toast('Sale price must be below the regular price'); return; }
  const btn = box.querySelector('#productForm button[type="submit"]');
  const btnLabel = btn ? btn.textContent : '';
  if (btn){ btn.disabled = true; btn.textContent = 'Saving...'; }

  /* The images textarea holds paths this app already stored — a seeded
     'images/...' file, or an 'uploads/...' name minted by the upload button.
     The server re-checks that pattern and drops anything else, so a pasted
     off-site URL never reaches a listing. */
  sdWrite(
    API.Seller.saveProduct({
      id: id || undefined,
      title: v('#pfTitle'),
      subtitle: v('#pfSubtitle'),
      category: v('#pfCategory'),
      description: v('#pfDesc'),
      price: Number(v('#pfPrice')),
      old_price: v('#pfOld') ? Number(v('#pfOld')) : null,
      stock: Number(v('#pfStock')),
      specs: specs,
      images: v('#pfImages').split('\n').map(x => x.trim()).filter(Boolean),
      sale: salePrice ? { sale_price: salePrice, start_date: from, end_date: to } : null
    }),
    {
      // The server's own message says whether it went live or for review.
      onOk: () => closeDashModal(),
      onFail: () => { if (btn){ btn.disabled = false; btn.textContent = btnLabel; } }
    }
  );
}

/* ============================================================
   INVENTORY — stock levels + date-to-date sale scheduling
   ============================================================ */
function inventoryView(orders, products){
  const stockable = products.filter(p => p.status !== 'rejected').sort((a, b) => a.stock - b.stock);
  const out = stockable.filter(p => p.stock <= 0), low = stockable.filter(p => p.stock > 0 && p.stock <= LOW_STOCK);
  const units = stockable.reduce((s, p) => s + p.stock, 0);
  const shown = SD.ifilter === 'out' ? out : SD.ifilter === 'low' ? low : stockable;
  const sel = SD.invSelected.size;
  const maxStock = Math.max(LOW_STOCK * 4, ...stockable.map(p => p.stock));
  const card = (f, tone, ico, label, value, note) => `
    <button class="sd-card sd-kpi ${tone}" data-act="ifilter" data-f="${f}" aria-pressed="${SD.ifilter === f}" style="${SD.ifilter === f && f !== 'all' ? 'border-color:var(--maroon)' : ''}">
      <span class="sd-kpi-top"><span class="sd-kpi-label">${label}</span><span class="sd-kpi-ico">${ico}</span></span>
      <span class="sd-kpi-value">${value}</span>
      <span class="sd-kpi-foot"><span class="sd-trend-note">${note}</span>${SD.ifilter === f && f !== 'all' ? '<span class="sd-trend flat">filtered</span>' : ''}</span>
    </button>`;

  return `
    <div class="sd-kpis cols-3">
      ${card('all', 'k-indigo', DI.layers, 'Units in stock', units, `across ${plural(stockable.length, 'listing')}`)}
      ${card('low', 'k-mustard', DI.bell, 'Running low', low.length, `at or below ${LOW_STOCK} units`)}
      ${card('out', '', DI.flag, 'Out of stock', out.length, 'hidden from AI recommendations')}
    </div>
    <section class="sd-card">
      <div class="sd-card-head"><div><h2>Stock levels</h2><div class="sd-card-sub">Changes save immediately and show on the storefront</div></div>
        <div class="sd-card-actions">
          ${SD.ifilter !== 'all' ? `<button class="btn btn-ghost btn-sm" data-act="ifilter" data-f="all">Show all</button>` : ''}
          ${shown.length ? `<label class="dcheck-label"><input type="checkbox" data-act="inv-select-all" ${sel && sel === shown.length ? 'checked' : ''}> Select all</label>` : ''}
        </div></div>
      ${sel ? `<div class="dbulk"><span><strong>${sel}</strong> selected</span><span class="muted">set every selected listing to the same quantity</span>
        <input class="dselect" id="invBulkQty" type="number" min="0" step="1" value="10" style="width:84px; padding:6px 10px" aria-label="Quantity">
        <button class="btn btn-primary btn-sm" data-act="inv-bulk-apply">Set stock</button>
        <button class="btn btn-ghost btn-sm" style="color:#fff; border-color:rgba(255,255,255,.4)" data-act="inv-bulk-clear">Clear</button></div>` : ''}
      ${shown.length ? `<div class="dtable"><table class="dt">
        <thead><tr><th style="width:28px"></th><th>Listing</th><th>Status</th><th>Stock</th><th>Level</th><th>Scheduled sale</th><th class="acts"></th></tr></thead>
        <tbody>${shown.map(p => { const t = stockTag(p); return `<tr class="${SD.invSelected.has(p.id) ? 'is-active' : ''} ${t === 'low' ? 'is-low' : t === 'out' ? 'is-out' : ''}">
          <td><input type="checkbox" class="ocheck" data-act="inv-select" data-id="${escapeHtml(p.id)}" ${SD.invSelected.has(p.id) ? 'checked' : ''} aria-label="Select ${escapeHtml(p.title)}"></td>
          <td><div class="pcell">${smallThumb(p)}<div style="min-width:0"><div class="name">${escapeHtml(p.title)}</div><span class="sub mono">${escapeHtml(p.category)} · ${fmt(SDB.effectivePrice(p).price)}</span></div></div></td>
          <td>${statusPill(p.status)}</td>
          <td><div class="stk"><button type="button" data-act="stock-delta" data-id="${escapeHtml(p.id)}" data-d="-1" aria-label="Decrease">−</button>
            <input type="number" min="0" step="1" value="${p.stock}" data-act="stock-set" data-id="${escapeHtml(p.id)}" aria-label="Stock for ${escapeHtml(p.title)}">
            <button type="button" data-act="stock-delta" data-id="${escapeHtml(p.id)}" data-d="1" aria-label="Increase">+</button></div></td>
          <td><div class="sd-level"><span class="sd-level-bar"><i class="${t}" style="width:${t === 'out' ? 0 : Math.max(6, Math.round(p.stock / maxStock * 100))}%"></i></span><span class="stock-tag ${t}">${t === 'out' ? 'out' : t === 'low' ? 'low' : 'ok'}</span></div></td>
          <td class="sd-sale-cell">${saleCell(p)}</td>
          <td class="acts"><button class="btn btn-ghost btn-sm sd-btn-ico" data-act="sale-edit" data-id="${escapeHtml(p.id)}">${SI.tag} ${p.sale ? 'Edit sale' : 'Schedule'}</button></td>
        </tr>`; }).join('')}</tbody></table></div>`
        : SD.ifilter !== 'all'
          ? emptyBlock(DI.check, SD.ifilter === 'out' ? 'Nothing is out of stock' : 'Nothing is running low', 'Stock levels look healthy.', `<button class="btn btn-ghost btn-sm" data-act="ifilter" data-f="all">Show all listings</button>`)
          : emptyBlock(DI.layers, 'Nothing to stock yet', 'Inventory follows your listings — add a product first.', `<button class="btn btn-dark btn-sm" data-act="view" data-view="products">Go to products</button>`)}
    </section>`;
}
function saleCell(p){
  if (!p.sale) return `<span class="muted">—</span>`;
  const eff = SDB.effectivePrice(p);
  return `${fmt(p.sale.sale_price)} ${eff.onSale ? '<span class="sd-sale-tag">live</span>' : ''}<small>${escapeHtml(p.sale.start_date)} → ${escapeHtml(p.sale.end_date)}</small>`;
}
/* sale-only editor — posts to api/seller/sale.php */
function saleForm(p){
  const s = p.sale || {};
  modalClass('');
  openDashModal(`
    <h3>${p.sale ? 'Edit scheduled sale' : 'Schedule a sale'}</h3>
    <p class="muted" style="font-size:13px; margin:-8px 0 16px">${escapeHtml(p.title)} · regular price ${fmt(p.price)}. The sale price applies automatically between these dates.</p>
    <form id="saleForm" class="dform-grid" autocomplete="off">
      <div class="field span2"><label for="sfPrice">Sale price (LKR)</label><input id="sfPrice" type="number" min="1" step="1" value="${s.sale_price || ''}" required></div>
      <div class="field"><label for="sfFrom">From</label><input id="sfFrom" type="date" value="${s.start_date || new Date().toISOString().slice(0, 10)}" required></div>
      <div class="field"><label for="sfTo">Until</label><input id="sfTo" type="date" value="${s.end_date || ''}" required></div>
      <div class="dmodal-foot span2">
        ${p.sale ? `<button type="button" class="btn btn-ghost btn-sm left" data-act="sale-remove" data-id="${escapeHtml(p.id)}" style="color:var(--maroon)">Remove sale</button>` : ''}
        <button type="button" class="btn btn-ghost btn-sm" data-act="modal-close">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Save sale</button>
      </div>
    </form>`);
  $('#saleForm').addEventListener('submit', e => {
    e.preventDefault();
    const price = Number($('#sfPrice').value), from = $('#sfFrom').value, to = $('#sfTo').value;
    if (!price || !from || !to || to < from){ toast('A sale needs a price and a valid date range'); return; }
    if (price >= p.price){ toast('Sale price must be below the regular price'); return; }
    sdWrite(
      API.Seller.scheduleSale(p.id, price, from, to),
      { onOk: () => closeDashModal() }
    );
  });
}

/* A dedicated endpoint rather than a full product save: scheduling a
   discount should not re-submit the whole listing, and on a rejected
   listing a full save would push it back into the moderation queue. */
function removeSale(p){
  return sdWrite(API.Seller.clearSale(p.id), { onOk: () => closeDashModal() });
}

/* ============================================================
   PAYOUTS — escrow: held until the buyer confirms delivery
   ============================================================ */
function nextPayoutDate(){
  const d = new Date(); d.setHours(0, 0, 0, 0);
  d.setDate(d.getDate() + ((8 - d.getDay()) % 7 || 7));          // next Monday
  return d;
}
function payoutsView(orders){
  const rows = orders.map(o => Object.assign({ pay: SDB.payoutFor(o) }, o)).sort((a, b) => new Date(b.placed_at) - new Date(a.placed_at));
  const sum = (state, k) => rows.filter(r => r.pay.state === state).reduce((s, r) => s + r.pay[k], 0);
  const cnt = state => rows.filter(r => r.pay.state === state).length;
  const available = sum('released', 'net'), pending = sum('escrow', 'net'), held = sum('held', 'net');
  const commissionPaid = sum('released', 'commission');
  const rate = SDB.commissionRate();
  const shown = SD.ptab === 'all' ? rows : rows.filter(r => r.pay.state === SD.ptab);
  const tabs = [['all', 'All'], ['released', 'Released'], ['escrow', 'In escrow'], ['held', 'Held'], ['refunded', 'Refunded'], ['cancelled', 'Cancelled']].filter(([k]) => k === 'all' || rows.some(r => r.pay.state === k));
  const next = nextPayoutDate();
  const s = SD.me.seller || {};

  return `
    <div class="sd-kpis">
      <div class="sd-card sd-kpi"><span class="sd-kpi-top"><span class="sd-kpi-label">Released payout</span><span class="sd-kpi-ico">${DI.check}</span></span>
        <span class="sd-kpi-value">${kpiMoney(available)}</span><span class="sd-kpi-foot"><span class="sd-trend-note">${plural(cnt('released'), 'delivered order')} · after ${pct(rate)} commission</span></span></div>
      <div class="sd-card sd-kpi k-mustard"><span class="sd-kpi-top"><span class="sd-kpi-label">Pending payout</span><span class="sd-kpi-ico">${DI.clock}</span></span>
        <span class="sd-kpi-value">${kpiMoney(pending)}</span><span class="sd-kpi-foot"><span class="sd-trend-note">${plural(cnt('escrow'), 'order')} held in escrow</span></span></div>
      <div class="sd-card sd-kpi k-indigo"><span class="sd-kpi-top"><span class="sd-kpi-label">Next payout</span><span class="sd-kpi-ico">${SI.bank}</span></span>
        <span class="sd-kpi-value" style="font-size:22px">${next.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' })}</span>
        <span class="sd-kpi-foot"><span class="sd-trend-note">weekly · to ${escapeHtml(s.bank_name || 'your bank')}${s.account_number ? ' ••' + escapeHtml(String(s.account_number).slice(-4)) : ''}</span></span></div>
      <div class="sd-card sd-kpi"><span class="sd-kpi-top"><span class="sd-kpi-label">Held in disputes</span><span class="sd-kpi-ico">${DI.flag}</span></span>
        <span class="sd-kpi-value">${kpiMoney(held)}</span><span class="sd-kpi-foot"><span class="sd-trend-note">commission paid to date: ${fmt(Math.round(commissionPaid))}</span></span></div>
    </div>

    <div class="sd-card sd-escrow">
      <b>How payouts work</b>
      <span class="sd-escrow-step"><i style="background:var(--mustard)"></i>Buyer pays</span><span class="sd-escrow-arrow">→</span>
      <span class="sd-escrow-step"><i style="background:var(--indigo-soft)"></i>Held in escrow</span><span class="sd-escrow-arrow">→</span>
      <span class="sd-escrow-step"><i style="background:var(--maroon)"></i>Delivery confirmed</span><span class="sd-escrow-arrow">→</span>
      <span class="sd-escrow-step"><i style="background:var(--olive)"></i>Released, minus ${pct(rate)}${SDB.commissionIsOwn() ? ' (negotiated)' : ''}</span>
    </div>

    <section class="sd-card">
      <div class="sd-card-head"><div><h2>Payout history</h2><div class="sd-card-sub">One row per sub-order</div></div>
        <div class="sd-card-actions"><button class="btn btn-ghost btn-sm sd-btn-ico" data-act="export-payouts">${DI.download} Export CSV</button></div></div>
      <div class="dtabs">${tabs.map(([k, l]) => `<button class="dtab${SD.ptab === k ? ' on' : ''}" data-act="ptab" data-tab="${k}">${l}<span class="dtab-n">${k === 'all' ? rows.length : cnt(k)}</span></button>`).join('')}</div>
      ${shown.length ? `<div class="dtable"><table class="dt">
        <thead><tr><th>Sub-order</th><th>Placed</th><th class="num">Gross</th><th class="num">Rate</th><th class="num">Commission</th><th class="num">Net payout</th><th>Status</th></tr></thead>
        <tbody>${shown.map(r => `<tr class="is-click" data-act="order-open" data-key="${escapeHtml(r.key)}">
          <td><span class="name mono" style="font-size:12px">${escapeHtml(r.id)}</span><span class="sub">${itemsLine(r)}</span></td>
          <td style="white-space:nowrap">${shortDate(r.placed_at)}</td>
          <td class="num">${fmt(r.pay.gross)}</td><td class="num">${pct(r.pay.rate)}</td><td class="num">− ${fmt(r.pay.commission)}</td>
          <td class="num"><strong>${fmt(r.pay.net)}</strong></td>
          <td>${payPill(r.pay.state)}${r.payout_released_at ? `<span class="sub">${shortDate(r.payout_released_at)}</span>` : ''}</td></tr>`).join('')}</tbody>
        <tfoot><tr><td colspan="2">Total (${shown.length})</td><td class="num">${fmt(shown.reduce((s, r) => s + r.pay.gross, 0))}</td><td></td><td class="num">− ${fmt(shown.reduce((s, r) => s + r.pay.commission, 0))}</td><td class="num">${fmt(shown.reduce((s, r) => s + r.pay.net, 0))}</td><td></td></tr></tfoot>
        </table></div>`
        : emptyBlock(DI.cash, 'No payouts yet', 'Each delivered sub-order becomes a line here with the commission already deducted.')}
    </section>`;
}

/* ============================================================
   SETTINGS — profile (with avatar), store, payout account, prefs
   ============================================================ */
/* These notification toggles are browser-local on purpose, not pending:
   nothing in this build sends email or push, so there is no server-side
   behaviour for them to be the truth of. Everything else on this page is in
   MySQL. If notifications are ever built, these move to a settings column
   and this comment goes with them. */
const PREFS_KEY = id => 'consult_seller_prefs_' + id;
const DEFAULT_PREFS = { newOrder: true, lowStock: true, weekly: false, insights: true };
function getPrefs(){ try { return Object.assign({}, DEFAULT_PREFS, JSON.parse(localStorage.getItem(PREFS_KEY(SD.me.id)) || '{}')); } catch(e){ return Object.assign({}, DEFAULT_PREFS); } }

function settingsView(){
  const u = SD.me, s = u.seller || {};
  const prefs = getPrefs();
  const cats = SDB.settings().categories;
  /* undefined = untouched (show what is stored), null = being removed,
     anything else = a freshly picked photo, shown from its preview. */
  const shownAvatar = SD.pendingAvatar === undefined
    ? getAvatar()
    : (SD.pendingAvatar === null ? null : SD.avatarPreview);
  const toggle = (k, title, sub) => `<div class="sd-toggle-row"><div><strong>${title}</strong><span>${sub}</span></div>
    <label class="dswitch"><input type="checkbox" data-pref="${k}" ${prefs[k] ? 'checked' : ''} aria-label="${escapeHtml(title)}"><i></i></label></div>`;

  return `<div class="sd-settings">
    <div class="sd-card sd-subnav" role="navigation" aria-label="Settings sections">
      <a href="#set-profile" data-act="set-jump">${SI.user} Profile</a>
      <a href="#set-store" data-act="set-jump">${SI.store} Store details</a>
      <a href="#set-payout" data-act="set-jump">${SI.bank} Payout account</a>
      <a href="#set-notify" data-act="set-jump">${DI.bell} Notifications</a>
    </div>
    <form class="sd-set-stack" id="settingsForm" autocomplete="off" novalidate>
      <section class="sd-card sd-set-sec" id="set-profile">
        <div class="sd-card-head"><div><h2>Edit profile</h2><div class="sd-card-sub">How you appear across the seller console</div></div></div>
        <div class="sd-avatar-edit">
          <span class="js-av-big">${avatarHtml('xl', shownAvatar)}</span>
          <div class="copy">
            <strong>Profile photo</strong>
            <p>A clear, square photo or logo works best. JPG or PNG, up to 2 MB — we'll resize it for you.</p>
            <div class="sd-avatar-btns">
              <label class="btn btn-dark sd-btn-ico" for="sdAvatarFile" style="cursor:pointer">${SI.upload} ${shownAvatar ? 'Change photo' : 'Upload photo'}</label>
              <button type="button" class="btn btn-ghost" data-act="avatar-remove" ${shownAvatar ? '' : 'disabled'}>Remove</button>
              <input type="file" id="sdAvatarFile" accept="image/png,image/jpeg,image/webp" hidden>
            </div>
            <span class="sd-avatar-pending js-av-note" ${SD.pendingAvatar === undefined ? 'hidden' : ''}>Preview — save to apply</span>
          </div>
          <div class="sd-avatar-preview">
            <span class="sd-me js-av-chip">${avatarHtml('sm', shownAvatar)}<span class="sd-me-copy" style="display:block"><strong>${escapeHtml(u.name || u.email)}</strong><span>${escapeHtml(storeName())}</span></span></span>
            Top bar preview
          </div>
        </div>
        <div class="sd-set-grid">
          <div class="field"><label for="setName">Full name</label><input id="setName" value="${escapeHtml(u.name || '')}" required></div>
          <div class="field"><label for="setPhone">Phone</label><input id="setPhone" value="${escapeHtml(u.phone || '')}" placeholder="07XXXXXXXX"></div>
          <div class="field span2"><label for="setEmail">Email</label><input id="setEmail" value="${escapeHtml(u.email || '')}" readonly title="Contact support to change your sign-in email"></div>
        </div>
      </section>

      <section class="sd-card sd-set-sec" id="set-store">
        <div class="sd-card-head"><div><h2>Store details</h2><div class="sd-card-sub">Shown to shoppers on your listings</div></div>${s.kyc_status === 'verified' ? `<span class="status-pill st-verified">verified</span>` : ''}</div>
        <div class="sd-set-grid">
          <div class="field span2"><label for="setBiz">Business name</label><input id="setBiz" value="${escapeHtml(s.business_name || '')}" required></div>
          <div class="field"><label for="setCity">City</label><input id="setCity" value="${escapeHtml(s.city || '')}"></div>
          <div class="field"><label for="setDistrict">District</label><input id="setDistrict" value="${escapeHtml(s.district || '')}"></div>
          <div class="field"><label for="setCat">Primary category</label><select id="setCat">${cats.map(c => `<option value="${escapeHtml(c)}"${s.primary_category === c ? ' selected' : ''}>${escapeHtml(cap(c))}</option>`).join('')}</select></div>
          <div class="field"><label for="setReg">Registration no.</label><input id="setReg" value="${escapeHtml(s.registration_no || '—')}" readonly title="Verified during KYC — contact support to change"></div>
        </div>
      </section>

      <section class="sd-card sd-set-sec" id="set-payout">
        <div class="sd-card-head"><div><h2>Payout account</h2><div class="sd-card-sub">Released escrow is paid here every Monday</div></div></div>
        <div class="sd-set-grid">
          <div class="field"><label for="setBank">Bank</label><input id="setBank" value="${escapeHtml(s.bank_name || '')}"></div>
          <div class="field"><label for="setBranch">Branch</label><input id="setBranch" value="${escapeHtml(s.branch || '')}"></div>
          <div class="field span2"><label for="setAcct">Account number</label><input id="setAcct" value="${escapeHtml(s.account_number || '')}" inputmode="numeric"></div>
        </div>
        <p class="dhint" style="margin:0">Changing bank details pauses payouts until Consult re-verifies the account.</p>
      </section>

      <section class="sd-card sd-set-sec" id="set-notify">
        <div class="sd-card-head"><div><h2>Notifications &amp; dashboard</h2><div class="sd-card-sub">Saved to this browser until email delivery is connected</div></div></div>
        ${toggle('newOrder', 'New order alerts', 'Email me the moment a shopper buys one of my listings')}
        ${toggle('lowStock', 'Low-stock warnings', `Tell me when a listing drops to ${LOW_STOCK} units or fewer`)}
        ${toggle('weekly', 'Weekly summary', 'A Monday recap of sales, payouts and top products')}
        ${toggle('insights', 'AI insights on Overview', 'Show the rotating tips card on the Overview page')}
      </section>

      <div class="sd-savebar">
        <span class="js-save-note">All changes saved</span>
        <div style="display:flex; gap:8px">
          <button type="button" class="btn btn-ghost" data-act="settings-reset">Discard</button>
          <button type="submit" class="btn btn-primary">Save changes</button>
        </div>
      </div>
    </form>
  </div>`;
}
function markSettingsDirty(){ const n = $('.js-save-note'); if (n) n.textContent = 'You have unsaved changes'; }
function markSettingsClean(){ const n = $('.js-save-note'); if (n) n.textContent = 'All changes saved'; }
function previewAvatar(src){
  $$('.js-av-big').forEach(el => el.innerHTML = avatarHtml('xl', src));
  $$('.js-av-chip .sd-av').forEach(el => el.outerHTML = avatarHtml('sm', src));
  const note = $('.js-av-note'); if (note) note.hidden = false;
  const rm = $('[data-act="avatar-remove"]'); if (rm) rm.disabled = !src;
  const up = $('label[for="sdAvatarFile"]'); if (up) up.innerHTML = `${SI.upload} ${src ? 'Change photo' : 'Upload photo'}`;
  markSettingsDirty();
}
/* Read, crop to a square and downscale to 256px BEFORE uploading, so a 6MB
   phone photo becomes a ~20KB JPEG. Two things come out of this:
     SD.pendingAvatar  a File, posted to api/seller/upload.php on save
     SD.avatarPreview  a data URL, only so the page can show it immediately
   The server re-checks the type and size regardless — this is a courtesy to
   the seller's connection, not a security measure. */
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

      SD.avatarPreview = c.toDataURL('image/jpeg', 0.86);
      previewAvatar(SD.avatarPreview);

      // toBlob is async; the preview is already on screen either way.
      if (c.toBlob){
        c.toBlob(blob => {
          SD.pendingAvatar = blob
            ? new File([blob], 'avatar.jpg', { type: 'image/jpeg' })
            : file;                       // fall back to the original
        }, 'image/jpeg', 0.86);
      } else {
        SD.pendingAvatar = file;
      }
    };
    img.onerror = () => toast("That file couldn't be read as an image");
    img.src = reader.result;
  };
  reader.readAsDataURL(file);
}
function saveSettings(){
  const val = id => ($('#' + id)?.value || '').trim();
  const name = val('setName'), phone = val('setPhone'), biz = val('setBiz');
  if (!name){ toast('Your name is required'); $('#setName').focus(); return; }
  if (!biz){ toast('Business name is required'); $('#setBiz').focus(); return; }
  if (phone && !PHONE_RE.test(phone.replace(/\s/g, ''))){ toast('Enter a Sri Lankan mobile number, e.g. 0771234567'); $('#setPhone').focus(); return; }

  const s = SD.me.seller || {};

  /* The notification toggles are genuinely browser-local: nothing in this
     build sends mail, so there is no server column for them to be the truth
     of. Kept in localStorage, which is what a per-browser preference is. */
  const prefs = {};
  $$('[data-pref]').forEach(el => { prefs[el.dataset.pref] = el.checked; });
  try { localStorage.setItem(PREFS_KEY(SD.me.id), JSON.stringify(prefs)); } catch(e){}

  /* The photo goes first and separately — it is a multipart upload, not part
     of the JSON profile body. SD.pendingAvatar is a File to upload, null to
     remove, or undefined for "not touched". */
  const photo = SD.pendingAvatar;
  SD.pendingAvatar = undefined;

  const photoStep = photo === undefined
    ? Promise.resolve()
    : (photo === null ? API.Auth.removeAvatar() : API.Auth.uploadAvatar(photo));

  photoStep
    .catch(err => {
      // A failed photo must not throw away the rest of the form.
      console.error('[seller] avatar update failed:', err);
      toast(err.message || 'The photo could not be saved — your other details were kept.');
    })
    .then(() => sdWrite(
      API.Seller.saveProfile({
        name: name,
        phone: phone.replace(/\s/g, ''),
        business_name: biz,
        city: val('setCity'),
        district: val('setDistrict'),
        primary_category: val('setCat') || s.primary_category,
        bank_name: val('setBank'),
        branch: val('setBranch'),
        account_number: val('setAcct')
      }),
      {
        onOk: res => {
          /* Adopt the refreshed user so the nav name and avatar update
             without a reload. */
          if (res && res.user){
            SD.me = res.user;
            Session.adopt(res.user);   // updates the nav and the snapshot
          }
          if (typeof markSettingsClean === 'function') markSettingsClean();
        }
      }
    ));
}

/* ============================================================
   AI ASSISTANT — seller-facing.
   PLACEHOLDER: a rule-based responder over the seller's own data.
   It deliberately does NOT call API.consult(): that endpoint is the
   shopper-facing product recommender and would answer "how are my
   sales?" with laptops. Swap sellerAssistantReply() for a POST to a
   seller-insights endpoint when one exists — the UI needs no change.
   ============================================================ */
const AI_STARTERS = ['How are my sales trending?', 'Which product should I restock?', 'Write a description for my new listing'];
const AI_KEY = id => 'consult_seller_ai_' + id;

function aiOpen(prompt){
  const panel = $('#sdAi');
  SD.ai.open = true;
  panel.hidden = false; $('#sdAiLaunch').hidden = true;
  closePops(); closeDrawer();
  if (!SD.ai.thread.length){
    SD.ai.thread.push({ who: 'ai', html: `Hi ${escapeHtml(firstName())} — I'm your store assistant. I read ${escapeHtml(storeName())}'s orders, stock and listings and answer questions about them. What would you like to know?` });
    aiSave();
  }
  aiRenderThread(); aiChips(SD.ai.thread.length > 1 ? null : AI_STARTERS);
  if (prompt) aiSend(prompt);
  else setTimeout(() => $('#sdAiText')?.focus(), 60);
}
function aiClose(){ SD.ai.open = false; $('#sdAi').hidden = true; $('#sdAiLaunch').hidden = false; $('#sdAiLaunch').focus(); }
function aiSave(){ try { sessionStorage.setItem(AI_KEY(SD.me.id), JSON.stringify(SD.ai.thread.slice(-40))); } catch(e){} }
function aiLoad(){ try { SD.ai.thread = JSON.parse(sessionStorage.getItem(AI_KEY(SD.me.id)) || '[]'); } catch(e){ SD.ai.thread = []; } }
function aiMsgHtml(m){
  return m.who === 'me'
    ? `<div class="sd-msg me">${avatarHtml('')}<div class="sd-bubble">${m.html}</div></div>`
    : `<div class="sd-msg"><span class="sd-msg-av">${SI.sparkle}</span><div class="sd-bubble">${m.html}</div></div>`;
}
function aiRenderThread(){
  const t = $('#sdAiThread');
  t.innerHTML = SD.ai.thread.map(aiMsgHtml).join('');
  t.scrollTop = t.scrollHeight;
}
function aiChips(list){
  const box = $('#sdAiChips');
  box.innerHTML = (list || []).map(c => `<button type="button" class="sd-chip" data-act="ai-chip" data-q="${escapeHtml(c)}">${escapeHtml(c)}</button>`).join('');
}
function aiSend(text){
  text = String(text || '').trim();
  if (!text || SD.ai.busy) return;
  SD.ai.busy = true;
  const t = $('#sdAiThread');
  SD.ai.thread.push({ who: 'me', html: escapeHtml(text) });
  aiRenderThread(); aiChips(null);
  t.insertAdjacentHTML('beforeend', `<div class="sd-msg" id="sdTyping"><span class="sd-msg-av">${SI.sparkle}</span><div class="sd-bubble"><span class="sd-typing"><i></i><i></i><i></i></span></div></div>`);
  t.scrollTop = t.scrollHeight;
  $('.sd-ai-send').disabled = true;
  setTimeout(() => {
    let reply;
    try { reply = sellerAssistantReply(text); }
    catch(err){ console.error('Assistant failed:', err); reply = { html: "Sorry — I couldn't read your store data just then. Try again in a moment.", chips: AI_STARTERS }; }
    $('#sdTyping')?.remove();
    SD.ai.thread.push({ who: 'ai', html: reply.html });
    aiSave(); aiRenderThread(); aiChips(reply.chips);
    SD.ai.busy = false; $('.sd-ai-send').disabled = false;
    if (SD.ai.open) $('#sdAiText')?.focus();
  }, 450 + Math.random() * 450);
}

function sellerAssistantReply(text){
  const q = text.toLowerCase();
  const orders = myOrders(), products = myProducts();
  const live = orders.filter(isLive);
  const go = (view, label) => `<button class="sd-link" data-act="view" data-view="${view}">${label} ${SI.arrow}</button>`;
  const has = re => re.test(q);

  // sales trend
  if (has(/\b(sales?|revenue|trend|trending|earn|doing|perform|money made|income)\b/) && !has(/\b(sale price|put on sale|discount)\b/)){
    const rev14 = daySeries(live, 14, o => o.placed_at, o => o.total || 0);
    const r7 = rev14.slice(-7).reduce((s, b) => s + b.total, 0), p7 = rev14.slice(0, 7).reduce((s, b) => s + b.total, 0);
    const o7 = live.filter(o => withinDays(o.placed_at, 7)).length;
    const best = rev14.slice(-7).reduce((a, b) => b.total > a.total ? b : a, { total: 0 });
    if (!live.length) return { html: `You haven't had a sale yet, so there's no trend to read. Two things move the needle early: <ul><li>fill in <b>structured specs</b> on every listing — the shopping AI matches on them</li><li>keep stock above ${LOW_STOCK} so listings stay recommendable</li></ul>${go('products', 'Review listings')}`, chips: ['Which listings need better specs?', 'Write a description for my new listing'] };
    const t = trendInfo(rev14.map(b => b.total));
    return { html: `In the last 7 days you made <b>${fmt(Math.round(r7))}</b> from ${plural(o7, 'order')}${p7 ? `, <b>${t.cls === 'up' ? 'up' : t.cls === 'down' ? 'down' : 'flat'} ${t.text.replace(/[▲▼] ?/, '')}</b> on the week before (${fmt(Math.round(p7))})` : ' — the week before had no sales, so this is all new'}.${best.total ? ` Your best day was <b>${escapeHtml(best.label)}</b> at ${fmt(Math.round(best.total))}.` : ''}${t.cls === 'down' ? ' A short scheduled sale on a slow mover is usually the fastest fix.' : ''}<br>${go('overview', 'See the chart')}`,
      chips: ['What are my top products?', 'Which listings should I put on sale?', 'Any orders waiting on me?'] };
  }

  // restock
  if (has(/\b(restock|stock|inventory|run(ning)? (out|low)|reorder|out of)\b/)){
    const sold = {};
    live.filter(o => withinDays(o.placed_at, 30)).forEach(o => (o.items || []).forEach(l => { const id = l.product && l.product.id; if (id) sold[id] = (sold[id] || 0) + (l.qty || 1); }));
    const need = products.filter(p => p.status === 'active' && (p.stock <= LOW_STOCK || (sold[p.id] && p.stock < sold[p.id])))
      .map(p => ({ p, sold: sold[p.id] || 0, suggest: Math.max(LOW_STOCK * 2, Math.ceil((sold[p.id] || 0) * 1.5)) - p.stock }))
      .sort((a, b) => a.p.stock - b.p.stock || b.sold - a.sold);
    if (!need.length) return { html: `Nothing needs restocking — every live listing has more than ${LOW_STOCK} units and more than it sold in the last 30 days. 👍`, chips: ['What are my top products?', 'How are my sales trending?'] };
    return { html: `Restock these first:<ul>${need.slice(0, 5).map(n => `<li><b>${escapeHtml(n.p.title)}</b> — ${n.p.stock ? `${n.p.stock} left` : 'out of stock'}${n.sold ? `, sold ${n.sold} in 30 days` : ''}. Add about <span class="mono">${Math.max(1, n.suggest)}</span>.</li>`).join('')}</ul>Out-of-stock listings drop out of the shopping AI's recommendations, so the top one is costing you visibility right now.<br>${go('inventory', 'Open inventory')}`,
      chips: ['How are my sales trending?', 'Which listings should I put on sale?'] };
  }

  // listing copy
  if (has(/\b(descri|write|copy|listing text|title|blurb)\b/)){
    const named = products.find(p => q.includes(p.title.toLowerCase().split(' ').slice(0, 2).join(' ')));
    const p = named || products.slice().sort((a, b) => new Date(b.created_at || 0) - new Date(a.created_at || 0))[0];
    if (!p) return { html: `Add the product first (title, category and a few specs) and I'll draft a description from the specs.<br><button class="sd-link" data-act="product-new">Add a product ${SI.arrow}</button>`, chips: AI_STARTERS.slice(0, 2) };
    const specs = Object.entries(p.specs || {});
    const lead = p.subtitle ? `${p.title} — ${p.subtitle}.` : `${p.title}.`;
    const body = specs.length
      ? `Key specs: ${specs.slice(0, 4).map(([k, v]) => `${k} ${v}`).join(', ')}. ${specs.length >= 3 ? 'A balanced pick if you want the essentials done properly without paying flagship prices.' : ''}`
      : 'Add at least three specs so shoppers (and the AI consultant) can compare it properly.';
    return { html: `Here's a draft for <b>${escapeHtml(p.title)}</b>${named ? '' : ' (your newest listing — name another one to switch)'}:<div class="sd-draft">${escapeHtml(lead + ' ' + body)} Sold and shipped by ${escapeHtml(storeName())} — paid safely through Consult escrow.</div>Lead with who it's for, then the specs that justify the price. Avoid "best quality" — say what's actually better.<br><button class="sd-link" data-act="product-edit" data-id="${escapeHtml(p.id)}">Open in the editor ${SI.arrow}</button>`,
      chips: ['Which listings need better specs?', 'Which product should I restock?'] };
  }

  // specs quality
  if (has(/\bspecs?\b/)){
    const thin = products.filter(p => p.status !== 'rejected' && Object.keys(p.specs || {}).length < 3);
    return thin.length
      ? { html: `These listings have fewer than 3 specs:<ul>${thin.slice(0, 6).map(p => `<li><b>${escapeHtml(p.title)}</b> — ${Object.keys(p.specs || {}).length} spec${Object.keys(p.specs || {}).length === 1 ? '' : 's'}</li>`).join('')}</ul>Add RAM/storage/display for laptops, battery/camera for phones, capacity/energy rating for appliances.`, chips: ['Write a description for my new listing'] }
      : { html: `Every listing has at least 3 specs — nice. That's exactly what the shopping AI needs to recommend you.`, chips: ['How are my sales trending?'] };
  }

  // orders
  if (has(/\b(orders?|pending|ship|pack|deliver|fulfil|waiting|attention|customer)\b/)){
    const open = orders.filter(isOpen), stale = orders.filter(isStale);
    if (!open.length) return { html: `No open orders — everything is delivered or closed.${orders.length ? ` You've fulfilled ${plural(orders.filter(o => o.status === 'delivered').length, 'order')} so far.` : ''}`, chips: ['How are my sales trending?'] };
    const by = s => open.filter(o => o.status === s).length;
    return { html: `You have <b>${plural(open.length, 'open order')}</b>: ${by('pending')} to pack, ${by('packed')} to ship, ${by('shipped')} out for delivery.${stale.length ? ` <b>${stale.length}</b> ${stale.length === 1 ? 'has' : 'have'} been waiting over ${STALE_HOURS}h — oldest is ${escapeHtml(stale[0].id)}.` : ''} Payouts release only after delivery, so moving these along is money in the bank.<br><button class="sd-link" data-act="goto-orders" data-tab="${stale.length ? '__stale' : 'pending'}">Open orders ${SI.arrow}</button>`,
      chips: ['When is my next payout?', 'Which product should I restock?'] };
  }

  // payouts
  if (has(/\b(payouts?|paid|escrow|commission|balance|bank|withdraw)\b/)){
    const pays = orders.map(SDB.payoutFor);
    const s = st => pays.filter(p => p.state === st).reduce((a, p) => a + p.net, 0);
    return { html: `Released so far: <b>${fmt(Math.round(s('released')))}</b>. Waiting in escrow: <b>${fmt(Math.round(s('escrow')))}</b> — that releases as each order is delivered.${s('held') ? ` ${fmt(Math.round(s('held')))} is held in disputes.` : ''} Next payout run: <b>${nextPayoutDate().toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'short' })}</b>, at your ${pct(SDB.commissionRate())} commission rate.<br>${go('payouts', 'Open payouts')}`,
      chips: ['Any orders waiting on me?', 'How are my sales trending?'] };
  }

  // pricing / sales
  if (has(/\b(price|pricing|discount|promot|put on sale|sale|cheaper)\b/)){
    const sold = new Set(); live.filter(o => withinDays(o.placed_at, 30)).forEach(o => (o.items || []).forEach(l => sold.add(l.product && l.product.id)));
    const idle = products.filter(p => p.status === 'active' && p.stock > LOW_STOCK && !sold.has(p.id) && !p.sale);
    const running = products.filter(p => SDB.effectivePrice(p).onSale);
    return { html: `${idle.length ? `Good sale candidates (in stock, no sales in 30 days, no sale scheduled):<ul>${idle.slice(0, 4).map(p => `<li><b>${escapeHtml(p.title)}</b> — ${p.stock} in stock at ${fmt(p.price)}</li>`).join('')}</ul>A 5–10% cut for 7 days is enough to test demand without training shoppers to wait.` : 'Every in-stock listing has sold recently or already has a sale scheduled — no obvious discount candidates.'}${running.length ? ` Currently on sale: ${running.map(p => escapeHtml(p.title)).join(', ')}.` : ''}<br>${go('inventory', 'Schedule a sale')}`,
      chips: ['Which product should I restock?', 'How are my sales trending?'] };
  }

  // top products
  if (has(/\b(top|best|popular|selling|winner)\b/)){
    const agg = {};
    live.filter(o => withinDays(o.placed_at, 30)).forEach(o => (o.items || []).forEach(l => { const p = l.product || {}; if (!p.id) return; const a = agg[p.id] || (agg[p.id] = { t: p.title, u: 0, r: 0 }); a.u += l.qty || 1; a.r += (Number(p.price) || 0) * (l.qty || 1); }));
    const top = Object.values(agg).sort((a, b) => b.r - a.r).slice(0, 3);
    return top.length
      ? { html: `Your top sellers over 30 days:<ul>${top.map((a, i) => `<li>${i + 1}. <b>${escapeHtml(a.t)}</b> — ${plural(a.u, 'unit')}, ${fmt(Math.round(a.r))}</li>`).join('')}</ul>Keep these well stocked — they're what the AI consultant is already recommending.`, chips: ['Which product should I restock?', 'Which listings should I put on sale?'] }
      : { html: `No sales in the last 30 days yet, so there's no ranking. Once orders arrive I'll rank listings by revenue.`, chips: ['Which listings need better specs?'] };
  }

  if (has(/^(hi|hello|hey|yo|ayubowan)\b|help|what can you/)){
    return { html: `I can help with:<ul><li>sales trends and your best days</li><li>what to restock, and how much</li><li>draft listing descriptions from your specs</li><li>open orders, escrow and payouts</li><li>which listings could use a sale</li></ul>`, chips: AI_STARTERS };
  }

  return { html: `I'm the <b>store</b> assistant, so I answer questions about ${escapeHtml(storeName())}'s sales, stock, listings, orders and payouts — I can't search the catalogue for shoppers. Try one of these:`, chips: AI_STARTERS };
}

/* ============================================================
   KYC GATE — shown instead of the dashboard until verified
   ============================================================ */
function renderGate(){
  const s = SD.me.seller || {};
  const rejected = s.kyc_status === 'rejected';
  $('#dash').classList.add('is-gated');
  $('#sdSide').innerHTML = '';
  $('#sdTop').innerHTML = `<div class="sd-top-title"><div class="sd-crumb">Seller Hub / <b>Verification</b></div><h1>${escapeHtml(storeName())}</h1></div>
    <div class="sd-top-actions" style="margin-left:auto"><div class="sd-pop-wrap">
      <button class="sd-me" data-act="pop" data-pop="me" aria-haspopup="true" aria-expanded="false" aria-label="Account menu">${avatarHtml('sm')}<span class="sd-me-copy"><strong>${escapeHtml(SD.me.name || SD.me.email)}</strong><span>${rejected ? 'Not approved' : 'Verification pending'}</span></span>${SI.chev}</button>
      <div class="sd-pop" id="pop-me" style="width:220px"><a class="sd-pop-item" href="contact.html">${DI.help} Contact support</a><button class="sd-pop-item danger" data-act="logout">${SI.logout} Log out</button></div>
    </div></div>`;
  $('#sellerBody').innerHTML = `<div class="sd-card gate">
    <span class="dempty-ico" style="${rejected ? 'background:rgba(139,46,60,.09)' : ''}">${rejected ? DI.flag : DI.shield}</span>
    <h2 style="font-family:'Outfit',sans-serif; font-size:21px; margin-bottom:8px">${rejected ? 'Your application wasn\'t approved' : 'Your store is under review'}</h2>
    <p class="muted" style="font-size:13.5px; line-height:1.65; max-width:440px; margin:0 auto">${rejected
      ? 'An admin reviewed your details and couldn\'t verify them. The reason is below — fix what it points to and apply again.'
      : 'We check your NIC and business registration before your storefront goes live — usually within 1–2 business days. You\'ll get the full dashboard the moment it\'s approved.'}</p>
    ${rejected ? `<div class="reason-box" style="text-align:left; max-width:440px; margin:20px auto 0"><span class="k">Reason</span>${escapeHtml(s.kyc_rejection_reason || 'No reason recorded')}</div>` : `
    <div class="stepper"><span class="step done"><i class="step-dot"></i><span class="step-label">submitted</span></span><i class="step-line done"></i><span class="step done current"><i class="step-dot"></i><span class="step-label">in review</span></span><i class="step-line"></i><span class="step"><i class="step-dot"></i><span class="step-label">approved</span></span></div>`}
    <div class="dempty-actions" style="margin-top:24px">
      ${rejected ? `<a class="btn btn-primary btn-sm" href="seller-register.html">Apply again</a>` : `<button class="btn btn-primary btn-sm" onclick="location.reload()">Check status</button>`}
      <a class="btn btn-ghost btn-sm" href="contact.html">Contact support</a>
    </div>
    <p class="muted" style="font-size:11.5px; margin-top:20px">Submitted ${shortDate(SD.me.created_at)} · ${escapeHtml(s.business_name || '')}</p>
  </div>`;
}

/* ============================================================
   ACTIONS — one delegated listener
   ============================================================ */
/**
 * Move one shipment to the next stage. Returns a PROMISE, because the
 * decision is the server's: api/seller/order_status.php re-checks the move
 * against the role map and refuses anything this seller may not do —
 * 'delivered' above all, which only the customer can set, because that is
 * what releases the money.
 *
 * Resolves null when there is nothing to advance, so a caller can tell
 * "not actionable" from "the server said no".
 */
function advanceByKey(key){
  const o = SDB.order(key);
  if (!o) return Promise.resolve(null);

  const next = nextOf(o.status);
  if (!next) return Promise.resolve(null);

  return sdWrite(
    API.Seller.setOrderStatus(o.key, next),
    { onOk: () => { SD.selected.delete(key); SD.justChanged.add(key); } }
  ).then(() => ({ id: o.id, next: next, key: key }));
}
function setView(v, extra = {}){
  SD.view = VIEW_IDS.includes(v) ? v : 'overview';
  Object.assign(SD, extra);
  if (location.hash !== '#' + SD.view) history.replaceState(null, '', '#' + SD.view);
  closePops(); closeDrawer();
  render();
  window.scrollTo({ top: 0, behavior: 'smooth' });
}
function openDrawer(){ $('#dash').classList.add('drawer-open'); }
function closeDrawer(){ $('#dash')?.classList.remove('drawer-open'); }

function wire(){
  // body, not #dash: the modal shell and the AI panel live outside the
  // container and use the same data-act delegation
  const root = document.body;

  root.addEventListener('click', e => {
    // a click outside any popover closes them
    if (!e.target.closest('.sd-pop-wrap')) closePops();

    const el = e.target.closest('[data-act]');
    if (!el || !root.contains(el)) return;
    const act = el.dataset.act, id = el.dataset.id, key = el.dataset.key;
    // anything that navigates away closes the order modal it was clicked from
    const fromModal = !!el.closest('#dashModal');

    switch (act){
      case 'view':        if (fromModal) closeDashModal(); setView(el.dataset.view); return;
      case 'goto-orders': if (fromModal) closeDashModal(); setView('orders', { tab: el.dataset.tab || 'all', mode: 'list', opage: 1 }); return;
      case 'toggle-side': SD.collapsed = !SD.collapsed; try { localStorage.setItem('sd_sidebar_collapsed', SD.collapsed ? '1' : '0'); } catch(e){} render(); return;
      case 'open-drawer': openDrawer(); return;
      case 'close-drawer': closeDrawer(); return;
      case 'modal-close': closeDashModal(); SD.openKey = null; return;
      case 'logout':      closePops(); openLogoutModal(); return;
      case 'pop': {
        const pop = $('#pop-' + el.dataset.pop);
        const open = !pop.classList.contains('open');
        closePops(pop);
        pop.classList.toggle('open', open);
        el.setAttribute('aria-expanded', String(open));
        return;
      }
      case 'search-all': {
        const q = $('#sdGlobal')?.value || '';
        setView('orders', { query: q, tab: 'all', range: 'all', mode: 'list', opage: 1 }); return;
      }

      /* overview */
      case 'chart-days': SD.chartDays = Number(el.dataset.days) || 30; render(); return;
      case 'tip':        SD.tipIdx = Number(el.dataset.i) || 0; render(); return;

      /* orders */
      case 'order-open':  openOrder(key); return;
      case 'advance': {
        const was = SDB.order(key);
        if (!was || !nextOf(was.status)){ toast("That order can't be advanced"); return; }
        advanceByKey(key).then(res => {
          // sdWrite has already reloaded and repainted; this only refreshes
          // the open modal's timeline in place.
          if (res && modalOpen() && SD.openKey === res.key) openOrder(res.key);
        });
        return;
      }
      case 'select':      el.checked ? SD.selected.add(key) : SD.selected.delete(key); render(); return;
      case 'select-all': {
        const actionable = myOrders().filter(o => nextOf(o.status));
        el.checked ? actionable.forEach(o => SD.selected.add(o.key)) : SD.selected.clear();
        render(); return;
      }
      case 'bulk-advance': {
        /* One request per shipment, in SEQUENCE rather than in parallel:
           each writes to sub_orders and order_status_log, and a burst of
           concurrent transactions on the same rows is how you get lock
           timeouts. The count reported is what actually succeeded. */
        const keys = [...SD.selected].filter(k => {
          const o = SDB.order(k);
          return o && nextOf(o.status);
        });
        if (!keys.length){ toast('Nothing to advance'); return; }

        SD.selected.clear();
        let done = 0;
        keys.reduce(
          (chain, k) => chain.then(() => {
            const o = SDB.order(k);
            if (!o || !nextOf(o.status)) return null;
            return API.Seller.setOrderStatus(k, nextOf(o.status))
              .then(() => { done++; SD.justChanged.add(k); })
              .catch(err => console.warn('[seller] could not advance', k, '-', err.message));
          }),
          Promise.resolve()
        ).then(() => {
          toast(done ? `${plural(done, 'order')} advanced` : 'Nothing could be advanced');
          return SDB.load();
        }).then(render).catch(err => {
          console.error('[seller] bulk advance reload failed:', err);
          toast('Reload the page to see the current status.');
        });
        return;
      }
      case 'bulk-clear':    SD.selected.clear(); render(); return;
      case 'tab':           SD.tab = el.dataset.tab; SD.opage = 1; render(); return;
      case 'mode':          SD.mode = el.dataset.mode; try { localStorage.setItem('sd_orders_mode', SD.mode); } catch(e){} render(); return;
      case 'reset-filters': SD.query = ''; SD.tab = 'all'; SD.range = 'all'; SD.opage = 1; render(); return;
      case 'osort': {
        const k = el.dataset.k;
        SD.osort = SD.osort.k === k ? { k, dir: -SD.osort.dir } : { k, dir: k === 'customer' || k === 'status' ? 1 : -1 };
        render(); return;
      }
      case 'opage':         SD.opage = Number(el.dataset.p) || 1; render(); $('#sellerBody').scrollIntoView({ block: 'start' }); return;

      /* products */
      case 'product-new':  closeDrawer(); productForm(null); return;
      case 'product-edit': { const p = myProducts().find(p => p.id === id); if (p) productForm(p); else toast("That listing couldn't be found"); return; }
      case 'pmode':        SD.pmode = el.dataset.mode; try { localStorage.setItem('sd_products_mode', SD.pmode); } catch(e){} render(); return;
      case 'pfilter':      SD.pfilter = el.dataset.f; if (el.dataset.clear) SD.pquery = ''; render(); return;
      case 'product-toggle': sdWrite(API.Seller.toggleProduct(id)); return;
      case 'product-delete': {
        const p = myProducts().find(x => x.id === id); if (!p) return;
        modalClass('');
        confirmDash({ title: 'Delete this listing?', body: `<strong>${escapeHtml(p.title)}</strong> will be removed from the storefront. Orders already placed for it are unaffected.`, confirmLabel: 'Delete', danger: true },
          () => sdWrite(API.Seller.deleteProduct(id)));
        return;
      }
      case 'spec-add':    $('#specRows').insertAdjacentHTML('beforeend', `<div class="spec-row"><input placeholder="e.g. RAM" data-spec="k" aria-label="Spec name"><input placeholder="e.g. 16GB DDR5" data-spec="v" aria-label="Spec value"><button type="button" data-act="spec-remove" title="Remove">×</button></div>`); return;
      case 'spec-remove': el.closest('.spec-row').remove(); return;

      /* inventory */
      case 'ifilter':      SD.ifilter = el.dataset.f; SD.invSelected.clear(); render(); return;
      case 'stock-delta':
        /* Sent as a DELTA, not a computed total: the database applies
           `stock = stock + ?`, so two quick clicks both count. */
        sdWrite(API.Seller.adjustStock(id, parseInt(el.dataset.d, 10)));
        return;
      case 'inv-select':     el.checked ? SD.invSelected.add(id) : SD.invSelected.delete(id); render(); return;
      case 'inv-select-all': {
        const shown = myProducts().filter(p => p.status !== 'rejected').filter(p => SD.ifilter === 'all' || stockTag(p) === SD.ifilter);
        el.checked ? shown.forEach(p => SD.invSelected.add(p.id)) : SD.invSelected.clear(); render(); return;
      }
      case 'inv-bulk-clear': SD.invSelected.clear(); render(); return;
      case 'inv-bulk-apply': {
        const qty = parseInt($('#invBulkQty').value, 10);
        if (isNaN(qty) || qty < 0){ toast('Enter a quantity of 0 or more'); return; }
        const ids = [...SD.invSelected];
        SD.invSelected.clear();
        // One request for the whole selection — the endpoint takes an id list.
        sdWrite(API.Seller.bulkStock(ids, qty));
        return;
      }
      case 'sale-edit':   { const p = myProducts().find(x => x.id === id); if (p) saleForm(p); return; }
      case 'sale-remove': { const p = SDB.product(id); if (p) removeSale(p); return; }

      /* payouts */
      case 'ptab': SD.ptab = el.dataset.tab; render(); return;
      case 'export-payouts': {
        const rows = [['sub_order_id', 'order_id', 'placed_at', 'gross', 'commission_rate', 'commission', 'net', 'state', 'payout_released_at']];
        myOrders().forEach(o => { const p = SDB.payoutFor(o); rows.push([o.id, o.order_id, o.placed_at, p.gross, p.rate, p.commission, p.net, p.state, o.payout_released_at || '']); });
        downloadCsv('payouts.csv', rows); return;
      }

      /* settings */
      case 'set-jump': {
        e.preventDefault();
        $(el.getAttribute('href'))?.scrollIntoView({ behavior: 'smooth', block: 'start' }); return;
      }
      case 'avatar-remove':  SD.pendingAvatar = null; SD.avatarPreview = null; previewAvatar(null); return;
      case 'settings-reset': SD.pendingAvatar = undefined; render(); toast('Changes discarded'); return;

      /* assistant */
      case 'ai-open':  aiOpen(el.dataset.prompt); return;
      case 'ai-close': aiClose(); return;
      case 'ai-chip':  aiSend(el.dataset.q); return;
      case 'ai-clear': SD.ai.thread = []; aiSave(); aiOpen(); return;
    }
  });

  /* search boxes — debounced, caret restored after the re-render */
  let t;
  root.addEventListener('input', e => {
    const id = e.target.id;
    if (id === 'sdGlobal'){ clearTimeout(t); t = setTimeout(() => globalSearch(e.target.value), 150); return; }
    if (e.target.closest('#settingsForm')){ markSettingsDirty(); return; }
    if (id !== 'sdSearch' && id !== 'sdPSearch') return;
    const pos = e.target.selectionStart;
    if (id === 'sdSearch'){ SD.query = e.target.value; SD.opage = 1; } else SD.pquery = e.target.value;
    clearTimeout(t);
    t = setTimeout(() => {
      render();
      const again = $('#' + id);
      if (again){ again.focus(); try { again.setSelectionRange(pos, pos); } catch(e){} }
    }, 220);
  });
  root.addEventListener('keydown', e => {
    if (e.target.id === 'sdGlobal' && e.key === 'Enter'){ e.preventDefault(); setView('orders', { query: e.target.value, tab: 'all', range: 'all', mode: 'list', opage: 1 }); }
    if (e.key === 'Escape'){ closePops(); closeDrawer(); if (SD.ai.open && !modalOpen()) aiClose(); }
    // "/" jumps to search, unless the seller is already typing somewhere
    if (e.key === '/' && !/^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName) && !e.target.isContentEditable){ const g = $('#sdGlobal'); if (g){ e.preventDefault(); g.focus(); } }
  });
  root.addEventListener('change', e => {
    const el = e.target;
    if (el.id === 'sdAvatarFile'){ readAvatarFile(el.files && el.files[0]); el.value = ''; return; }
    if (el.id === 'pfImageFile'){ uploadProductImages(el.files); el.value = ''; return; }
    if (el.dataset.act === 'range'){ SD.range = el.value; SD.opage = 1; render(); }
    if (el.dataset.act === 'stock-set'){
      sdWrite(API.Seller.setStock(el.dataset.id, Number(el.value)));
    }
  });
  root.addEventListener('submit', e => {
    if (e.target.id === 'settingsForm'){ e.preventDefault(); saveSettings(); }
    if (e.target.id === 'sdAiForm'){ e.preventDefault(); const i = $('#sdAiText'); aiSend(i.value); i.value = ''; }
  });

  /* chart tooltip */
  root.addEventListener('mouseover', e => {
    const h = e.target.closest && e.target.closest('.sd-chart .hit');
    const tip = $('#sdTip');
    if (!tip) return;
    if (!h){ if (!e.target.closest || !e.target.closest('.sd-chart')) tip.classList.remove('show'); return; }
    const n = Number(h.dataset.n);
    tip.innerHTML = `${escapeHtml(h.dataset.label)} · <b>${fmt(Number(h.dataset.v))}</b> · ${plural(n, 'order')}`;
    tip.style.left = h.dataset.x + '%'; tip.style.top = h.dataset.y + '%';
    tip.classList.add('show');
  });

  wireBoardDragDrop(root);
  window.addEventListener('hashchange', () => { const v = location.hash.slice(1); if (VIEW_IDS.includes(v) && v !== SD.view) setView(v); });
}

/* ---------- board drag & drop ----------
   Delegated so it survives re-renders. Only the next column accepts
   the drop; the others still receive dragover (so drop fires) and
   answer with a shake instead of a silent no-op. */
function wireBoardDragDrop(root){
  root.addEventListener('dragstart', e => {
    const card = e.target.closest && e.target.closest('.kcard[draggable="true"]');
    if (!card) return;
    SD.drag = { key: card.dataset.key, status: card.dataset.status, next: nextOf(card.dataset.status) };
    e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setData('text/plain', card.dataset.key); } catch(err){}
    card.classList.add('is-dragging');
    const board = $('#kanban'); board.classList.add('is-dragging');
    board.querySelectorAll('.kcol').forEach(col => col.classList.toggle('can-drop', col.dataset.status === SD.drag.next));
    board.querySelectorAll('.kcol').forEach(col => col.classList.toggle('no-drop', col.dataset.status !== SD.drag.next));
  });
  root.addEventListener('dragover', e => {
    const col = e.target.closest && e.target.closest('.kcol');
    if (!col || !SD.drag) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = col.dataset.status === SD.drag.next ? 'move' : 'none';
    col.classList.add('is-over');
  });
  root.addEventListener('dragleave', e => {
    const col = e.target.closest && e.target.closest('.kcol');
    if (col && !col.contains(e.relatedTarget)) col.classList.remove('is-over');
  });
  root.addEventListener('drop', e => {
    const col = e.target.closest && e.target.closest('.kcol');
    if (!col || !SD.drag) return;
    e.preventDefault();
    const drag = SD.drag; SD.drag = null;
    if (col.dataset.status !== drag.next){
      col.classList.add('shake'); setTimeout(() => col.classList.remove('shake'), 350);
      const idx = FLOW.indexOf(col.dataset.status), cur = FLOW.indexOf(drag.status);
      toast(idx < cur ? "Orders can't move backwards" : idx === cur ? 'Already in this stage' : 'Orders move one stage at a time');
      cleanupDrag(); return;
    }
    /* advanceByKey posts to the server and repaints when it answers, so the
       card snaps back to its real column if the move was refused — rather
       than sitting in the column it was dropped into as though it worked. */
    cleanupDrag();
    advanceByKey(drag.key).then(res => {
      if (!res) toast("That order can't be advanced");
    });
    return;
  });
  root.addEventListener('dragend', () => { SD.drag = null; cleanupDrag(); });
  function cleanupDrag(){
    const board = $('#kanban'); if (!board) return;
    board.classList.remove('is-dragging');
    board.querySelectorAll('.is-over, .can-drop, .no-drop, .is-dragging').forEach(x => x.classList.remove('is-over', 'can-drop', 'no-drop', 'is-dragging'));
  }
}

/* ---------- boot ----------
   requireRole() gates on the session SNAPSHOT so the page does not flash;
   the real gate is server-side (require_role('seller') on every endpoint),
   and Session.ready is the authoritative answer. The workspace then loads
   in one request before the first real render.
   ---------------------------------------------------------------- */
document.addEventListener('DOMContentLoaded', () => {
  if (!requireRole('seller', 'seller')) return;

  /* The snapshot, so the skeleton below has a name and an avatar to draw.
     requireRole only returns true when it holds a signed-in seller, so this
     is never null here. Session.ready replaces it with the server's answer
     a moment later, and SDB.load() replaces the seller block with the
     authoritative one. */
  SD.me = Session.user;

  try {
    SD.collapsed = localStorage.getItem('sd_sidebar_collapsed') === '1';
    const m = localStorage.getItem('sd_orders_mode'); if (m === 'board' || m === 'list') SD.mode = m;
    const pm = localStorage.getItem('sd_products_mode'); if (pm === 'grid' || pm === 'table') SD.pmode = pm;
  } catch(e){}
  const h = location.hash.slice(1);
  if (VIEW_IDS.includes(h)) SD.view = h;

  wire();
  render();                                     // skeleton, while the data is in flight

  Session.ready
    .then(user => {
      if (!user) return null;                   // requireRole / api.js is redirecting
      SD.me = user;
      return SDB.load();
    })
    .then(data => {
      if (!data) return;
      SD.loading = false;

      /* The verification gate, decided by what the SERVER says about this
         seller — not by a snapshot that could be stale after an admin
         approved or rejected them a moment ago. */
      if (!SD.data.seller || SD.data.seller.kyc_status !== 'verified'){
        renderGate();
        return;
      }

      aiLoad();
      const launch = $('#sdAiLaunch');
      if (launch) launch.hidden = false;
      render();
    })
    .catch(err => {
      SD.loading = false;
      console.error('[seller] dashboard failed to load:', err);

      if (err.code === 'AUTH') return;          // being redirected to login

      if (err.code === 'FORBIDDEN'){
        // Signed in, but not as a seller: the server is the authority.
        rejectRole('seller');
        return;
      }

      renderErrorState($('#sellerBody'), 'reload');
    });
});
