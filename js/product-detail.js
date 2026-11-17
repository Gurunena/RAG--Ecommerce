/* ============================================================
   product-detail.js — product-detail.html?id=p1
   Reads the product id from the query string via URLSearchParams
   and renders it through the same AJAX endpoint the rest of the
   site uses (API.getProduct).

   Adds a thumbnail gallery, a trust row and a reviews section.
   Everything degrades: one photo → one thumbnail, no reviewsList
   → the summary still renders off `rating` / `reviews`.

   The product comes from api/products/detail.php, which also
   returns `review_permission` — whether this viewer may write a
   review, and why not if they cannot. The form is only offered when
   the answer is yes, and api/reviews/create.php checks the same rule
   again, so the button being there is a convenience, never the
   control.
   ============================================================ */

let CURRENT_PRODUCT = null;
let REVIEWS_EXPANDED = false;

const REVIEWS_VISIBLE = 3;   // before "Show more reviews"

/* ---------- small helpers ---------- */
function starsFor(rating, cls){
  const filled = Math.round(rating || 0);
  return `<span class="${cls || 'stars-row'}">` +
    Array.from({length:5}, (_,i) =>
      `<span class="star${i < filled ? ' on' : ''}">★</span>`).join('') +
    `</span>`;
}

function reviewDate(iso){
  const d = new Date(iso);
  if (isNaN(d)) return iso || '';
  return d.toLocaleDateString('en-LK', { year:'numeric', month:'short', day:'numeric' });
}

function initials(name){
  return name.trim().split(/\s+/).slice(0,2).map(w => w[0].toUpperCase()).join('');
}

/* When a product has no per-star data, shape a plausible curve around its
   average so the chart still means something instead of sitting empty. */
function deriveBreakdown(avg){
  const a = avg || 0;
  if (a >= 4.5) return { 5:72, 4:20, 3:5, 2:2, 1:1 };
  if (a >= 4.2) return { 5:60, 4:26, 3:9, 2:3, 1:2 };
  if (a >= 3.8) return { 5:45, 4:30, 3:15, 2:6, 1:4 };
  return { 5:30, 4:28, 3:22, 2:12, 1:8 };
}

/* ---------- gallery ---------- */
function galleryHTML(p){
  const imgs = (p.images && p.images.length) ? p.images : (p.image ? [p.image] : []);

  if (!imgs.length){
    return `<div class="pd-visual"><div class="muted" style="font-size:13px">No photo yet</div></div>`;
  }

  const thumbs = imgs.map((src, i) => `
    <button type="button" class="pd-thumb${i === 0 ? ' active' : ''}"
            data-i="${i}" aria-label="View image ${i + 1} of ${imgs.length}">
      <img src="${src}" alt="" loading="lazy" onerror="this.style.display='none'">
    </button>`).join('');

  return `
    <div class="pd-gallery">
      <div class="pd-visual" id="pdVisual">
        <img id="pdMainImg" src="${imgs[0]}" alt="${escapeHtml(p.title)}">
      </div>
      <div class="pd-thumbs" id="pdThumbs">${thumbs}</div>
    </div>`;
}

function wireGallery(p){
  const imgs = (p.images && p.images.length) ? p.images : (p.image ? [p.image] : []);
  const main = $('#pdMainImg');
  const strip = $('#pdThumbs');
  if (!main || !strip) return;

  const show = i => {
    if (!imgs[i]) return;
    main.src = imgs[i];
    $$('#pdThumbs .pd-thumb').forEach((b, n) => b.classList.toggle('active', n === i));
  };

  strip.addEventListener('click', e => {
    const b = e.target.closest('.pd-thumb');
    if (b) show(+b.dataset.i);
  });

  // basic swipe on the main image, for phones
  let x0 = null, idx = 0;
  const visual = $('#pdVisual');
  visual.addEventListener('touchstart', e => { x0 = e.changedTouches[0].clientX; }, { passive:true });
  visual.addEventListener('touchend', e => {
    if (x0 === null) return;
    const dx = e.changedTouches[0].clientX - x0;
    if (Math.abs(dx) > 40){
      idx = (idx + (dx < 0 ? 1 : -1) + imgs.length) % imgs.length;
      show(idx);
    }
    x0 = null;
  }, { passive:true });
}

/* ---------- reviews ---------- */
function reviewsHTML(p){
  const list = Array.isArray(p.reviewsList) ? p.reviewsList : [];
  const total = p.reviews || list.length || 0;
  const avg = p.rating || 0;
  const bd = p.ratingBreakdown || deriveBreakdown(avg);

  const bars = [5,4,3,2,1].map(star => {
    const pct = Math.max(0, Math.min(100, Number(bd[star] ?? bd[String(star)] ?? 0)));
    return `
      <div class="bd-row">
        <span class="bd-label mono">${star}★</span>
        <span class="bd-track"><span class="bd-fill" style="width:${pct}%"></span></span>
        <span class="bd-pct mono">${pct}%</span>
      </div>`;
  }).join('');

  const shown = REVIEWS_EXPANDED ? list : list.slice(0, REVIEWS_VISIBLE);

  const cards = shown.map((r, i) => `
    <article class="review">
      <div class="review-head">
        <span class="review-avatar" aria-hidden="true">${initials(r.name)}</span>
        <div class="review-who">
          <strong>${escapeHtml(r.name)}</strong>
          ${r.verified ? '<span class="verified">verified purchase</span>' : ''}
        </div>
        <span class="review-date mono">${reviewDate(r.date)}</span>
      </div>
      ${starsFor(r.rating, 'stars-row sm')}
      <p class="review-text">${escapeHtml(r.text)}</p>
      <button type="button" class="helpful-btn" data-i="${i}">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M7 21V10l5-7 1.5 1.2a2 2 0 01.6 2.1L13 10h5.5a2 2 0 012 2.4l-1.4 7A2 2 0 0117.1 21H7z"
                stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
        </svg>
        Helpful <span class="helpful-n">${r.helpful || 0}</span>
      </button>
    </article>`).join('');

  const moreBtn = list.length > REVIEWS_VISIBLE ? `
    <button type="button" class="btn btn-ghost review-more" id="reviewMore">
      ${REVIEWS_EXPANDED ? 'Show fewer reviews' : `Show all ${list.length} reviews`}
    </button>` : '';

  const empty = `
    <div class="review-empty">
      <p class="muted">No written reviews for this one yet — the ${total} ratings above came in without comments.</p>
    </div>`;

  /* The button says what the server says. Someone who cannot review
     sees the reason instead of a button that fails when pressed. */
  const perm = p.review_permission
    || { can_review: false, reason: 'signed_out', message: 'Sign in to write a review' };
  const action = perm.can_review
    ? `<button type="button" class="btn btn-primary" id="writeReview">Write a review</button>`
    : perm.reason === 'signed_out'
      ? `<button type="button" class="btn btn-primary" id="writeReview">Sign in to review</button>`
      : `<span class="muted" style="font-size:12.5px; max-width:240px; text-align:right">${escapeHtml(perm.message)}</span>`;

  return `
    <section class="pd-reviews" id="pdReviews">
      <div class="reviews-head">
        <h2 class="serif">Customer reviews</h2>
        ${action}
      </div>

      <div id="reviewFormHost"></div>

      <div class="reviews-summary">
        <div class="rs-score">
          <div class="rs-num">${avg.toFixed(1)}</div>
          ${starsFor(avg)}
          <div class="rs-count muted">${total} rating${total === 1 ? '' : 's'}</div>
        </div>
        <div class="rs-breakdown">${bars}</div>
      </div>

      <div class="review-list">${list.length ? cards : empty}</div>
      ${moreBtn}
    </section>`;
}

function wireReviews(p){
  // helpful counter — UI-only, but it should feel alive
  $$('#pdReviews .helpful-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      if (btn.classList.contains('voted')) return;
      const n = btn.querySelector('.helpful-n');
      n.textContent = (parseInt(n.textContent, 10) || 0) + 1;
      btn.classList.add('voted');
    });
  });

  const more = $('#reviewMore');
  if (more){
    more.addEventListener('click', () => {
      REVIEWS_EXPANDED = !REVIEWS_EXPANDED;
      const host = $('#pdReviews');
      host.outerHTML = reviewsHTML(p);
      wireReviews(p);
      $('#pdReviews').scrollIntoView({ block:'nearest', behavior:'smooth' });
    });
  }

  const write = $('#writeReview');
  if (write){
    write.addEventListener('click', () => {
      // same session gate the rest of the site uses
      if (!Session.isLoggedIn){
        toast('Sign in to write a review');
        setTimeout(() => {
          location.href = 'login.html?return=' + encodeURIComponent(
            'product-detail.html?id=' + p.id);
        }, 900);
        return;
      }
      openReviewForm(p);
    });
  }
}

/* ---------- the review form ----------
   Rendered into the reviews section rather than a modal, reusing the
   existing .field / .btn / .review classes so it needs no new styling
   and nothing about the page layout changes. */
let REVIEW_RATING = 0;

function reviewStarPicker(){
  return [1, 2, 3, 4, 5].map(n =>
    `<button type="button" class="star${n <= REVIEW_RATING ? ' on' : ''}" data-star="${n}"
             aria-label="${n} star${n === 1 ? '' : 's'}"
             style="background:none; border:0; cursor:pointer; font-size:26px; padding:0 2px">★</button>`
  ).join('');
}

function openReviewForm(p){
  const host = $('#reviewFormHost');
  if (!host) return;

  if (host.dataset.open === '1'){        // pressing it again closes the form
    host.innerHTML = '';
    host.dataset.open = '0';
    return;
  }
  host.dataset.open = '1';
  REVIEW_RATING = 0;

  host.innerHTML = `
    <form class="review" id="reviewForm" style="margin-bottom:18px">
      <div class="field">
        <label>Your rating</label>
        <div id="rvStars" class="stars-row">${reviewStarPicker()}</div>
        <div class="field-error" id="rvRating-err"></div>
      </div>
      <div class="field">
        <label for="rvText">Your review <span class="muted">(optional)</span></label>
        <textarea id="rvText" rows="4" placeholder="What has it actually been like to own?"></textarea>
      </div>
      <div style="display:flex; gap:10px">
        <button type="submit" class="btn btn-primary" id="rvSubmit">Post review</button>
        <button type="button" class="btn btn-ghost" id="rvCancel">Cancel</button>
      </div>
    </form>`;

  const wireStars = () => {
    $$('#rvStars [data-star]').forEach(b => b.addEventListener('click', () => {
      REVIEW_RATING = +b.dataset.star;
      const err = $('#rvRating-err');
      if (err){ err.textContent = ''; err.classList.remove('show'); }
      $('#rvStars').innerHTML = reviewStarPicker();
      wireStars();
    }));
  };
  wireStars();

  $('#rvCancel').addEventListener('click', () => { host.innerHTML = ''; host.dataset.open = '0'; });
  $('#reviewForm').addEventListener('submit', e => { e.preventDefault(); submitReview(p); });
  host.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

function submitReview(p){
  const err = $('#rvRating-err');
  if (!REVIEW_RATING){
    if (err){ err.textContent = 'Choose a rating from 1 to 5 stars'; err.classList.add('show'); }
    toast('Pick a star rating first');
    return;
  }

  const text = $('#rvText').value.trim();
  // Mirrors the server rule: empty is fine, a two-word stub is not.
  if (text && text.length < 10){
    toast('Either leave the comment empty or write at least 10 characters');
    return;
  }

  const btn = $('#rvSubmit');
  btn.disabled = true;
  btn.textContent = 'Posting…';

  API.Reviews.create(p.id, REVIEW_RATING, text)
    .then(() => {
      toast('Thanks — your review is live');
      // The rating, the review count and the breakdown have all moved,
      // so re-read the product instead of patching what is on screen.
      API.invalidateCatalog();
      REVIEWS_EXPANDED = false;
      renderProduct(p.id);
    })
    .catch(e => {
      btn.disabled = false;
      btn.textContent = 'Post review';
      if (e.fields && e.fields.rating && err){
        err.textContent = e.fields.rating;
        err.classList.add('show');
      }
      toast(e.message);
    });
}

/* ---------- trust row ---------- */
function trustRowHTML(p){
  const items = [
    ['shield', 'Secure payment', 'Held until delivered'],
    ['truck',  'Fast delivery',  'Island-wide, tracked'],
    ['return', 'Easy returns',   '7-day window'],
    ['check',  'Verified seller', escapeHtml(sellerOf(p).name)]
  ];
  const icons = {
    shield:'<rect x="4" y="10" width="16" height="10" rx="2.5" stroke="#1B2A4A" stroke-width="1.6"/><path d="M8 10V7.5a4 4 0 018 0V10" stroke="#8B2E3C" stroke-width="1.6" stroke-linecap="round"/>',
    truck:'<path d="M2 7h11v9H2z" stroke="#1B2A4A" stroke-width="1.5" stroke-linejoin="round"/><path d="M13 10h4l3 3v3h-7z" stroke="#8B2E3C" stroke-width="1.5" stroke-linejoin="round"/><circle cx="6.5" cy="18" r="1.4" fill="#D4A83C"/><circle cx="17" cy="18" r="1.4" fill="#D4A83C"/>',
    return:'<path d="M4 12a8 8 0 0114-5.3M20 12a8 8 0 01-14 5.3" stroke="#1B2A4A" stroke-width="1.6" stroke-linecap="round"/><path d="M18 3v4h-4M6 21v-4h4" stroke="#8B2E3C" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>',
    check:'<circle cx="12" cy="12" r="9" stroke="#1B2A4A" stroke-width="1.5"/><path d="M8.5 12.2l2.3 2.3 4.7-4.9" stroke="#8B2E3C" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>'
  };
  return `
    <div class="pd-trust">
      ${items.map(([k, title, sub]) => `
        <div class="pdt-item">
          <svg width="19" height="19" viewBox="0 0 24 24" fill="none">${icons[k]}</svg>
          <div><strong>${title}</strong><em>${sub}</em></div>
        </div>`).join('')}
    </div>`;
}

/* ---------- page ---------- */
function renderProduct(id){
  const box = $('#productBody');
  box.innerHTML = `<div class="pd"><div class="skeleton" style="height:320px"></div>
    <div><div class="skeleton" style="height:28px;width:70%;margin-bottom:12px"></div>
    <div class="skeleton" style="height:16px;width:45%"></div></div></div>`;

  API.getProduct(id).then(p => {
    if (!p) {
      box.innerHTML = `<p class="muted" style="padding:40px 0">Product not found. <a href="products.html">Back to the catalogue</a>.</p>`;
      return;
    }
    CURRENT_PRODUCT = p;
    document.title = p.title + ' — Consult';

    // specs is always an object from PHP, but escape both sides anyway:
    // this is seller-entered text going straight into HTML.
    const rows = Object.entries(p.specs || {})
      .map(([k,v]) => `<tr><td>${escapeHtml(k)}</td><td>${escapeHtml(v)}</td></tr>`).join('');

    box.innerHTML = `<div class="pd">
      <div>
        ${galleryHTML(p)}
      </div>
      <div>
        ${p.badge ? `<span class="badge${p.badge==='deal'?' alt':''}">${escapeHtml(p.badge)}</span>` : ''}
        <h1>${escapeHtml(p.title)}</h1>
        <p class="muted" style="font-size:14px">${escapeHtml(p.subtitle || '')}</p>
        <div class="pd-price">${fmt(p.price)}
          ${p.oldPrice ? `<span class="pold" style="font-size:15px">${fmt(p.oldPrice)}</span>` : ''}</div>
        ${p.onSale ? `<div class="pmeta" style="color:var(--maroon); margin-top:-4px">Sale price — ends ${escapeHtml(String(p.saleEndsAt || ''))}</div>` : ''}
        ${p.why ? `<div class="why"><b>Why we'd recommend it:</b> ${escapeHtml(p.why)}</div>` : ''}
        <table class="spec-table">${rows}</table>
        <div class="seller-line" style="margin-bottom:16px">
          Sold by <b>${escapeHtml(sellerOf(p).name)}</b>${sellerOf(p).city ? ', ' + escapeHtml(sellerOf(p).city) : ''}
          ${sellerOf(p).verified ? '· <span class="verified">verified seller</span>' : ''}
          · ★ ${p.rating} (${p.reviews} reviews) · ${p.stock > 0 ? p.stock + ' in stock' : '<b>out of stock</b>'}
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap">
          <button class="btn btn-primary" onclick="quickAdd('${p.id}')"${p.stock > 0 ? '' : ' disabled'}>${p.stock > 0 ? 'Add to cart' : 'Out of stock'}</button>
          <button class="btn btn-ghost" onclick="location.href='index.html#chat'">Ask about this</button>
        </div>
      </div>
    </div>
    ${trustRowHTML(p)}
    ${reviewsHTML(p)}`;

    wireGallery(p);
    wireReviews(p);
  }).catch(err => {
    console.error('Product detail failed:', err);
    box.innerHTML = `
      <div class="render-error">
        <p class="serif" style="font-size:17px; margin-bottom:6px">Couldn't load this product</p>
        <p class="muted" style="margin-bottom:16px">${escapeHtml(err.message)}</p>
        <button type="button" class="btn btn-dark" onclick="location.reload()">Try again</button>
        <a class="btn btn-ghost" href="products.html" style="margin-left:8px">Back to the catalogue</a>
      </div>`;
  });
}

/* The nested seller object is always sent by the API, but the
   data/products.json fallback or an older cached record may not have
   one. One accessor, so no render line has to guard it — a missing
   seller used to blank this page with no error at all. */
function sellerOf(p){
  return (p && p.seller) || { name: 'Unknown seller', city: '', verified: false };
}

document.addEventListener('DOMContentLoaded', () => {
  const id = new URLSearchParams(location.search).get('id');
  if (!id){
    $('#productBody').innerHTML = `<p class="muted" style="padding:40px 0">No product specified. <a href="products.html">Back to the catalogue</a>.</p>`;
    return;
  }
  renderProduct(id);
});
