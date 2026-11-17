/*
 * tests/logo_check.mjs — the nav logo, in a real browser.
 *
 *   cd C:\E-commerce\consult-project
 *   node tests\logo_check.mjs
 *
 * Verifies on every page that the "C" letter tile is gone, the real logo
 * image loads, there is no maroon square behind it, and the nav is exactly
 * the height it was before (the image was sized to the tile it replaced
 * so nothing shifts).
 *
 * Also saves a 3x close-up of the nav logo to tests/screenshots/.
 */
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PROJ = path.resolve(__dirname, '..');
const BASE = (process.argv[2] || 'http://localhost/consult-project').replace(/\/+$/, '');
const SHOTS = path.join(__dirname, 'screenshots');
fs.mkdirSync(SHOTS, { recursive: true });

let pass = 0, fail = 0;
const failures = [];
const check = (label, ok, detail = '') => {
  if (ok) { pass++; console.log(`  [PASS] ${label}${detail ? `  (${detail})` : ''}`); }
  else { fail++; failures.push(label + (detail ? ` — ${detail}` : '')); console.log(`  [FAIL] ${label}${detail ? `  (${detail})` : ''}`); }
};
const section = t => console.log(`\n${'='.repeat(70)}\n  ${t}\n${'='.repeat(70)}`);

const exe = [
  'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
  'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe'
].find(p => fs.existsSync(p));

const PORT = 9344;
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'logo-check-'));
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
  throw new Error('no debugging port');
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

async function open(url, width = 1280) {
  const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
  const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
  await send('Page.enable', {}, sessionId);
  await send('Runtime.enable', {}, sessionId);
  await send('Emulation.setDeviceMetricsOverride',
    { width, height: 900, deviceScaleFactor: 1, mobile: width < 500 }, sessionId);
  await send('Page.navigate', { url }, sessionId);
  await new Promise(r => setTimeout(r, 2000));
  const ev = async expr => {
    const r = await send('Runtime.evaluate',
      { expression: expr, returnByValue: true, awaitPromise: true }, sessionId);
    if (r.exceptionDetails) throw new Error(r.exceptionDetails.text);
    return r.result.value;
  };
  return { ev, sessionId, targetId };
}

/* ================================================================
   1. the source files
   ================================================================ */

section('1. The files');

const pages = fs.readdirSync(PROJ).filter(f => f.endsWith('.html'));

const stillLetter = pages.filter(f =>
  /class="logo-mark"/.test(fs.readFileSync(path.join(PROJ, f), 'utf8')));
check('no page still has the "C" letter tile', stillLetter.length === 0,
  stillLetter.length ? stillLetter.join(', ') : pages.length + ' pages');

const missingImg = pages.filter(f =>
  !/images\/consult-mark\.png/.test(fs.readFileSync(path.join(PROJ, f), 'utf8')));
check('every page uses the real logo', missingImg.length === 0,
  missingImg.length ? missingImg.join(', ') : pages.length + ' pages');

const markPath = path.join(PROJ, 'images', 'consult-mark.png');
check('the logo file exists and is small', fs.existsSync(markPath)
  && fs.statSync(markPath).size < 60000,
  Math.round(fs.statSync(markPath).size / 1024) + ' KB');

const orig = path.join(PROJ, 'images', 'Consult _Logo.png');
check('your original artwork is untouched',
  fs.existsSync(orig) && fs.statSync(orig).size > 900000,
  Math.round(fs.statSync(orig).size / 1024) + ' KB');

/* nothing else changed */
const css = fs.readFileSync(path.join(PROJ, 'css', 'styles.css'), 'utf8');
check('css/styles.css was not edited',
  /\.logo-mark\{[\s\S]*?background:var\(--maroon\)/.test(css),
  'the original .logo-mark rule is still there, now unused');

/* ================================================================
   2. in the browser
   ================================================================ */

section('2. Rendered, every page');

for (const page of pages) {
  const p = await open(`${BASE}/${page}`);
  const info = await p.ev(`(async () => {
    const img = document.querySelector('nav.editorial .logo img');
    if (!img) return { found: false };
    const r = img.getBoundingClientRect();
    let status = 0, bytes = 0;
    try { const q = await fetch(img.src); status = q.status; bytes = (await q.blob()).size; } catch {}
    const cs = getComputedStyle(img);
    return {
      found: true, status, bytes,
      complete: img.complete, natural: img.naturalWidth + 'x' + img.naturalHeight,
      size: Math.round(r.width) + 'x' + Math.round(r.height),
      bg: cs.backgroundColor,
      navH: Math.round(document.querySelector('nav.editorial').getBoundingClientRect().height),
      letterC: !!document.querySelector('nav.editorial .logo-mark')
    };
  })()`);

  if (!info.found) { check(`${page}: logo image present`, false); continue; }

  const ok = info.status === 200 && info.complete && info.bytes > 1000
    && info.size === '31x31' && info.bg === 'rgba(0, 0, 0, 0)' && !info.letterC;
  check(`${page.padEnd(22)} logo ok`, ok,
    `${info.size}, HTTP ${info.status}, ${Math.round(info.bytes / 1024)}KB, natural ${info.natural}, bg ${info.bg}${info.letterC ? ', C TILE STILL THERE' : ''}`);

  await send('Target.closeTarget', { targetId: p.targetId });
}

/* ================================================================
   3. the nav did not move
   ================================================================ */

section('3. Nothing shifted');

{
  const p = await open(`${BASE}/index.html`);
  const now = await p.ev(
    `Math.round(document.querySelector('nav.editorial').getBoundingClientRect().height)`);

  /* Put the old tile back in the same browser and compare — the only
     honest baseline. */
  const old = await p.ev(`(() => {
    const img = document.querySelector('nav.editorial .logo img');
    const d = document.createElement('div');
    d.className = 'logo-mark';
    d.textContent = 'C';
    img.replaceWith(d);
    return Math.round(document.querySelector('nav.editorial').getBoundingClientRect().height);
  })()`);

  check('nav height identical to the old "C" tile', now === old,
    `logo ${now}px, old tile ${old}px`);
  await send('Target.closeTarget', { targetId: p.targetId });
}

/* ================================================================
   4. phone width
   ================================================================ */

section('4. 375px');

{
  const p = await open(`${BASE}/index.html`, 375);
  const info = await p.ev(`(() => {
    const d = document.documentElement;
    const img = document.querySelector('nav.editorial .logo img');
    const r = img.getBoundingClientRect();
    return { scrollW: d.scrollWidth, clientW: d.clientWidth,
             size: Math.round(r.width) + 'x' + Math.round(r.height) };
  })()`);
  check('no horizontal scroll at 375px', info.scrollW <= info.clientW + 1,
    `scrollW ${info.scrollW} vs clientW ${info.clientW}`);
  check('logo still 31x31 on a phone', info.size === '31x31', info.size);
  await send('Target.closeTarget', { targetId: p.targetId });
}

/* ================================================================
   5. a picture
   ================================================================ */

{
  const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
  const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
  await send('Page.enable', {}, sessionId);
  await send('Runtime.enable', {}, sessionId);
  await send('Emulation.setDeviceMetricsOverride',
    { width: 1280, height: 900, deviceScaleFactor: 3, mobile: false }, sessionId);
  await send('Page.navigate', { url: `${BASE}/index.html` }, sessionId);
  await new Promise(r => setTimeout(r, 2200));

  // open the chat so its sidebar brand is in the DOM
  await send('Runtime.evaluate',
    { expression: "typeof showChat === 'function' ? showChat() : 0", returnByValue: true },
    sessionId);
  await new Promise(r => setTimeout(r, 1200));

  for (const [sel, name, pad] of [
    ['nav.editorial .logo', 'logo-close.png', 8],
    ['nav.editorial', 'logo-navbar.png', 0],
    ['.consult-side-brand', 'logo-chat-sidebar.png', 10]
  ]) {
    const box = (await send('Runtime.evaluate', {
      expression: `(() => { const el = document.querySelector('${sel}');
        const r = el.getBoundingClientRect();
        return { x: Math.max(0, r.left - ${pad}), y: Math.max(0, r.top - ${pad}),
                 width: r.width + ${pad * 2}, height: r.height + ${pad * 2} }; })()`,
      returnByValue: true
    }, sessionId)).result.value;
    const shot = await send('Page.captureScreenshot',
      { format: 'png', clip: { ...box, scale: 3 }, captureBeyondViewport: true }, sessionId);
    fs.writeFileSync(path.join(SHOTS, name), Buffer.from(shot.data, 'base64'));
    console.log(`\n  screenshot: tests/screenshots/${name}`);
  }
  await send('Target.closeTarget', { targetId });
}

ws.close();
browser.kill();

console.log(`\n${'-'.repeat(70)}`);
console.log(`  ${pass} passed, ${fail} failed`);
console.log('-'.repeat(70));
if (failures.length) {
  console.log('\n  Failures:');
  for (const f of failures) console.log(`    - ${f}`);
  console.log('');
}
process.exit(fail ? 1 : 0);
