function showSection(id){
  $$('#page-home, #page-chat').forEach(p => p.classList.remove('active'));
  $('#' + id).classList.add('active');
  document.body.classList.toggle('is-chat', id === 'page-chat');
  setNavH();
}

function showChat(){ 
  showSection('page-chat'); 
}

function showHero(){
   showSection('page-home'); 
}

window.addEventListener('hashchange', () => {
  showSection(location.hash === '#chat' ? 'page-chat' : 'page-home');
});

function initHome(){
  initShowroom();

  API.getTrending().then(list => {
    // the strongest pick gets a larger card; the rest ride the rail
    const [hero, ...rest] = list;
    if (hero) $('#trendFeature').innerHTML = featureCard(hero);
    $('#trendTrack').innerHTML = rest.map(p => productCard(p)).join('');
    startTrendAutoScroll();
    observeReveals();
  });

  initDiscover();
  initFeaturePanel();
  runTracker();
  initReveals();
  initFilterIndicator();

  $('#heroForm').addEventListener('submit', e => {
    e.preventDefault();
    const v = $('#heroInput').value.trim();
    if (!v) return;
    $('#heroInput').value = '';
    startChat(v);
  });

  $$('#heroChips .chip').forEach(c => {
    c.onclick = () => startChat(c.textContent);
  });

  $('#voiceSearchBtn')?.addEventListener('click', () => toast('Voice search — coming soon'));
  $('#imageSearchBtn')?.addEventListener('click', () => toast('Search by image — coming soon'));
  $('#aiAdviceBtn')?.addEventListener('click', () => {
    const v = $('#heroInput').value.trim();
    if (v){ $('#heroInput').value = ''; startChat(v); }
    else showChat();
  });

  if (location.hash === '#chat') showChat();
}

/* ============================================================
   SHOWROOM — the hero's AI product carousel.
   Pulls real products from the same API.getProducts() call every
   other section uses (so swapping in a real backend later is a
   one-line change, same as the rest of the site), then drives a
   podium/glass-tile crossfade between them: enter, settle into an
   idle float, leave. Dots jump directly; hovering the stage or
   focusing a dot pauses the rotation; both resume a few seconds
   after the shopper stops interacting.
   ============================================================ */
let SHOWROOM = { products: [], index: 0, timer: null, hoverPaused: false };

function initShowroom(){
  const stage = $('#showroomStage');
  if (!stage) return;

  API.getProducts().then(list => {
    SHOWROOM.products = pickShowroomProducts(list);
    if (!SHOWROOM.products.length) return;

    renderShowroomDots();
    showShowroomProduct(0, { firstPaint: true });
    armShowroomTimer();
  }).catch(() => { /* no catalogue yet — showroom just stays on its idle glow */ });

  stage.addEventListener('mouseenter', () => pauseShowroom(true));
  stage.addEventListener('mouseleave', () => resumeShowroomSoon());
  stage.addEventListener('focusin', () => pauseShowroom(true));
  stage.addEventListener('focusout', () => resumeShowroomSoon());

  if (!showroomQuiet() && showroomFinePointer()){
    stage.addEventListener('mousemove', onShowroomTilt);
    stage.addEventListener('mouseleave', () => setShowroomTilt(0, 0));
  }
}

/* one product per category, in catalogue order, capped at 5 —
   keeps the rotation varied instead of showing five laptops */
function pickShowroomProducts(list){
  const seen = new Set();
  const picks = [];
  for (const p of list){
    if (seen.has(p.category)) continue;
    seen.add(p.category);
    picks.push(p);
    if (picks.length === 5) break;
  }
  return picks;
}

function showroomQuiet(){
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}
function showroomFinePointer(){
  return window.matchMedia('(pointer: fine)').matches;
}

function renderShowroomDots(){
  const wrap = $('#showroomDots');
  if (!wrap) return;
  wrap.innerHTML = SHOWROOM.products.map((p, i) => `
    <button type="button" class="showroom-dot${i === 0 ? ' on' : ''}"
            role="tab" aria-label="Show ${escapeHtml(p.title)}"
            aria-selected="${i === 0}"
            onclick="jumpShowroom(${i})"></button>
  `).join('');
}

function jumpShowroom(i){
  showShowroomProduct(i);
  pauseShowroom(false);
  resumeShowroomSoon();
}

function armShowroomTimer(){
  clearTimeout(SHOWROOM.timer);
  if (SHOWROOM.hoverPaused) return;
  SHOWROOM.timer = setTimeout(() => {
    showShowroomProduct((SHOWROOM.index + 1) % SHOWROOM.products.length);
    armShowroomTimer();
  }, 4600);
}

function pauseShowroom(on){
  SHOWROOM.hoverPaused = on;
  if (on) clearTimeout(SHOWROOM.timer);
}

let showroomResumeTimer = null;
function resumeShowroomSoon(){
  clearTimeout(showroomResumeTimer);
  showroomResumeTimer = setTimeout(() => {
    SHOWROOM.hoverPaused = false;
    armShowroomTimer();
  }, 1200);
}

function showShowroomProduct(i, opts = {}){
  const p = SHOWROOM.products[i];
  if (!p) return;
  SHOWROOM.index = i;

  const mount = $('#showroomMount');
  const outgoing = mount?.querySelector('.showroom-tile');
  const quiet = showroomQuiet();

  if (outgoing){
    outgoing.classList.remove('is-entering', 'is-settled');
    outgoing.classList.add('is-leaving');
    setTimeout(() => outgoing.remove(), quiet ? 320 : 460);
  }

  const tile = document.createElement('div');
  tile.className = 'showroom-tile is-entering';
  tile.innerHTML = `
    <div class="tile-glass">
      <img src="${escapeHtml(p.image || '')}" alt="${escapeHtml(p.title)}" onerror="this.style.display='none'">
      <div class="tile-scan" aria-hidden="true"></div>
    </div>
    <div class="ai-match-chip"><span class="dot"></span> AI MATCH</div>
  `;
  mount?.appendChild(tile);

  const settleAfter = quiet ? 0 : 650;
  setTimeout(() => {
    tile.classList.remove('is-entering');
    tile.classList.add('is-settled');
  }, settleAfter);

  renderShowroomInfo(p, opts.firstPaint);

  $$('#showroomDots .showroom-dot').forEach((d, n) => {
    d.classList.toggle('on', n === i);
    d.setAttribute('aria-selected', String(n === i));
  });
}

function renderShowroomInfo(p, firstPaint){
  const info = $('#showroomInfo');
  if (!info) return;

  const matchPct = Math.min(99, Math.round((p.rating || 4.5) * 20));

  const paint = () => {
    info.innerHTML = `
      <div class="si-cat">${escapeHtml(p.category)}</div>
      <div class="si-name">${escapeHtml(p.title)}</div>
      <div class="si-row">
        <span class="si-price">${fmt(p.price)}${p.oldPrice ? `<span class="si-old">${fmt(p.oldPrice)}</span>` : ''}</span>
      </div>
      ${starRow(p.rating, p.reviews)}
      <div class="si-match">
        <span class="si-match-lbl">AI Match</span>
        <span class="si-match-val">${matchPct}%</span>
      </div>
    `;
    info.classList.add('on');
  };

  if (firstPaint || showroomQuiet()){ paint(); return; }
  info.classList.remove('on');
  setTimeout(paint, 200);
}

/* subtle cursor-follow tilt on the glass tile — desktop pointer only.
   Moving right/left turns the card around its vertical axis; moving
   up/down turns it around its horizontal axis. Capped at a few
   degrees so it reads as a premium detail, not a gimmick. */
function onShowroomTilt(e){
  const stage = $('#showroomStage');
  const r = stage.getBoundingClientRect();
  const px = (e.clientX - r.left) / r.width - 0.5;   // -0.5 .. 0.5, left->right
  const py = (e.clientY - r.top) / r.height - 0.5;    // -0.5 .. 0.5, top->bottom
  setShowroomTilt(py * 6, px * -6);
}
function setShowroomTilt(rotX, rotY){
  const glass = $('#showroomMount .tile-glass');
  if (glass) glass.style.transform = `rotateX(${rotX}deg) rotateY(${rotY}deg)`;
}

/* ============================================================
   ELEVATION — reveal motion, count-up stats, hero card, filter
   indicator. Transform/opacity only, and every one of them
   stands down for prefers-reduced-motion.
   ============================================================ */
const prefersQuiet = () =>
  window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/* ---------- the larger "best match" card in the trending split ---------- */
function featureCard(p){
  return `
    <article class="fcard" onclick="go('product/${p.id}')">
      <div class="fcard-thumb">
        <img src="${escapeHtml(p.image || '')}" alt="${escapeHtml(p.title)}" onerror="this.style.display='none'">
      </div>
      
      ${p.badge ? `<span class="badge">${escapeHtml(p.badge)}</span>` : ''}
      <h3 class="fcard-title">${escapeHtml(p.title)}</h3>
      <p class="fcard-sub">${escapeHtml(p.subtitle)}</p>
      ${starRow(p.rating, p.reviews)}
      <p class="fcard-why">${escapeHtml(p.why)}</p>
      <div class="fcard-foot">
        <div class="fcard-price">
          ${fmt(p.price)}
          ${p.oldPrice ? `<s>${fmt(p.oldPrice)}</s>` : ''}
        </div>
        <button type="button" class="btn btn-primary"
                onclick="event.stopPropagation(); quickAdd('${p.id}')">Add to cart</button>
      </div>
    </article>`;
}

/* ---------- scroll reveal ---------- */
let revealObserver = null;

function initReveals(){
  // reduced motion, or a browser without IO: show everything, animate nothing
  if (prefersQuiet() || !('IntersectionObserver' in window)){
    $$('.reveal, .reveal-stagger').forEach(el => el.classList.add('in-view'));
    runCountUps();
    return;
  }

  revealObserver = new IntersectionObserver(entries => {
    entries.forEach(e => {
      if (!e.isIntersecting) return;
      e.target.classList.add('in-view');
      if (e.target.id === 'trustStats') runCountUps();
      revealObserver.unobserve(e.target);   // fires once, never re-triggers
    });
  }, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' });

  observeReveals();
}

/* re-callable: catches sections whose cards were rendered after load */
function observeReveals(){
  if (!revealObserver) return;
  $$('.reveal:not(.in-view), .reveal-stagger:not(.in-view)')
    .forEach(el => revealObserver.observe(el));
}

/* ---------- trust stats count up, once ---------- */
let countsDone = false;

function runCountUps(){
  if (countsDone) return;
  countsDone = true;

  $$('#trustStats .trust-num[data-count]').forEach(el => {
    const target = parseInt(el.dataset.count, 10);
    if (!target) return;

    if (prefersQuiet()){
      el.textContent = target.toLocaleString('en-LK');
      return;
    }

    const dur = 1200;
    const start = performance.now();

    (function frame(now){
      const t = Math.min((now - start) / dur, 1);
      const eased = 1 - Math.pow(1 - t, 3);
      el.textContent = Math.round(target * eased).toLocaleString('en-LK');
      if (t < 1) requestAnimationFrame(frame);
    })(start);
  });
}

/* ---------- sliding indicator behind the category pills ---------- */
function initFilterIndicator(){
  const bar = $('#productFilters');
  if (!bar) return;

  if (!bar.querySelector('.filter-indicator')){
    const ind = document.createElement('span');
    ind.className = 'filter-indicator';
    bar.prepend(ind);
  }

  const sync = () => moveFilterIndicator(bar.querySelector('.product-filter.active'));
  sync();
  window.addEventListener('resize', sync);
  // pill widths shift once Inter actually lands
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(sync);
}

function moveFilterIndicator(btn){
  const bar = $('#productFilters');
  if (!bar || !btn) return;
  const ind = bar.querySelector('.filter-indicator');
  if (!ind) return;

  ind.style.width  = btn.offsetWidth + 'px';
  ind.style.height = btn.offsetHeight + 'px';
  ind.style.transform = `translateX(${btn.offsetLeft}px)`;
}

/* ---------- "Discover with AI" row: same catalogue, framed with context badges ---------- */
const DISCOVER_CONTEXTS = ['overall', 'students', 'performance', 'budget'];

function initDiscover(){
  API.getProducts().then(list => {
    const picks = list.slice(0, 4);

   $('#discoverTrack').innerHTML = picks
  .map((p, i) => productCard(p, DISCOVER_CONTEXTS[i % DISCOVER_CONTEXTS.length]))
  .join('');

    observeReveals();
  });
}

function scrollDiscover(dir){
  $('#discoverTrack').scrollBy({ left: dir * 244, behavior: 'smooth' });
}
/* =========================================================
   HOME PRODUCT GRID
   ========================================================= */

let currentProductFilter = "all";

async function renderHomeProducts(filter = "all") {

    const grid = document.getElementById("homeProductGrid");

    if (!grid) return;

    // Loading state
    grid.innerHTML = `
        <div class="product-loading">
            Loading products...
        </div>
    `;

    try {

        // full catalogue, not just the top-6 "trending" slice —
        // this grid should grow as you add more products to products.json
        const allProducts = await API.getProducts();

        let items = allProducts;

        // Filter category
        if (filter !== "all") {
            items = allProducts.filter(
                p => p.category === filter
            );
        }

        // Render cards
        grid.innerHTML = items.map(p => {

            const image = p.image
                ? p.image
                : `image/${p.icon}.png`;

            const oldPrice = p.oldPrice
                ? `
                    <span class="home-product-old-price">
                        ${fmt(p.oldPrice)}
                    </span>
                  `
                : "";

            const badge = p.badge
                ? `
                    <div class="home-product-badge">
                        ${escapeHtml(p.badge)}
                    </div>
                  `
                : "";

            return `
                <article
                    class="home-product-card"
                    onclick="openHomeProduct('${p.id}')"
                >

                    <div class="home-product-image">

                        ${badge}

                        <button
                            class="product-heart"
                            onclick="event.stopPropagation(); toggleWishlist('${p.id}', this)"
                            aria-label="Add to wishlist"
                        >
                            ♡
                        </button>

                        <img
                            src="${image}"
                            alt="${escapeHtml(p.title)}"
                            loading="lazy"
                            onerror="
                                this.style.display='none';
                                this.parentElement.classList.add('image-failed')
                            "
                        >

                    </div>

                    <div class="home-product-info">

                        <div class="home-product-category">
                            ${escapeHtml(p.category || "")}
                        </div>

                        <div class="home-product-title">
                            ${escapeHtml(p.title)}
                        </div>

                        <div class="home-product-subtitle">
                            ${escapeHtml(p.subtitle || "")}
                        </div>

                        <div class="home-product-rating">

                            <span class="stars">
                                ★★★★★
                            </span>

                            <span>
                                ${p.rating || 0}
                                (${p.reviews || 0})
                            </span>

                        </div>

                        <div class="home-product-price">
                            ${fmt(p.price)}
                            ${oldPrice}
                        </div>

                        <div class="home-product-ai">

                            <span>✦</span>

                            <span>
                                <strong>AI pick:</strong>
                                ${escapeHtml(
                                    p.why
                                        ? p.why.substring(0, 75) + "..."
                                        : "Good match for your needs."
                                )}
                            </span>

                        </div>

                        <button
                            class="home-product-action"
                            onclick="
                                event.stopPropagation();
                                askAboutProduct('${p.id}')
                            "
                         >
                            <a href="index.html#chat"> Ask Consult →</a>
                        </button>

                    </div>

                </article>
            `;

        }).join("");

        // the grid is a stagger group; re-arm it for the freshly built cards
        grid.classList.remove("in-view");
        observeReveals();

    } catch (error) {

        console.error(
            "Home product grid error:",
            error
        );

        grid.innerHTML = `
            <div class="product-error">
                Unable to load products.
            </div>
        `;
    }
}

function filterProducts(category, button) {

  document
    .querySelectorAll(".product-filter")
    .forEach(btn => {
      btn.classList.remove("active");
    });

  button.classList.add("active");
  moveFilterIndicator(button);

  renderHomeProducts(category);
}

function askAboutProduct(productId) {

  const product = products.find(
    p => p.id === productId
  );

  if (!product) return;

  const message =
    `Tell me about the ${product.title}. ` +
    `Is it a good choice and why?`;

  startChat(message);
}


function openHomeProduct(productId) {

  const product = products.find(
    p => p.id === productId
  );

  if (!product) return;

  // If you already have a product details function,
  // replace this with your existing function.
  askAboutProduct(productId);
}


function toggleWishlist(productId, button) {

  button.classList.toggle("active");

  button.textContent =
    button.classList.contains("active")
      ? "♥"
      : "♡";
}


function showAllProducts() {

  const section =
    document.querySelector(".home-products");

  if (section) {

    section.scrollIntoView({
      behavior: "smooth",
      block: "start"
    });

  }

}
/* NOTE: productCard() lives in common.js — it already renders the star row,
   the add-to-cart button and the AI context badges (Best Overall / Best for
   Students / …). A second copy used to be redefined here, which shadowed it
   and silently disabled all three. */

/* ============================================================
   FEATURE PANEL SCENES
   Each slide's media box holds a small looping vignette that acts
   out the mechanic the copy claims. Structure is inline SVG, motion
   is CSS keyframes (transform/opacity, plus stroke-dashoffset for
   the drawn ticks). The moving "items" are the real catalogue
   photographs, cropped into rounded squares — the same shots used
   in Trending / Popular products, so the panel matches the site.
   ============================================================ */
const SCENE_PHOTOS = {
  laptop:     'images/thinkpad-e14.webp',
  phone:      'images/galaxy-a55.webp',
  headphones: 'images/sony-wh-ch520.webp'
};

/* a photo cropped into a rounded square, on a small white card */
function scenePhoto(kind, cx, cy, size, clipId, cls){
  const s = size, h = s / 2, pad = 9;
  return `
    <g class="${cls}">
      <rect class="sc-card" x="${cx - h - pad}" y="${cy - h - pad}"
            width="${s + pad * 2}" height="${s + pad * 2}" rx="${pad + 6}"/>
      <clipPath id="${clipId}">
        <rect x="${cx - h}" y="${cy - h}" width="${s}" height="${s}" rx="10"/>
      </clipPath>
      <image href="${SCENE_PHOTOS[kind]}" x="${cx - h}" y="${cy - h}"
             width="${s}" height="${s}" preserveAspectRatio="xMidYMid slice"
             clip-path="url(#${clipId})"/>
    </g>`;
}

/* --- scene 1: specs verified against seller-declared data --- */
function sceneSpecs(){
  const rows = ['16GB DDR4', '512GB NVMe SSD', '10 hr battery', '3-year local warranty'];
  return `
  <g class="sc sc-specs">
    ${scenePhoto('laptop', 132, 196, 76, 'clipSpec', 'sc-still')}
    <rect class="sc-panel" x="228" y="104" width="400" height="188" rx="18"/>
    <text class="sc-kicker" x="252" y="136">SELLER-DECLARED SPECS</text>
    ${rows.map((r, i) => {
      const y = 172 + i * 34;
      return `<text class="sc-txt" x="252" y="${y}">${r}</text>
              <path class="sc-check k${i + 1}" d="M576 ${y - 10} l9 9 l18 -20"/>`;
    }).join('')}
    <g class="sc-why">
      <rect class="sc-whybg" x="228" y="312" width="400" height="62" rx="14"/>
      <rect class="sc-whybar" x="228" y="312" width="4" height="62"/>
      <text class="sc-whytxt" x="250" y="338">Every claim checked against the listing.</text>
      <text class="sc-whydim" x="250" y="360">Nothing scraped. Nothing guessed.</text>
    </g>
  </g>`;
}

/* --- scene 3: payment held in escrow until delivery --- */
function sceneEscrow(){
  return `
  <g class="sc sc-esc">
    <text class="sc-kicker" x="52" y="150">CUSTOMER</text>
    <text class="sc-kicker end" x="648" y="150">SELLER</text>
    <circle class="sc-node" cx="92" cy="220" r="20"/>
    <circle class="sc-node" cx="608" cy="220" r="20"/>

    <g class="sc-vault">
      <rect class="sc-vaultbox" x="292" y="182" width="116" height="98" rx="14"/>
      <path class="sc-shackle" d="M322 182v-22a28 28 0 0 1 56 0v22"/>
      <circle class="sc-fill" cx="350" cy="224" r="11"/>
      <rect class="sc-fill" x="345" y="230" width="10" height="22" rx="5"/>
    </g>

    ${scenePhoto('laptop', 350, 220, 56, 'clipE1', 'sc-in')}
    <g class="sc-out">
      ${scenePhoto('laptop', 350, 220, 56, 'clipE2', 'sc-outitem')}
      <circle class="sc-coin" cx="286" cy="220" r="15"/>
      <text class="sc-coinmark" x="286" y="226">₨</text>
    </g>

    <g class="sc-dots">
      <circle class="sc-dot d1" cx="326" cy="330" r="6"/>
      <circle class="sc-dot d2" cx="350" cy="330" r="6"/>
      <circle class="sc-dot d3" cx="374" cy="330" r="6"/>
    </g>
    <text class="sc-kicker mid" x="350" y="372">HELD IN ESCROW</text>
  </g>`;
}

const PROMOS = [
  { src:'images/promo3.jpeg', fit:'cover',
    title:'Deals this week',           },
  { src:'images/promo1.jpeg', fit:'cover',
    title:'Verified sellers only',     },
  { src:'images/promo2.jpeg', fit:'cover',
    title:'Island-wide delivery',     },
  { src:'images/promo3.jpeg', fit:'cover',
    title:'One cart, every shop',  }
];

const PROMO_MS = 2200;   
let promoIdx = 0, promoTimer = null;

function startPromo(list){
  const track = $('#featPromoTrack');
  const dots  = $('#promoDots');
  if (!track) return;

  track.innerHTML = list.map((p, i) => `
    <figure class="feat-promo-slide${i === 0 ? ' on' : ''}${p.fit === 'contain' ? ' plain' : ''}">
      <img src="${p.src}" alt="" class="${p.fit || 'cover'}" loading="lazy"
           onerror="this.style.display='none'">
    </figure>`).join('');

  dots.innerHTML = list.map((_, i) =>
    `<button type="button" class="promo-dot${i === 0 ? ' on' : ''}" data-i="${i}"
             aria-label="Promotion ${i + 1}"></button>`).join('');

  dots.onclick = e => {
    const b = e.target.closest('.promo-dot');
    if (b) promoGo(+b.dataset.i, list);
  };

  promoIdx = 0;
  promoGo(0, list);
}

function promoGo(i, list){
  promoIdx = (i + list.length) % list.length;

  $$('#featPromoTrack .feat-promo-slide')
    .forEach((el, n) => el.classList.toggle('on', n === promoIdx));
  $$('#promoDots .promo-dot')
    .forEach((el, n) => el.classList.toggle('on', n === promoIdx));

  // the promo caption rides the existing label overlay
  const p = list[promoIdx];
  $('#featMediaT').textContent = p.title;
  $('#featMediaS').textContent = p.sub;

  clearTimeout(promoTimer);
  if (!featPaused){
    promoTimer = setTimeout(() => promoGo(promoIdx + 1, list), PROMO_MS);
  }
}

function stopPromo(){
  clearTimeout(promoTimer);
  promoTimer = null;
}

/* ---------- dark feature panel carousel ---------- */
const FEATURES = [
  {
    head: 'Real reasoning, not a ranked list of whatever pays the most.',
    icon: `<svg width="20" height="20" viewBox="0 0 24 24" fill="none">
      <path d="M12 3l2.4 6.2 6.6.3-5.2 4.1 1.8 6.4L12 16.4 6.4 20l1.8-6.4L3 9.5l6.6-.3z"
            stroke="#D4A83C" stroke-width="1.5" stroke-linejoin="round"/></svg>`,
    title: 'Grounded in real specs', photo: 'laptop',
    body: "Every recommendation is built from verified seller data — RAM, battery, warranty terms. If a spec isn't confirmed, we say so instead of guessing.",
    promo: [
      { src:'images/promo1.jpeg', fit:'cover',
        title:'ThinkPad E14', sub:'recommended for 7 of 10 student briefs' }
    ]
  },
  {
    head: 'One cart across every shop on the island.',
    icon: `<svg width="20" height="20" viewBox="0 0 24 24" fill="none">
      <path d="M3 5h2l2 10.5h10L19 8H6" stroke="#D4A83C" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
      <circle cx="9" cy="19" r="1.4" fill="#D4A83C"/><circle cx="16" cy="19" r="1.4" fill="#D4A83C"/></svg>`,
    title: 'Pay once, we split it', photo: 'phone',
    body: 'Buy a laptop in Nugegoda and a phone in Kandy in the same order. You pay a single total — we route each part to the right seller automatically.',
    promo: PROMOS
  },
  {
    head: 'Your money stays put until the parcel actually lands.',
    icon: `<svg width="20" height="20" viewBox="0 0 24 24" fill="none">
      <rect x="4" y="10" width="16" height="10" rx="2.5" stroke="#D4A83C" stroke-width="1.5"/>
      <path d="M8 10V7.5a4 4 0 018 0V10" stroke="#D4A83C" stroke-width="1.5" stroke-linecap="round"/></svg>`,
    title: 'Held until delivered', photo: 'laptop',
    body: "We capture payment at checkout but hold it. Sellers are paid only once delivery is confirmed — so a shop can't take your money and vanish.",
    promo: [
      { src:'images/promo2.jpeg', fit:'cover',
        title:'Escrow protection', sub:'released on delivery confirmation' }
    ]
  }
];

let featIdx = 0, featTimer;

function initFeaturePanel(){
  const dots = $('#pdots');
  if (!dots) return;
  dots.innerHTML = FEATURES.map((_,i) =>
    `<div class="pdot${i===0?' on':''}" onclick="featGo(${i})"></div>`).join('');
  featGo(0);
}

function featGo(i){
  featIdx = (i + FEATURES.length) % FEATURES.length;
  const f = FEATURES[featIdx];
  $('#panelHead').textContent  = f.head;
  $('#featIcon').innerHTML     = f.icon;
  $('#featTitle').textContent  = f.title;
  $('#featBody').textContent   = f.body;
  if (!Array.isArray(f.promo)){
    $('#featMediaT').textContent = f.mediaT;
    $('#featMediaS').textContent = f.mediaS;
  }

  const media = $('#featMedia');
  // stop the outgoing scene, swap in the new one, then re-arm. Replacing the
  // markup already restarts the keyframes, so this can be synchronous — no
  // rAF to miss, and the class never lingers on a scene that isn't showing.
  media?.classList.remove('playing');
  stopPromo();

  const isPromo = Array.isArray(f.promo);
  $('#featArt').hidden = isPromo;
  $('#featPromo').hidden = !isPromo;
  media?.classList.toggle('promo-mode', isPromo);

  if (isPromo){
    $('#featArt').innerHTML = '';        // nothing animating behind the photos
    startPromo(f.promo);
  } else {
    $('#featArt').innerHTML = f.art;
    $('#featArt').setAttribute('viewBox','0 0 700 440');
  }
  if (!featPaused) media?.classList.add('playing');

  // the floating card mirrors the product the scene is actually using
  const ft = $('#featThumb');
  if (ft) ft.innerHTML = `<img src="${SCENE_PHOTOS[f.photo]}" alt="">`;

  $$('#pdots .pdot').forEach((d,n) => d.classList.toggle('on', n === featIdx));

  clearTimeout(featTimer);
  if (featPaused){
    stopPromo();                         // freeze the promo photos as well
  } else {
    featTimer = setTimeout(() => featGo(featIdx + 1), 6500);
    const f = FEATURES[featIdx];
    if (Array.isArray(f.promo)) promoGo(promoIdx, f.promo);   // resume rotating
  }
}

function featStep(d){ featGo(featIdx + d); }

/* play/pause gates both the scene's keyframes and the slide auto-advance */
let featPaused = false;

function toggleFeatPlay(){
  featPaused = !featPaused;
  const media = $('#featMedia');
  const btn   = $('#featPlay');

  media?.classList.toggle('playing', !featPaused);
  if (btn){
    btn.textContent = featPaused ? '▶' : '❚❚';
    btn.setAttribute('aria-label', featPaused ? 'Play animation' : 'Pause animation');
    btn.setAttribute('aria-pressed', String(featPaused));
  }

  clearTimeout(featTimer);
  if (!featPaused) featTimer = setTimeout(() => featGo(featIdx + 1), 6500);
}

function scrollTrend(dir){
  stopTrendAutoScroll();
  $('#trendTrack').scrollBy({ left: dir * 244, behavior:'smooth' });
}

/* auto-advance the trending carousel until the shopper touches it */
let trendAutoTimer = null;
function stopTrendAutoScroll(){
  clearInterval(trendAutoTimer);
  trendAutoTimer = null;
}
function startTrendAutoScroll(){
  const track = $('#trendTrack');
  if (!track) return;
  stopTrendAutoScroll();
  trendAutoTimer = setInterval(() => {
    const atEnd = track.scrollLeft + track.clientWidth >= track.scrollWidth - 4;
    track.scrollTo({ left: atEnd ? 0 : track.scrollLeft + 244, behavior:'smooth' });
  }, 3400);
  ['pointerdown','wheel','touchstart'].forEach(evt =>
    track.addEventListener(evt, stopTrendAutoScroll, { passive:true, once:true }));
}

/* delivery tracker animation */
function runTracker(){
  const pts  = $$('#tracker .t-point');
  const fill = $('#tFill');
  const sc   = $('#scooter');
  const pct  = [0, 33, 66, 100];
  let stage = 0;

  const width = () => $('#tracker').offsetWidth - 60;

  function step(){
    pts.forEach((p,i) => p.classList.toggle('on', i <= stage));
    fill.style.width = pct[stage] + '%';
    sc.style.left = (12 + width() * pct[stage]/100) + 'px';
    sc.classList.toggle('moving', stage > 0 && stage < 3);
    stage++;
    if (stage > 3){
      setTimeout(() => {
        fill.style.transition = sc.style.transition = 'none';
        fill.style.width = '0%'; sc.style.left = '12px';
        pts.forEach(p => p.classList.remove('on'));
        requestAnimationFrame(() => {
          fill.style.transition = 'width 2.4s cubic-bezier(.4,0,.2,1)';
          sc.style.transition   = 'left 2.4s cubic-bezier(.4,0,.2,1)';
        });
        stage = 0;
      }, 2600);
    }
  }
  step();
  setInterval(step, 2900);
}

/* ============================================================
   CHAT — the core experience
   ============================================================ */
let chatCtx = {};

function startChat(message){
  showChat();
  location.hash = 'chat';
  setTimeout(() => sendMessage(message), 120);
}

function scrollChatToBottom(behavior = 'smooth'){
  const scroll = $('#chatScroll');
  if (!scroll) return;
  requestAnimationFrame(() => {
    requestAnimationFrame(() => {
      scroll.scrollTo({
        top: scroll.scrollHeight,
        behavior
      });
    });
  });
}

function chatAppend(html){
  const f = $('#feed');
  if (!f) return;
  f.insertAdjacentHTML('beforeend', html);
  scrollChatToBottom();
}

function sendMessage(text){
  if (!text.trim()) return;
  document.getElementById('chatWelcome')?.classList.add('hidden');
  chatAppend(`<div class="msg-user">${escapeHtml(text)}</div>`);
  scrollChatToBottom();

  const tid = 'typing-' + Date.now();
  chatAppend(`<div class="msg-ai" id="${tid}">
    <div class="ai-mark">C</div>
    <div class="ai-body"><div class="typing"><i></i><i></i><i></i></div></div>
  </div>`);

  API.consult(text, chatCtx).then(res => {
    chatCtx = res.context || chatCtx;
    document.getElementById(tid)?.remove();

    chatAppend(`<div class="msg-ai">
      <div class="ai-mark">C</div>
      <div class="ai-body">${res.message}</div>
    </div>`);

    if (res.products) {
      chatAppend(`<div class="rec-list">${res.products.map((p,i) => recCard(p, i===0, i)).join('')}</div>`);
    }
    if (res.chips) {
      chatAppend(`<div class="chips" style="justify-content:flex-start; margin-left:38px;">
        ${res.chips.map(c => `<div class="chip" onclick="quick(this)">${c}</div>`).join('')}</div>`);
    }
    scrollChatToBottom();
  }).catch(err => {
    document.getElementById(tid)?.remove();

    /* API.consult() only rejects when the message itself was refused — a
       question that is empty or too long. Everything else (service down,
       timed out, a bad reply) already resolved through the local fallback,
       so reaching here means there IS something specific worth saying. */
    const why = (err && err.message)
      ? escapeHtml(err.message)
      : 'Something went wrong reaching the catalogue. Try again in a moment.';

    chatAppend(`<div class="msg-ai"><div class="ai-mark">C</div>
      <div class="ai-body">${why}</div></div>`);
    scrollChatToBottom();
  });
}

function recCard(p, top, i){
  const specs = Object.entries(p.specs || {}).slice(0,4)
    .map(([k,v]) => `<span class="spec">${v}</span>`).join('');

  const image = p.image
    ? `<img src="${escapeHtml(p.image || '')}" alt="${escapeHtml(p.title)}" class="rec-product-image">`
    : icon(p.icon, 44);

  return `
    <article class="rec-card ${top ? 'is-top' : ''}">
      <div class="rec-thumb">${image}</div>
      <div class="rec-main">
        <div class="rec-top">
          ${top ? '<span class="rec-badge">best match</span>' : '<span></span>'}
          <span class="rec-rank">#${i + 1}</span>
        </div>
        <h3>${escapeHtml(p.title)}</h3>
        <p class="rec-subtitle">${escapeHtml(p.subtitle)}</p>
        <div class="rec-price">${fmt(p.price)}</div>
        <div class="specs">${specs}</div>
        <p class="rec-why"><strong>Why this one:</strong> ${escapeHtml(p.why)}</p>
        <div class="rec-seller">Sold by ${escapeHtml(p.seller.name)}, ${escapeHtml(p.seller.city)}
          ${p.seller.verified ? ' · verified' : ''} · ★ ${p.rating} (${p.reviews})
        </div>
        <div class="rec-actions">
          <button type="button" class="btn btn-primary" onclick="addToCart('${p.id}')">Add to cart</button>
          <button type="button" class="btn btn-ghost" onclick="location.href='product-detail.html?id=${p.id}'">Full specs</button>
        </div>
      </div>
    </article>`;
}

function quick(el){ sendMessage(el.textContent); }

function setConsultQuestion(question){
  const input = $('#chatInput');
  if (!input) return;
  input.value = question;
  input.focus();
}

function newConsultChat(){
  chatCtx = {};
  const feed = $('#feed');
  if (feed) feed.innerHTML = '';
  document.getElementById('chatWelcome')?.classList.remove('hidden');
  const input = $('#chatInput');
  if (input) {
    input.value = '';
    input.focus();
  }
  const scroll = $('#chatScroll');
  if (scroll) scroll.scrollTo({top: 0, behavior: 'smooth'});
}

function initConsultProductGrid(){
  const grid = $('#chatProductGrid');
  if (!grid || typeof API === 'undefined') return;

  API.getTrending().then(products => {
    grid.innerHTML = products.slice(0, 4).map(p => `
      <button type="button" class="chat-product-card" onclick="startChat('Tell me about ${escapeHtml(p.title)}')">
        <span class="chat-product-image">
          ${p.image
            ? `<img src="${escapeHtml(p.image || '')}" alt="${escapeHtml(p.title)}">`
            : icon(p.icon, 38)}
        </span>
        <span class="chat-product-info">
          <strong>${escapeHtml(p.title)}</strong>
          <small>${escapeHtml(p.subtitle)}</small>
          <b>${fmt(p.price)}</b>
        </span>
      </button>
    `).join('');
  }).catch(() => {});
}

function initChat(){
  $('#chatForm').addEventListener('submit', e => {
    e.preventDefault();
    const v = $('#chatInput').value.trim();
    if (!v) return;
    $('#chatInput').value = '';
    sendMessage(v);
  });
}

document.addEventListener('DOMContentLoaded', () => {
  initHome();
  initChat();
  initConsultProductGrid();
  renderHomeProducts();
});
