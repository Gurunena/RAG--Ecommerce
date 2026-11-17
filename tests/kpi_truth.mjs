/*
 * tests/kpi_truth.mjs — are the dashboard KPIs the SQL truth, or a sum
 * over a truncated list?
 *
 *   node tests\kpi_truth.mjs
 *
 * Both dashboards fetch their data in one payload. That payload carries
 * `stats`, computed in SQL over EVERY row, and `orders`, which is capped:
 *
 *     admin_orders(int $limit = 500)
 *     seller_orders(int $sellerId, int $limit = 500)
 *
 * Some KPI cards reduce over the capped `orders` array in JavaScript
 * instead of reading `stats`. While a shop has under 500 sub-orders the
 * two agree and nothing looks wrong. Past that they diverge silently, and
 * the KPI stops responding to anything older than the newest 500 rows —
 * which is exactly "the dashboard shows fixed data".
 *
 * This prints both numbers side by side so the gap is a fact, not a
 * theory.
 */
const BASE = (process.argv[2] || 'http://localhost/consult-project').replace(/\/+$/, '');
const PASSWORD = 'Consult@2026';

const money = n => 'LKR ' + Math.round(Number(n) || 0).toLocaleString('en-LK');

function session() {
  let cookie = '';
  const remember = res => {
    for (const c of (res.headers.getSetCookie ? res.headers.getSetCookie() : [])) {
      const pair = c.split(';')[0], name = pair.split('=')[0];
      cookie = cookie.split('; ').filter(Boolean)
        .filter(x => x.split('=')[0] !== name).concat(pair).join('; ');
    }
    return res;
  };
  return {
    async get(p) {
      const r = remember(await fetch(`${BASE}/api/${p}`,
        { headers: { Accept: 'application/json', Cookie: cookie } }));
      return (await r.json()).data;
    },
    async login(email) {
      const c = remember(await fetch(`${BASE}/api/auth/csrf.php`,
        { headers: { Accept: 'application/json', Cookie: cookie } }));
      const csrf = (await c.json()).data.csrf_token;
      remember(await fetch(`${BASE}/api/auth/login.php`, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json',
                   'X-CSRF-Token': csrf, Cookie: cookie },
        body: JSON.stringify({ email, password: PASSWORD })
      }));
    }
  };
}

/* the same helpers the dashboards use, copied so the arithmetic matches */
const isLive = o => !o.refunded_at && o.status !== 'cancelled';
const hoursSince = iso => {
  const t = Date.parse(String(iso).replace(' ', 'T'));
  return isNaN(t) ? Infinity : (Date.now() - t) / 36e5;
};
const withinDays = (iso, d) => iso && hoursSince(iso) <= d * 24;

console.log('='.repeat(72));
console.log('  KPI cards: JS over a capped list  vs  the SQL stats');
console.log('='.repeat(72));

/* ================================================================
   admin
   ================================================================ */

const admin = session();
await admin.login('admin@consult.lk');
const a = await admin.get('admin/dashboard.php');

const subs = a.orders;
const live = subs.filter(isLive);
const live30 = live.filter(o => withinDays(o.placed_at, 30));
const jsGmv30 = live30.reduce((s, o) => s + (o.total || 0), 0);

console.log('\n  ADMIN — overviewView()');
console.log(`    sub-orders in the payload : ${subs.length}   (admin_orders LIMIT 500)`);
console.log(`    sub-orders in the database: ${a.stats.sub_orders_total}`);
console.log(`    rows the console never sees: ${a.stats.sub_orders_total - subs.length}`);
console.log('');
console.log(`    "Platform GMV · 30 days" as the card computes it (JS over the ${subs.length} rows)`);
console.log(`        ${money(jsGmv30)}`);
console.log(`    the same figure from SQL over every row  (stats.gmv_30d)`);
console.log(`        ${money(a.stats.gmv_30d)}`);

const gap = Math.abs(jsGmv30 - a.stats.gmv_30d);
console.log('');
console.log(gap < 1
  ? '    they agree today — the 30-day window happens to fit inside the 500'
  : `    THEY DISAGREE BY ${money(gap)}  (${(gap / Math.max(1, a.stats.gmv_30d) * 100).toFixed(1)}% of the real figure)`);

/* the oldest row the console can see, which is where the window closes */
const oldest = subs.map(o => o.placed_at).sort()[0];
console.log(`\n    oldest sub-order the console can see: ${oldest}`);
console.log(`    anything placed before that is invisible to every JS-computed KPI.`);

/* ================================================================
   seller
   ================================================================ */

const seller = session();
await seller.login('techhub@example.com');
const s = await seller.get('seller/dashboard.php');

console.log('\n  SELLER — techhub@example.com');
console.log(`    sub-orders in the payload : ${s.orders.length}   (seller_orders LIMIT 500)`);
const sellerStats = s.stats || {};
console.log(`    stats keys available      : ${Object.keys(sellerStats).join(', ')}`);

const sLive = s.orders.filter(isLive);
const sLive30 = sLive.filter(o => withinDays(o.placed_at, 30));
const sJsRev30 = sLive30.reduce((t, o) => t + (o.total || 0), 0);
console.log('');
console.log(`    revenue 30d, JS over the ${s.orders.length} rows : ${money(sJsRev30)}`);
for (const k of ['revenue_30d', 'revenue_total', 'gmv_30d']) {
  if (k in sellerStats) console.log(`    stats.${k.padEnd(14)} (SQL)      : ${money(sellerStats[k])}`);
}

const sOldest = s.orders.map(o => o.placed_at).sort()[0];
console.log(`\n    oldest sub-order this seller can see: ${sOldest}`);

console.log(`\n${'-'.repeat(72)}`);
console.log('  A KPI that reduces over `orders` can only ever reflect the newest');
console.log('  500 rows. A KPI that reads `stats` reflects the whole table.');
console.log('-'.repeat(72));
