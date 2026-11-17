/* ============================================================
   cart.js — cart.html: one cart, grouped by seller.

   The cart is rows in cart_items now. State.cart is the mirror
   common.js keeps of the server's answer, so this file renders the
   same way it always did; the +/- buttons send the new quantity to
   PHP and redraw from what comes back, rather than editing a local
   array and hoping.

   The totals shown are the server's, not a second calculation here —
   a cart page that quotes a different number from checkout is worse
   than one that is a little slower.
   ============================================================ */

function renderCart(){
  const box = $('#cartBody');
  if (!box) return;
  try {
    if (!State.cart.length){
      box.innerHTML = `<div style="text-align:center; padding:70px 0">
        <p class="serif" style="font-size:21px; margin-bottom:8px">Your cart is empty</p>
        <p class="muted" style="margin-bottom:20px">Tell the consultant what you need and it'll find it.</p>
        <a class="btn btn-dark" href="index.html#chat">Start a conversation</a></div>`;
      return;
    }

    const groups = {};
    State.cart.forEach(l => (groups[l.product.sellerId] ||= []).push(l));

    const html = Object.values(groups).map(lines => {
      const s = safeProduct(lines[0].product).seller;
      return `<div class="seller-group">
        <div class="seller-head">
          <span>${escapeHtml(s.name)}${s.city ? ' · ' + escapeHtml(s.city) : ''}</span>
          <span class="mono" style="font-size:11px; color:var(--gray)">ships separately</span>
        </div>
        ${lines.map(l => {
          const p = safeProduct(l.product);
          const atMax = l.qty >= (l.product.stock || 0);
          return `<div class="line">
          <div class="line-thumb">${productThumb(p, l.product.icon, 34)}</div>
          <div style="flex:1; min-width:0">
            <div class="ptitle" style="font-size:14px">${escapeHtml(p.title)}</div>
            <div class="pmeta">${escapeHtml(p.subtitle)}</div>
            ${l.product.onSale ? `<div class="pmeta" style="color:var(--maroon)">on sale until ${escapeHtml(String(l.product.saleEndsAt || ''))}</div>` : ''}
          </div>
          <div class="qty">
            <button onclick="setQty('${p.id}',-1)" aria-label="Decrease quantity">−</button>
            <span>${l.qty}</span>
            <button onclick="setQty('${p.id}',1)" aria-label="Increase quantity"${atMax ? ' disabled title="That is all the seller has in stock"' : ''}>+</button>
          </div>
          <div class="pprice" style="min-width:110px; text-align:right">${p.price != null ? fmt(p.price * l.qty) : fmtOrNA(null)}</div>
        </div>`;
        }).join('')}
      </div>`;
    }).join('');

    // Straight from the server, so the cart and checkout always agree.
    const t = State.cartTotals;
    const sellers = t.sellers || Object.keys(groups).length;

    box.innerHTML = `
      <div class="cart-grid">
        <div>
          ${html}
          ${sellers > 1 ? `<div class="notice">
            <span>◆</span><span>Your cart has items from <b>${sellers} sellers</b>. You'll pay once — we split the order and route each part to the right seller automatically.</span>
          </div>` : ''}
        </div>
        <div>
          <div class="summary">
            <div class="srow"><span>Subtotal</span><span class="mono">${fmt(t.subtotal)}</span></div>
            <div class="srow"><span>Delivery</span><span class="mono">${fmt(t.delivery)}</span></div>
            <div class="srow total"><span>Total</span><span>${fmt(t.total)}</span></div>
            <a class="btn btn-primary btn-block" style="margin-top:14px; text-align:center" href="checkout.html">Continue to checkout</a>
          </div>
        </div>
      </div>`;
  } catch(err){
    console.error('Cart render failed:', err);
    renderErrorState(box, 'clear-cart');
  }
}

/* The buttons are still +1 / −1. The endpoint takes an absolute quantity,
   so the delta is resolved against the line being shown; the server clamps
   it to stock and we redraw from its answer. */
function setQty(id, delta){
  const line = State.cart.find(l => l.product.id === id);
  if (!line) return;
  setCartQty(id, line.qty + delta);     // common.js — calls renderCart() back
}

document.addEventListener('DOMContentLoaded', () => {
  if (!requireAuth('cart')) return;

  const box = $('#cartBody');
  if (box){
    box.innerHTML = `<div class="skeleton" style="height:120px; margin-bottom:12px"></div>
                     <div class="skeleton" style="height:120px"></div>`;
  }

  // common.js already asks for the cart off Session.ready; wait on the same
  // promise rather than firing a second request for it.
  Session.ready
    .then(user => (user ? loadCart() : null))
    .then(() => renderCart())
    .catch(err => {
      console.error('Could not load the cart:', err);
      if (!box) return;
      box.innerHTML = `
        <div class="render-error">
          <p class="serif" style="font-size:17px; margin-bottom:6px">Couldn't load your cart</p>
          <p class="muted" style="margin-bottom:16px">${escapeHtml(err.message)}</p>
          <button type="button" class="btn btn-dark" onclick="location.reload()">Try again</button>
        </div>`;
    });
});
