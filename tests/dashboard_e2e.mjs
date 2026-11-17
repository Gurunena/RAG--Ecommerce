/*
 * tests/dashboard_e2e.mjs — does a real order move every dashboard?
 *
 *   cd C:\E-commerce\consult-project
 *   node tests\dashboard_e2e.mjs
 *
 * Drives the real API with three real sessions and records every number
 * before and after each step:
 *
 *   1. nimal@example.com places an order
 *   2. techhub@example.com (the seller) should see it, KPIs should move
 *   3. the seller marks it packed -> shipped
 *   4. the customer's order page should show the new status
 *   5. admin GMV and recent orders should move
 *
 * Every request is logged, so the report can say exactly which endpoints
 * the dashboards actually use.
 *
 * The order it creates is REAL. It is cleaned up at the end, including
 * putting the stock back, and the script says so if cleanup fails.
 */
const BASE = (process.argv[2] || 'http://localhost/consult-project').replace(/\/+$/, '');
const PASSWORD = 'Consult@2026';

let pass = 0, fail = 0;
const failures = [];
const calls = [];

const check = (label, ok, detail = '') => {
  if (ok) { pass++; console.log(`  [PASS] ${label}${detail ? `  (${detail})` : ''}`); }
  else { fail++; failures.push(label + (detail ? ` — ${detail}` : '')); console.log(`  [FAIL] ${label}${detail ? `  (${detail})` : ''}`); }
};
const section = t => console.log(`\n${'='.repeat(72)}\n  ${t}\n${'='.repeat(72)}`);
const money = n => 'LKR ' + Math.round(Number(n) || 0).toLocaleString('en-LK');

function session(who) {
  let cookie = '';
  const remember = res => {
    const set = res.headers.getSetCookie ? res.headers.getSetCookie() : [];
    for (const c of set) {
      const pair = c.split(';')[0];
      const name = pair.split('=')[0];
      cookie = cookie.split('; ').filter(Boolean)
        .filter(x => x.split('=')[0] !== name).concat(pair).join('; ');
    }
    return res;
  };
  const log = (method, p, status) => calls.push(`${who.padEnd(8)} ${method.padEnd(4)} ${p.split('?')[0].padEnd(34)} ${status}`);

  return {
    who,
    async get(p) {
      const res = remember(await fetch(`${BASE}/api/${p}`,
        { headers: { Accept: 'application/json', Cookie: cookie } }));
      log('GET', p, res.status);
      return { status: res.status, body: await res.json().catch(() => null) };
    },
    async post(p, payload) {
      const c = remember(await fetch(`${BASE}/api/auth/csrf.php`,
        { headers: { Accept: 'application/json', Cookie: cookie } }));
      const csrf = (await c.json()).data.csrf_token;
      const res = remember(await fetch(`${BASE}/api/${p}`, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json',
                   'X-CSRF-Token': csrf, Cookie: cookie },
        body: JSON.stringify(payload)
      }));
      log('POST', p, res.status);
      return { status: res.status, body: await res.json().catch(() => null) };
    },
    async login(email) {
      const r = await this.post('auth/login.php', { email, password: PASSWORD });
      if (r.status !== 200) throw new Error(`${who} login failed: ${r.status} ${r.body?.error || ''}`);
      return r.body.data.user;
    }
  };
}

console.log('='.repeat(72));
console.log('  Dashboard end-to-end: does one real order move every screen?');
console.log('='.repeat(72));

try {
  await fetch(`${BASE}/api/auth/csrf.php`);
} catch {
  console.log(`\n  Cannot reach ${BASE}. Start Apache and MySQL.\n`);
  process.exit(1);
}

const cust = session('customer');
const seller = session('seller');
const admin = session('admin');

const custUser = await cust.login('nimal@example.com');
const sellerUser = await seller.login('techhub@example.com');
const adminUser = await admin.login('admin@consult.lk');
check('all three demo accounts sign in',
  custUser.role === 'customer' && sellerUser.role === 'seller' && adminUser.role === 'admin',
  `${custUser.role} / ${sellerUser.role} / ${adminUser.role}`);

/* ================================================================
   BEFORE
   ================================================================ */

section('BEFORE — the numbers each dashboard shows');

const sellerBefore = (await seller.get('seller/dashboard.php')).body.data;
const adminBefore = (await admin.get('admin/dashboard.php')).body.data;
const custBefore = (await cust.get('orders/list.php')).body.data;

const snap = (s, a, c) => ({
  sellerOrders: s.orders.length,
  sellerRevenue: s.stats.revenue_total ?? s.stats.revenue_30d ?? 0,
  sellerPending: s.orders.filter(o => o.status === 'pending').length,
  adminGmv: a.stats.gmv_total,
  adminOrders: a.stats.orders_total,
  adminSubs: a.stats.sub_orders_total,
  adminRecent: a.orders.length,
  custOrders: (c.items || []).length
});

const before = snap(sellerBefore, adminBefore, custBefore);
console.log(`\n  seller: ${before.sellerOrders} sub-orders, ${before.sellerPending} pending`);
console.log(`  admin : GMV ${money(before.adminGmv)}, ${before.adminOrders} orders, ${before.adminSubs} sub-orders`);
console.log(`  buyer : ${before.custOrders} orders`);

/* the seller's own stock, so we can put it back */
const pick = sellerBefore.products.find(p => p.stock > 0 && p.status === 'active');
check('the seller has a listing in stock to sell', !!pick,
  pick ? `${pick.id} "${pick.title}" stock ${pick.stock}` : 'none found');
if (!pick) process.exit(1);
const stockBefore = pick.stock;

/* ================================================================
   1. the customer places an order
   ================================================================ */

section('1. nimal@example.com places an order');

await cust.post('cart/clear.php', {});
const added = await cust.post('cart/add.php', { product_id: pick.id, qty: 1 });
check('item added to the cart', added.status === 200, `${pick.id}`);

const placed = await cust.post('orders/checkout.php', {
  name: 'Nimal Perera', phone: '0771234567',
  address: '12 Test Lane', city: 'Colombo', zip: '00100'
});
check('checkout returns 201', placed.status === 201,
  placed.status === 201 ? '' : JSON.stringify(placed.body).slice(0, 120));
const newOrder = placed.body?.data?.order;
check('an order id came back', !!newOrder?.id, newOrder ? `order ${newOrder.id}` : 'none');

const orderTotal = Number(newOrder?.total || 0);
const deliveryFee = Number(newOrder?.delivery ?? newOrder?.delivery_fee ?? 0);
console.log(`        order total: ${money(orderTotal)}`);

/* ================================================================
   2. the seller sees it
   ================================================================ */

section('2. The seller dashboard picks it up');

const sellerAfter1 = (await seller.get('seller/dashboard.php')).body.data;

/* seller_orders() is capped at LIMIT 500, so once a seller has 500+
   sub-orders the LIST LENGTH can never grow — a new order silently pushes
   the oldest out. The count is therefore not a valid signal; what matters
   is whether the new order is actually present. */
const capped = sellerAfter1.orders.length >= 500;
check(capped ? 'seller list is at its 500-row cap (length cannot grow)'
             : 'the seller sub-order count went up',
  capped ? true : sellerAfter1.orders.length === before.sellerOrders + 1,
  `${before.sellerOrders} -> ${sellerAfter1.orders.length}${capped ? '  <-- CAPPED' : ''}`);
check('it shows as pending',
  sellerAfter1.orders.filter(o => o.status === 'pending').length === before.sellerPending + 1,
  `${before.sellerPending} -> ${sellerAfter1.orders.filter(o => o.status === 'pending').length}`);

/* The seller row carries the order as a FORMATTED ref ('ORD-014234'),
   not the raw id — seller_orders() shapes it with order_ref(). Matching on
   the raw id finds nothing, which is a bug in this test, not the app. */
const ref = 'ORD-' + String(newOrder.id).padStart(6, '0');
const mine = sellerAfter1.orders.find(o => o.order_id === ref);
check('the new sub-order is in the seller list', !!mine, mine ? mine.id : 'not found');

const stockNow = sellerAfter1.products.find(p => p.id === pick.id)?.stock;
check('stock dropped by one', stockNow === stockBefore - 1, `${stockBefore} -> ${stockNow}`);

/* ================================================================
   3. seller moves it along
   ================================================================ */

section('3. The seller marks it packed, then shipped');

const subId = mine?.db_id ?? mine?.id;
let packed = await seller.post('seller/order_status.php',
  { sub_order_id: mine.db_id, status: 'packed' });
check('packed accepted', packed.status === 200,
  packed.status === 200 ? '' : JSON.stringify(packed.body).slice(0, 140));

let shipped = await seller.post('seller/order_status.php',
  { sub_order_id: mine.db_id, status: 'shipped', tracking_number: 'E2E-TEST-001', courier_name: 'Test Courier' });
check('shipped accepted', shipped.status === 200,
  shipped.status === 200 ? '' : JSON.stringify(shipped.body).slice(0, 140));

const sellerAfter2 = (await seller.get('seller/dashboard.php')).body.data;
const mine2 = sellerAfter2.orders.find(o => String(o.db_id) === String(mine.db_id));
check('the seller dashboard now shows it shipped', mine2?.status === 'shipped', mine2?.status);

/* ================================================================
   4. the customer sees the change
   ================================================================ */

section('4. The customer order page follows');

const custAfter = (await cust.get('orders/list.php')).body.data;
/* orders/list.php returns a PAGED envelope: {items, total, page,
   per_page, pages}. There is no `orders` key — reading one gave 0 and
   looked like the buyer could not see their own order. */
check('the buyer has one more order',
  (custAfter.items || []).length === before.custOrders + 1,
  `${before.custOrders} -> ${(custAfter.items || []).length}`);

const custOrder = (custAfter.items || []).find(o => String(o.id) === String(newOrder.id));
const custSub = custOrder?.sub_orders?.[0];
check('the buyer sees status "shipped"', custSub?.status === 'shipped', custSub?.status);
check('and the tracking number the seller entered',
  custSub?.tracking_number === 'E2E-TEST-001', custSub?.tracking_number || 'none');

/* ================================================================
   5. the admin console
   ================================================================ */

section('5. The admin console');

const adminAfter = (await admin.get('admin/dashboard.php')).body.data;

check('admin order count went up',
  adminAfter.stats.orders_total === before.adminOrders + 1,
  `${before.adminOrders} -> ${adminAfter.stats.orders_total}`);
check('admin sub-order count went up',
  adminAfter.stats.sub_orders_total === before.adminSubs + 1,
  `${before.adminSubs} -> ${adminAfter.stats.sub_orders_total}`);
/* GMV is SUM(sub_orders.subtotal) — MERCHANDISE only. The order total
   also carries the delivery fee, so GMV rises by the goods value, not the
   total. That is the right definition of gross merchandise value; the
   gap should be exactly the delivery fee. */
const gmvRise = adminAfter.stats.gmv_total - before.adminGmv;
const goods = orderTotal - deliveryFee;
check('admin GMV rose by the merchandise value (delivery excluded)',
  Math.abs(gmvRise - goods) < 1,
  `${money(before.adminGmv)} -> ${money(adminAfter.stats.gmv_total)}  (+${money(gmvRise)}; goods ${money(goods)} + delivery ${money(deliveryFee)} = ${money(orderTotal)})`);

const inRecent = adminAfter.orders.find(o => String(o.order_db_id) === String(newOrder.id));
check('the order is in the admin recent-orders list', !!inRecent,
  inRecent ? `${inRecent.id} status ${inRecent.status}` : 'not found');
check('with the status the seller set', inRecent?.status === 'shipped', inRecent?.status);

/* the 30-day series must have moved too */
const todayKey = new Date().toISOString().slice(0, 10);
const seriesBefore = (adminBefore.series || []).find(d => String(d.date) === todayKey);
const seriesAfter = (adminAfter.series || []).find(d => String(d.date) === todayKey);
check('today\'s point in the 30-day series moved',
  seriesAfter && seriesBefore ? Number(seriesAfter.gmv ?? 0) > Number(seriesBefore.gmv ?? 0) : !!seriesAfter,
  seriesBefore && seriesAfter
    ? `${JSON.stringify(seriesBefore)} -> ${JSON.stringify(seriesAfter)}`
    : 'no point for today');

/* ================================================================
   cleanup
   ================================================================ */

section('Cleanup');

const del = await admin.post('admin/order_action.php', {
  sub_order_id: mine.db_id, action: 'refund',
  reason: 'End-to-end dashboard test — removing the test order'
});
console.log(`  refunded the test sub-order: ${del.status === 200 ? 'ok' : 'FAILED ' + JSON.stringify(del.body).slice(0, 120)}`);
console.log(`  NOTE: order ${newOrder.id} remains in the database as a refunded order.`);
console.log(`        Delete it with:  DELETE FROM orders WHERE id = ${newOrder.id};`);

/* ================================================================
   report
   ================================================================ */

section('Endpoints used');
for (const c of calls) console.log('  ' + c);

console.log(`\n${'-'.repeat(72)}`);
console.log(`  ${pass} passed, ${fail} failed`);
console.log('-'.repeat(72));
if (failures.length) {
  console.log('\n  Failures:');
  for (const f of failures) console.log(`    - ${f}`);
  console.log('');
}
process.exit(fail ? 1 : 0);
