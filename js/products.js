/* ============================================================
   products.js — products.html: instant search + a sidebar of
   filters (category / price / rating / stock) and sorting, all
   client-side over the AJAX-loaded product list.

   Every filter combines with AND. The grid re-renders live; only
   the mobile drawer has an explicit "Apply", and that just closes
   the drawer — the filtering already happened as you touched it.
   ============================================================ */

let ALL_PRODUCTS = [];

const FILTERS = {
  search: '',
  cats: new Set(),
  min: null,
  max: null,
  rating: 0,
  inStock: false,
  sort: 'relevance'
};

function initProducts(){
  API.getProducts().then(list => {
    ALL_PRODUCTS = list;
    buildCategoryList(list);
    applyFilters();
  }).catch(() => {
    $('#productGrid').innerHTML =
      `<p class="muted" style="padding:40px 0">Couldn't load the catalogue. Try again in a moment.</p>`;
    $('#resultCount').textContent = '';
  });

  /* ---- search: debounced so we re-render once the typing settles ---- */
  let debounce;
  $('#fSearch').addEventListener('input', e => {
    const v = e.target.value;
    $('#searchClear').hidden = !v;
    clearTimeout(debounce);
    debounce = setTimeout(() => {
      FILTERS.search = v.trim().toLowerCase();
      applyFilters();
    }, 250);
  });

  $('#searchClear').addEventListener('click', () => {
    $('#fSearch').value = '';
    $('#searchClear').hidden = true;
    FILTERS.search = '';
    applyFilters();
    $('#fSearch').focus();
  });

  /* ---- price ---- */
  ['#fMinPrice', '#fMaxPrice'].forEach(sel => {
    $(sel).addEventListener('input', () => {
      const n = parseFloat($(sel).value);
      FILTERS[sel === '#fMinPrice' ? 'min' : 'max'] = isNaN(n) ? null : n;
      applyFilters();
    });
  });

  /* ---- rating ---- */
  $$('input[name="fRating"]').forEach(r => {
    r.addEventListener('change', () => {
      FILTERS.rating = parseFloat(r.value) || 0;
      applyFilters();
    });
  });

  /* ---- stock ---- */
  $('#fInStock').addEventListener('change', e => {
    FILTERS.inStock = e.target.checked;
    applyFilters();
  });

  /* ---- sort ---- */
  $('#fSort').addEventListener('change', e => {
    FILTERS.sort = e.target.value;
    applyFilters();
  });

  /* ---- mobile drawer ---- */
  $('#filtersToggle').addEventListener('click', openFilterDrawer);
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeFilterDrawer();
  });
}

/* ---------- category checkboxes, built from whatever's in the catalogue ---------- */
/* "audio" doesn't take an -s; anything new falls back to a plain plural */
const CAT_LABELS = {
  laptop:'Laptops', phone:'Phones', audio:'Audio',
  camera:'Cameras', appliance:'Appliances'
};
const catLabel = c =>
  CAT_LABELS[c] || (c.charAt(0).toUpperCase() + c.slice(1) + 's');

function buildCategoryList(list){
  const cats = [...new Set(list.map(p => p.category))].sort();
  $('#catList').innerHTML = cats.map(c => `
    <label class="check-row">
      <input type="checkbox" value="${c}" data-cat>
      <span>${catLabel(c)}</span>
      <em>${list.filter(p => p.category === c).length}</em>
    </label>`).join('');

  $$('#catList input[data-cat]').forEach(box => {
    box.addEventListener('change', () => {
      if (box.checked) FILTERS.cats.add(box.value);
      else FILTERS.cats.delete(box.value);
      applyFilters();
    });
  });
}

/* ---------- the one place filtering happens ---------- */
function applyFilters(){
  const q = FILTERS.search;

  let list = ALL_PRODUCTS.filter(p => {
    if (FILTERS.cats.size && !FILTERS.cats.has(p.category)) return false;
    if (FILTERS.min !== null && p.price < FILTERS.min) return false;
    if (FILTERS.max !== null && FILTERS.max > 0 && p.price > FILTERS.max) return false;
    if (FILTERS.rating && (p.rating || 0) < FILTERS.rating) return false;
    if (FILTERS.inStock && !(p.stock > 0)) return false;

    if (q){
      const hay = [p.title, p.category, p.subtitle, (p.tags || []).join(' ')]
        .join(' ').toLowerCase();
      if (!hay.includes(q)) return false;
    }
    return true;
  });

  const s = FILTERS.sort;
  if (s === 'price-asc')       list = [...list].sort((a,b) => a.price - b.price);
  else if (s === 'price-desc') list = [...list].sort((a,b) => b.price - a.price);
  else if (s === 'rating')     list = [...list].sort((a,b) => (b.rating||0) - (a.rating||0));

  renderGrid(list);
  updateFilterChrome();
}

/* how many filters are actually narrowing things down right now */
function activeFilterCount(){
  return FILTERS.cats.size +
    (FILTERS.min !== null ? 1 : 0) +
    (FILTERS.max !== null ? 1 : 0) +
    (FILTERS.rating ? 1 : 0) +
    (FILTERS.inStock ? 1 : 0);
}

function updateFilterChrome(){
  const n = activeFilterCount();
  const anything = n > 0 || !!FILTERS.search;

  $('#clearAll').hidden = !anything;

  const badge = $('#filtersBadge');
  badge.hidden = n === 0;
  badge.textContent = n;
}

function clearAllFilters(){
  FILTERS.search = '';
  FILTERS.cats.clear();
  FILTERS.min = FILTERS.max = null;
  FILTERS.rating = 0;
  FILTERS.inStock = false;

  $('#fSearch').value = '';
  $('#searchClear').hidden = true;
  $('#fMinPrice').value = '';
  $('#fMaxPrice').value = '';
  $('#fInStock').checked = false;
  $$('#catList input[data-cat]').forEach(b => { b.checked = false; });
  const any = $('input[name="fRating"][value="0"]');
  if (any) any.checked = true;

  applyFilters();
}

function renderGrid(list){
  const grid = $('#productGrid');
  $('#resultCount').textContent = `${list.length} product${list.length === 1 ? '' : 's'}`;

  if (!list.length){
    const q = $('#fSearch').value.trim();
    grid.innerHTML = `
      <div class="empty-state" style="grid-column:1/-1">
        <p class="serif" style="font-size:18px; margin-bottom:8px">
          ${q ? `No products match “${escapeHtml(q)}”` : 'No products match those filters'}
        </p>
        <p class="muted" style="margin-bottom:18px">
          ${q ? 'Try a different search, or clear the filters you have on.'
              : 'Try widening the price range or choosing a different category.'}
        </p>
        <button type="button" class="btn btn-primary" onclick="clearAllFilters()">
          ${q ? 'Clear search' : 'Clear all filters'}
        </button>
      </div>`;
    return;
  }

  // productCard() lives in common.js — same card, same quick-add button
  grid.innerHTML = list.map(p => productCard(p)).join('');
}

/* ---------- mobile filter drawer ---------- */
function openFilterDrawer(){
  $('#shopSidebar').classList.add('open');
  $('#drawerBackdrop').classList.add('show');
  document.body.style.overflow = 'hidden';
}

function closeFilterDrawer(){
  $('#shopSidebar').classList.remove('open');
  $('#drawerBackdrop').classList.remove('show');
  document.body.style.overflow = '';
}

document.addEventListener('DOMContentLoaded', initProducts);
