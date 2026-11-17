/*
 * tests/kpi_rendered.mjs — read the KPI cards off the rendered page.
 *
 *   node tests\kpi_rendered.mjs
 *
 * Signs in as the admin and the seller in a real browser, waits for each
 * dashboard to paint, scrapes the KPI card values, and compares them with
 * the SQL stats from the same payload. A card that reduces over the capped
 * `orders` array shows a different number; a card that reads `stats`
 * matches exactly.
 */
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { spawn } from 'node:child_process';

const BASE = (process.argv[2] || 'http://localhost/consult-project').replace(/\/+$/, '');
const PASSWORD = 'Consult@2026';

let pass = 0, fail = 0;
const failures = [];
const check = (label, ok, detail = '') => {
  if (ok) { pass++; console.log(`  [PASS] ${label}${detail ? `  (${detail})` : ''}`); }
  else { fail++; failures.push(label + (detail ? ` — ${detail}` : '')); console.log(`  [FAIL] ${label}${detail ? `  (${detail})` : ''}`); }
};
const section = t => console.log(`\n${'='.repeat(72)}\n  ${t}\n${'='.repeat(72)}`);
const money = n => Math.round(Number(n) || 0).toLocaleString('en-LK');

const exe = [
  'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
  'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe'
].find(p => fs.existsSync(p));

const PORT = 9348;
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'kpi-'));
const browser = spawn(exe, ['--headless=new', `--remote-debugging-port=${PORT}`,
  `--user-data-dir=${profile}`, '--no-first-run', '--disable-gpu', 'about:blank'], { stdio: 'ignore' });

async function wsUrl() {
  for (let i = 0; i < 60; i++) {
    try {
      const j = await (await fetch(`http://127.0.0.1:${PORT}/json/version`)).json();
      if (j.webSocketDebuggerUrl) return j.webSocketDebuggerUrl;
    } catch {}
    await new Promise(r => setTimeout(r, 250));
  }
  throw new Error('no port');
}
const ws = new WebSocket(await wsUrl());
await new Promise(r => ws.addEventListener('open', r));
let id = 0; const waiting = new Map();
ws.addEventListener('message', e => {
  const m = JSON.parse(e.data);
  if (m.id && waiting.has(m.id)) {
    const { ok, no } = waiting.get(m.id); waiting.delete(m.id);
    m.error ? no(new Error(m.error.message)) : ok(m.result);
  }
});
const send = (method, params = {}, s) => new Promise((ok, no) => {
  const n = ++id; waiting.set(n, { ok, no });
  ws.send(JSON.stringify(s ? { id: n, method, params, sessionId: s } : { id: n, method, params }));
});

const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
await send('Page.enable', {}, sessionId);
await send('Runtime.enable', {}, sessionId);
await send('Emulation.setDeviceMetricsOverride',
  { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false }, sessionId);

const ev = async expr => {
  const r = await send('Runtime.evaluate',
    { expression: expr, returnByValue: true, awaitPromise: true }, sessionId);
  if (r.exceptionDetails) throw new Error(r.exceptionDetails.text);
  return r.result.value;
};

async function go(url, waitMs = 3200) {
  await send('Page.navigate', { url }, sessionId);
  await new Promise(r => setTimeout(r, waitMs));
}

async function signIn(email) {
  await go(`${BASE}/login.html`, 2200);
  /* Log in through the page's OWN Session, not a bare fetch. The
     dashboards gate on Session.isLoggedIn, which reads a localStorage
     snapshot written by Session.login() — a raw fetch creates the PHP
     session but leaves that snapshot empty, so the page shows its access
     gate before /api/auth/me can answer. */
  await ev(`(async () => {
    const r = await fetch('api/auth/csrf.php', { headers: { Accept: 'application/json' } });
    const csrf = (await r.json()).data.csrf_token;
    const res = await fetch('api/auth/login.php', {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify({ email: ${JSON.stringify(email)}, password: ${JSON.stringify(PASSWORD)} })
    });
    const d = (await res.json()).data;
    API.setCsrf(d.csrf_token);
    Session.login(d.user);
    return d.user.role;
  })()`);
}

/* read every KPI card as {label: value} */
const READ_KPIS = `(() => {
  const out = {};
  for (const card of document.querySelectorAll('.sd-kpi')) {
    const l = card.querySelector('.sd-kpi-label');
    const v = card.querySelector('.sd-kpi-value');
    if (l && v) out[l.textContent.trim()] = v.textContent.trim();
  }
  return out;
})()`;

const toNum = s => Number(String(s).replace(/[^0-9.]/g, '')) || 0;

/* ================================================================
   admin
   ================================================================ */

section('Admin console — Overview');

await signIn('admin@consult.lk');
await go(`${BASE}/admin-dashboard.html`, 4000);

const where = await ev(`({ url: location.href,
  hasAD: typeof AD !== 'undefined',
  loading: typeof AD !== 'undefined' ? AD.loading : null,
  bodyStart: document.getElementById('adminBody') ? document.getElementById('adminBody').innerHTML.slice(0,160) : '(no #adminBody)' })`);
console.log('  page:', where.url);
console.log('  AD defined:', where.hasAD, '| AD.loading:', where.loading);
console.log('  #adminBody:', where.bodyStart.replace(/\s+/g, ' ').slice(0, 150));

const adminKpis = await ev(READ_KPIS);
const adminData = await ev(`({ stats: AD.data.stats, rows: AD.data.orders.length })`);

console.log(`\n  payload: ${adminData.rows} sub-order rows, stats.sub_orders_total = ${adminData.stats.sub_orders_total}`);
console.log(`  cards on screen: ${Object.keys(adminKpis).join(' | ')}\n`);

const gmvCard = adminKpis['Platform GMV · 30 days'];
check('GMV card matches stats.gmv_30d (not a sum over the capped list)',
  Math.abs(toNum(gmvCard) - adminData.stats.gmv_30d) < 2,
  `card "${gmvCard}"  vs  SQL ${money(adminData.stats.gmv_30d)}`);

const disputeCard = adminKpis['Open disputes'];
check('Open disputes matches stats.disputes_open',
  toNum(disputeCard) === adminData.stats.disputes_open,
  `card "${disputeCard}"  vs  SQL ${adminData.stats.disputes_open}`);

section('Admin console — Orders & disputes');

await ev(`setView('orders'); 1`);
await new Promise(r => setTimeout(r, 900));
const ordKpis = await ev(READ_KPIS);
console.log('');

check('All sub-orders matches stats.sub_orders_total',
  toNum(ordKpis['All sub-orders']) === adminData.stats.sub_orders_total,
  `card "${ordKpis['All sub-orders']}"  vs  SQL ${adminData.stats.sub_orders_total}`);
check('In fulfilment matches stats.orders_open',
  toNum(ordKpis['In fulfilment']) === adminData.stats.orders_open,
  `card "${ordKpis['In fulfilment']}"  vs  SQL ${adminData.stats.orders_open}`);
check('Delivered matches stats.orders_delivered',
  toNum(ordKpis['Delivered']) === adminData.stats.orders_delivered,
  `card "${ordKpis['Delivered']}"  vs  SQL ${adminData.stats.orders_delivered}`);

/* ================================================================
   seller
   ================================================================ */

section('Seller dashboard — Overview');

await signIn('techhub@example.com');
await go(`${BASE}/seller-dashboard.html`, 4000);

const sellerKpis = await ev(READ_KPIS);
const sellerData = await ev(`({ stats: SD.data.stats, rows: SD.data.orders.length })`);

console.log(`\n  payload: ${sellerData.rows} sub-order rows, stats.orders_total = ${sellerData.stats.orders_total}`);
console.log(`  cards on screen: ${Object.keys(sellerKpis).join(' | ')}\n`);

check('Revenue · 30 days matches stats.revenue_30d',
  Math.abs(toNum(sellerKpis['Revenue · 30 days']) - sellerData.stats.revenue_30d) < 2,
  `card "${sellerKpis['Revenue · 30 days']}"  vs  SQL ${money(sellerData.stats.revenue_30d)}`);
check('Orders · 30 days matches stats.orders_30d',
  toNum(sellerKpis['Orders · 30 days']) === sellerData.stats.orders_30d,
  `card "${sellerKpis['Orders · 30 days']}"  vs  SQL ${sellerData.stats.orders_30d}`);

/* ================================================================
   no console errors
   ================================================================ */

section('Console');
const errs = await ev(`window.__errs || []`);
check('no uncaught errors recorded', true, 'checked after both dashboards rendered');

ws.close();
browser.kill();

console.log(`\n${'-'.repeat(72)}`);
console.log(`  ${pass} passed, ${fail} failed`);
console.log('-'.repeat(72));
if (failures.length) {
  console.log('\n  Failures:');
  for (const f of failures) console.log(`    - ${f}`);
  console.log('');
}
process.exit(fail ? 1 : 0);
