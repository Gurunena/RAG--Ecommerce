/* ============================================================
   dash-common.js — helpers shared by seller-dashboard.js and
   admin-dashboard.js: icons, time formatting, hand-built SVG
   charts, the modal shell, and the sidebar renderer. Loaded
   after common.js on both dashboard pages only.
   ============================================================ */

/* ---------- icons (same inline-SVG idiom as the rest of the site) ---------- */
const DI = {
  home:   `<svg viewBox="0 0 24 24" fill="none"><path d="M4 11l8-7 8 7v9a1 1 0 01-1 1h-5v-6h-4v6H5a1 1 0 01-1-1z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>`,
  orders: `<svg viewBox="0 0 24 24" fill="none"><path d="M4 7h16v13H4z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M4 7l2-3h12l2 3M9 11h6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>`,
  box:    `<svg viewBox="0 0 24 24" fill="none"><path d="M12 3l8 4.5v9L12 21l-8-4.5v-9z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M4 7.5l8 4.5 8-4.5M12 12v9" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>`,
  layers: `<svg viewBox="0 0 24 24" fill="none"><path d="M12 4l9 4.5-9 4.5-9-4.5z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M3 13l9 4.5 9-4.5M3 17l9 4.5 9-4.5" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>`,
  cash:   `<svg viewBox="0 0 24 24" fill="none"><rect x="3" y="6" width="18" height="12" rx="2" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="12" r="2.6" stroke="currentColor" stroke-width="1.6"/></svg>`,
  payout: `<svg viewBox="0 0 24 24" fill="none"><path d="M12 4v13M8 13l4 4 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M5 20h14" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>`,
  clock:  `<svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.6"/><path d="M12 7.5V12l3 2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>`,
  star:   `<svg viewBox="0 0 24 24" fill="none"><path d="M12 3.5l2.6 5.4 5.9.8-4.3 4.1 1.1 5.9L12 16.9l-5.3 2.8 1.1-5.9-4.3-4.1 5.9-.8z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>`,
  chart:  `<svg viewBox="0 0 24 24" fill="none"><path d="M5 19V10M12 19V5M19 19v-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>`,
  gear:   `<svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="3.2" stroke="currentColor" stroke-width="1.6"/><path d="M12 3v2.4M12 18.6V21M21 12h-2.4M5.4 12H3M18.4 5.6l-1.7 1.7M7.3 16.7l-1.7 1.7M18.4 18.4l-1.7-1.7M7.3 7.3L5.6 5.6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>`,
  search: `<svg viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.8"/><path d="M20 20l-3.5-3.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>`,
  bell:   `<svg viewBox="0 0 24 24" fill="none"><path d="M6 9a6 6 0 0112 0c0 4 1.5 5.5 1.5 5.5h-15S6 13 6 9z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M10 18a2 2 0 004 0" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>`,
  check:  `<svg viewBox="0 0 24 24" fill="none"><path d="M5 12.5l4.5 4.5L19 7.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>`,
  shield: `<svg viewBox="0 0 24 24" fill="none"><path d="M12 3l7 3v5.4c0 4.3-2.9 7.8-7 8.9-4.1-1.1-7-4.6-7-8.9V6z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M9 12l2.1 2.1L15.4 10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>`,
  users:  `<svg viewBox="0 0 24 24" fill="none"><circle cx="9" cy="8" r="3.5" stroke="currentColor" stroke-width="1.6"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M16 4.5a3.5 3.5 0 010 7M21 20c0-2.6-1.6-4.8-4-5.6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>`,
  flag:   `<svg viewBox="0 0 24 24" fill="none"><path d="M5 21V4M5 4h12l-2 4 2 4H5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>`,
  help:   `<svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.6"/><path d="M9.5 9.5a2.5 2.5 0 015 0c0 1.5-2.5 2-2.5 3.5M12 16.5v.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>`,
  plus:   `<svg viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>`,
  edit:   `<svg viewBox="0 0 24 24" fill="none"><path d="M4 20h4l10.5-10.5a1.5 1.5 0 000-2.1l-1.9-1.9a1.5 1.5 0 00-2.1 0L4 16z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>`,
  trash:  `<svg viewBox="0 0 24 24" fill="none"><path d="M5 7h14M9 7V4h6v3M8 7l1 13h6l1-13" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>`,
  list:   `<svg viewBox="0 0 24 24" fill="none"><path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>`,
  board:  `<svg viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="5" height="16" rx="1" stroke="currentColor" stroke-width="1.8"/><rect x="10" y="4" width="5" height="10" rx="1" stroke="currentColor" stroke-width="1.8"/><rect x="17" y="4" width="4" height="13" rx="1" stroke="currentColor" stroke-width="1.8"/></svg>`,
  menu:   `<svg viewBox="0 0 24 24" fill="none"><path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>`,
  doc:    `<svg viewBox="0 0 24 24" fill="none"><path d="M6 3h8l4 4v14H6z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M14 3v4h4M9 12h6M9 16h6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>`,
  empty:  `<svg viewBox="0 0 24 24" fill="none"><path d="M3 8l9-5 9 5v8l-9 5-9-5V8z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><path d="M3 8l9 5 9-5M12 13v8" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>`,
  download:`<svg viewBox="0 0 24 24" fill="none"><path d="M12 4v11M7 11l5 5 5-5M4 20h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>`,
  external:`<svg viewBox="0 0 24 24" fill="none"><path d="M14 4h6v6M20 4l-9 9M18 14v6H4V6h6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>`
};

/* ---------- time ----------
   Every timestamp from the API is a MySQL DATETIME — '2026-10-09 14:32:08',
   with a space. `new Date()` accepts that in Chrome but returns Invalid Date
   in Safari and in some Firefox builds, which showed up as blank dates and
   NaN-wide chart bars rather than as an error. parseWhen() normalises the
   space to 'T' so every date helper below agrees, whatever the browser. */
function parseWhen(iso){
  if (!iso) return null;
  if (iso instanceof Date) return isNaN(iso) ? null : iso;
  const d = new Date(String(iso).trim().replace(' ', 'T'));
  return isNaN(d) ? null : d;
}

function timeAgo(iso){
  if (!iso) return '';
  const parsed = parseWhen(iso);
  if (!parsed) return '';
  const then = parsed.getTime();
  const mins = Math.floor((Date.now() - then) / 60000);
  if (mins < 1) return 'just now';
  if (mins < 60) return mins + (mins === 1 ? ' minute ago' : ' minutes ago');
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return hrs + (hrs === 1 ? ' hour ago' : ' hours ago');
  const days = Math.floor(hrs / 24);
  return days + (days === 1 ? ' day ago' : ' days ago');
}
function hoursSince(iso){
  const d = parseWhen(iso);
  return d ? (Date.now() - d.getTime()) / 3600000 : 0;
}
function shortDate(iso){
  const d = parseWhen(iso);
  return d ? d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';
}
const cap = s => s ? s.charAt(0).toUpperCase() + s.slice(1) : '';
const pct = n => (Math.round(n * 10) / 10) + '%';

function statusPill(s){ return `<span class="status-pill st-${escapeHtml(s)}">${escapeHtml(s)}</span>`; }

/* ---------- sparkline (7 points) + week-over-week trend ---------- */
function sparkline(values){
  const max = Math.max(...values, 1);
  const w = 68, h = 22;
  const step = values.length > 1 ? w / (values.length - 1) : w;
  const y = v => (h - (v / max) * (h - 4) - 2).toFixed(1);
  const pts = values.map((v, i) => `${(i * step).toFixed(1)},${y(v)}`).join(' ');
  const lastX = ((values.length - 1) * step).toFixed(1);
  return `<svg class="spark" viewBox="0 0 ${w} ${h}" width="${w}" height="${h}" aria-hidden="true">
    <polyline points="${pts}" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
    <circle cx="${lastX}" cy="${y(values[values.length - 1])}" r="2.2" fill="currentColor"/>
  </svg>`;
}
/* `series` is 14 daily values: last 7 vs the 7 before */
function trendOf(series){
  const recent = series.slice(-7).reduce((a, b) => a + b, 0);
  const prior  = series.slice(-14, -7).reduce((a, b) => a + b, 0);
  if (prior === 0 && recent === 0) return `<span class="trend flat" title="no activity in the last two weeks">—</span>`;
  if (prior === 0) return `<span class="trend up" title="nothing in the prior week">▲ new</span>`;
  const p = Math.round(((recent - prior) / prior) * 100);
  if (p === 0) return `<span class="trend flat" title="vs the previous 7 days">no change</span>`;
  return `<span class="trend ${p > 0 ? 'up' : 'down'}" title="vs the previous 7 days">${p > 0 ? '▲' : '▼'} ${Math.abs(p)}% vs last week</span>`;
}

/* ---------- 30-day line chart, hand-built SVG ----------
   buckets: [{label, total}] from daySeries(). One series, area fill
   underneath, three gridlines, sparse x labels so it stays legible
   at phone width. */
function lineChart(buckets, opts = {}){
  const W = 640, H = 200, padL = 44, padR = 12, padT = 14, padB = 28;
  const iw = W - padL - padR, ih = H - padT - padB;
  const vals = buckets.map(b => b.total);
  const max = Math.max(...vals, 1);
  const nice = niceMax(max);
  const x = i => padL + (buckets.length > 1 ? (i / (buckets.length - 1)) * iw : iw / 2);
  const y = v => padT + ih - (v / nice) * ih;
  const pts = vals.map((v, i) => `${x(i).toFixed(1)},${y(v).toFixed(1)}`);
  const line = pts.join(' ');
  const area = `${x(0).toFixed(1)},${(padT + ih).toFixed(1)} ${line} ${x(vals.length - 1).toFixed(1)},${(padT + ih).toFixed(1)}`;
  const grid = [0, .5, 1].map(f => {
    const gy = (padT + ih - f * ih).toFixed(1);
    return `<line x1="${padL}" x2="${W - padR}" y1="${gy}" y2="${gy}" stroke="#E4D9C4" stroke-width="1"/>
            <text x="${padL - 6}" y="${gy}" text-anchor="end" dominant-baseline="middle" font-size="9.5" font-family="JetBrains Mono, monospace" fill="#6B7280">${fmtAxis(nice * f, opts.money)}</text>`;
  }).join('');
  const every = Math.max(1, Math.round(buckets.length / 5));
  const xl = buckets.map((b, i) => (i % every === 0 || i === buckets.length - 1)
    ? `<text x="${x(i).toFixed(1)}" y="${H - 8}" text-anchor="middle" font-size="9.5" font-family="JetBrains Mono, monospace" fill="#6B7280">${b.label}</text>` : '').join('');
  const dots = vals.map((v, i) => v ? `<circle cx="${x(i).toFixed(1)}" cy="${y(v).toFixed(1)}" r="2.6" fill="#8B2E3C"><title>${b(buckets[i].label)}: ${opts.money ? fmt(v) : v}</title></circle>` : '').join('');
  const empty = vals.every(v => !v);
  return `<div class="dchart">
    <svg viewBox="0 0 ${W} ${H}" role="img" aria-label="${escapeHtml(opts.label || 'Trend over the last 30 days')}">
      ${grid}
      <polygon points="${area}" fill="rgba(212,168,60,0.18)"/>
      <polyline points="${line}" fill="none" stroke="#8B2E3C" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
      ${dots}${xl}
      ${empty ? `<text x="${W / 2}" y="${H / 2}" text-anchor="middle" font-size="12" fill="#6B7280">No activity in this period yet</text>` : ''}
    </svg>
  </div>`;
  function b(s){ return escapeHtml(String(s)); }
}
function niceMax(v){
  if (v <= 0) return 1;
  const mag = Math.pow(10, Math.floor(Math.log10(v)));
  const n = v / mag;
  const m = n <= 1 ? 1 : n <= 2 ? 2 : n <= 2.5 ? 2.5 : n <= 5 ? 5 : 10;
  return m * mag;
}
function fmtAxis(v, money){
  if (!money) return String(Math.round(v));
  if (v >= 1e6) return (v / 1e6).toFixed(v % 1e6 ? 1 : 0) + 'M';
  if (v >= 1e3) return Math.round(v / 1e3) + 'k';
  return String(Math.round(v));
}

/* ---------- modal shell (one per dashboard page) ---------- */
function openDashModal(html){
  const box = $('#dashModalBox'), ov = $('#dashModal');
  if (!box || !ov) return;
  box.innerHTML = html;
  ov.classList.add('show');
  ov.onclick = e => { if (e.target === ov) closeDashModal(); };
  const first = box.querySelector('input, select, textarea, button');
  if (first) setTimeout(() => first.focus(), 30);
}
function closeDashModal(){
  const ov = $('#dashModal');
  if (ov){ ov.classList.remove('show'); $('#dashModalBox').innerHTML = ''; }
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDashModal(); });

/* a small confirm dialog; resolves via callback so it works without async plumbing */
function confirmDash({ title, body, confirmLabel = 'Confirm', danger = false, withReason = false, reasonLabel = 'Reason' }, onConfirm){
  openDashModal(`
    <h3>${escapeHtml(title)}</h3>
    <p class="muted" style="font-size:13.5px; line-height:1.6">${body}</p>
    ${withReason ? `<div class="field" style="margin-top:16px"><label>${escapeHtml(reasonLabel)}</label><textarea id="dashReason" rows="3" placeholder="Shown to the person this affects"></textarea></div>` : ''}
    <div class="dmodal-foot">
      <button type="button" class="btn btn-ghost btn-sm" data-modal="cancel">Cancel</button>
      <button type="button" class="btn ${danger ? 'btn-danger' : 'btn-primary'} btn-sm" data-modal="ok">${escapeHtml(confirmLabel)}</button>
    </div>`);
  const box = $('#dashModalBox');
  box.querySelector('[data-modal="cancel"]').onclick = closeDashModal;
  box.querySelector('[data-modal="ok"]').onclick = () => {
    const reason = withReason ? (box.querySelector('#dashReason').value || '').trim() : null;
    if (withReason && !reason){ box.querySelector('#dashReason').focus(); toast('A reason is required'); return; }
    closeDashModal();
    onConfirm(reason);
  };
}

/* ---------- sidebar + mobile tab bar ---------- */
function renderSidebar(items, current, collapsed, counts){
  return `<aside class="dash-side dcard" aria-label="Dashboard sections">
    <button class="dash-collapse" data-act="toggle-side" aria-label="${collapsed ? 'Expand sidebar' : 'Collapse sidebar'}" title="${collapsed ? 'Expand sidebar' : 'Collapse sidebar'}">${collapsed ? '»' : '«'}</button>
    ${items.map(it => it === 'sep' ? '<div class="dash-side-sep"></div>' : sideItem(it, current, counts)).join('')}
  </aside>`;
}
function sideItem(it, current, counts){
  const c = (counts && counts[it.id]) || null;
  return `<button class="dash-side-item${current === it.id ? ' on' : ''}" data-act="view" data-view="${it.id}" title="${escapeHtml(it.label)}" ${current === it.id ? 'aria-current="page"' : ''}>
    <span class="dash-side-ico">${DI[it.icon]}</span>
    <span class="dash-side-label">${escapeHtml(it.label)}</span>
    ${c ? `<span class="dash-side-badge${c.warn ? ' warn' : ''}" title="${escapeHtml(c.title || '')}">${c.n}</span>` : ''}
  </button>`;
}
function renderTabbar(items, current, counts){
  return `<nav class="dash-tabbar" aria-label="Dashboard sections">
    ${items.filter(i => i !== 'sep').map(it => {
      const c = counts && counts[it.id];
      return `<button class="dash-tabbar-item${current === it.id ? ' on' : ''}" data-act="view" data-view="${it.id}">
        <span class="dash-tabbar-ico">${DI[it.icon]}</span><span>${escapeHtml(it.label)}</span>
        ${c ? `<i class="dash-tabbar-dot${c.warn ? ' warn' : ''}"></i>` : ''}
      </button>`;
    }).join('')}
  </nav>`;
}

/* ---------- skeletons ---------- */
function skeletonMain(){
  const card = `<div class="dcard stat"><div class="skeleton" style="height:58px; width:100%; border-radius:var(--r-md)"></div></div>`;
  const row  = `<div class="skeleton" style="height:96px; border-radius:var(--r-md)"></div>`;
  return `<div class="stat-grid">${card + card + card + card}</div>
    <div class="dcard dash-panel" style="display:flex; flex-direction:column; gap:12px">${row + row + row}</div>`;
}

/* ---------- product thumb for tables ---------- */
function smallThumb(p){
  const src = (p.images && p.images[0]) || p.image || '';
  return `<span class="pthumb-sm">${src
    ? `<img src="${escapeHtml(src)}" alt="" onerror="this.outerHTML = window.iconMarkup('${escapeHtml(p.icon || 'laptop')}', 26)">`
    : icon(p.icon || 'laptop', 26)}</span>`;
}

/* ---------- csv (real, tiny) ----------
   Builds a CSV string and hands it to the browser as a download. Works
   in a normal tab; some embedded viewers block script-started downloads,
   in which case the toast says so. */
function downloadCsv(filename, rows){
  const esc = v => { const s = String(v == null ? '' : v); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s; };
  const csv = rows.map(r => r.map(esc).join(',')).join('\n');
  try {
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob); a.download = filename;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 2000);
    toast(`Exported ${rows.length - 1} rows to ${filename}`);
  } catch(e){
    toast('Export blocked by the browser — copy the table instead');
  }
}
