/*
 * tests/logo_sweep.mjs — find every brand mark still showing a letter.
 *
 *   node tests\logo_sweep.mjs
 *
 * The nav logo passes on all 17 pages, so if something still looks like
 * the old maroon "C" it is a DIFFERENT element. This walks each page and
 * reports every small square-ish box whose only content is the letter C,
 * wherever it lives — nav, sidebar, chat bubble, anywhere.
 */
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PROJ = path.resolve(__dirname, '..');
const BASE = (process.argv[2] || 'http://localhost/consult-project').replace(/\/+$/, '');

const exe = [
  'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
  'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe'
].find(p => fs.existsSync(p));

const PORT = 9346;
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'logo-sweep-'));
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

/* Any element whose entire visible text is "C" and which is drawn as a
   small coloured box — i.e. looks like a logo tile. */
const FIND = `(() => {
  const hits = [];
  for (const el of document.querySelectorAll('body *')) {
    if (el.children.length) continue;
    if (el.textContent.trim() !== 'C') continue;
    const r = el.getBoundingClientRect();
    const cs = getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility === 'hidden') continue;
    hits.push({
      cls: (el.className || '(no class)').toString(),
      tag: el.tagName.toLowerCase(),
      size: Math.round(r.width) + 'x' + Math.round(r.height),
      bg: cs.backgroundColor,
      onScreen: r.width > 0 && r.height > 0
    });
  }
  const logos = [...document.querySelectorAll('img[src*="consult-mark"]')].length;
  return { hits, logos };
})()`;

const pages = fs.readdirSync(PROJ).filter(f => f.endsWith('.html'));

console.log('='.repeat(70));
console.log('  Brand marks still rendering the letter "C"');
console.log('='.repeat(70));

let total = 0;

for (const page of pages) {
  const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
  const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
  await send('Page.enable', {}, sessionId);
  await send('Runtime.enable', {}, sessionId);
  await send('Emulation.setDeviceMetricsOverride',
    { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false }, sessionId);
  await send('Page.navigate', { url: `${BASE}/${page}` }, sessionId);
  await new Promise(r => setTimeout(r, 1800));

  const r = (await send('Runtime.evaluate',
    { expression: FIND, returnByValue: true }, sessionId)).result.value;

  const line = `${page.padEnd(23)} logo img: ${r.logos}`;
  if (r.hits.length) {
    total += r.hits.length;
    console.log(`\n  ${line}   <-- ${r.hits.length} "C" mark(s) still here`);
    for (const h of r.hits) {
      console.log(`      ${h.tag}.${h.cls}  ${h.size}  bg ${h.bg}`);
    }
  } else {
    console.log(`  ${line}   clean`);
  }

  await send('Target.closeTarget', { targetId });
}

/* The chat is built on demand, so open it and look again. */
console.log(`\n${'='.repeat(70)}\n  index.html with the chat open\n${'='.repeat(70)}`);
{
  const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
  const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
  await send('Page.enable', {}, sessionId);
  await send('Runtime.enable', {}, sessionId);
  await send('Emulation.setDeviceMetricsOverride',
    { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false }, sessionId);
  await send('Page.navigate', { url: `${BASE}/index.html` }, sessionId);
  await new Promise(r => setTimeout(r, 2200));
  await send('Runtime.evaluate',
    { expression: `typeof showChat === 'function' ? showChat() : 0`, returnByValue: true }, sessionId);
  await new Promise(r => setTimeout(r, 1200));

  const r = (await send('Runtime.evaluate',
    { expression: FIND, returnByValue: true }, sessionId)).result.value;
  if (r.hits.length) {
    total += r.hits.length;
    for (const h of r.hits) {
      console.log(`  ${h.tag}.${h.cls}  ${h.size}  bg ${h.bg}   <-- still a "C"`);
    }
  } else {
    console.log('  clean');
  }
  await send('Target.closeTarget', { targetId });
}

ws.close();
browser.kill();

console.log(`\n${'-'.repeat(70)}`);
console.log(`  ${total} element(s) still drawing the letter C`);
console.log('-'.repeat(70));
