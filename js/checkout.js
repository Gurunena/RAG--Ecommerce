/* ============================================================
   checkout.js — checkout.html: delivery details, then one AJAX
   call that turns the cart into an order.

   What this page does NOT do, on purpose: add anything up. The
   figures shown are the ones api/cart/* computed, and the order is
   placed by posting the delivery address alone —
   api/orders/checkout.php re-prices every line from MySQL inside a
   transaction and decides the total itself. So there is no number on
   this page that a tampered browser could turn into money.

   The gateway is simulated. The UI says so rather than implying a
   real card was charged.
   ============================================================ */

function renderCheckout(){
  if (!State.cart.length){ location.href = 'cart.html'; return; }
  const box = $('#checkoutBody');
  try {
    // The server's figures, not a second sum over the mirror.
    const t = State.cartTotals;
    const sellers = t.sellers || new Set(State.cart.map(l => l.product.sellerId)).size;
    const total = t.total;

    box.innerHTML = `
      <div class="cart-grid">
        <div>
          <h2 style="font-size:19px; margin-bottom:14px">Delivery details</h2>
          <div class="grid2">
            <div class="field"><label>Full name</label><input id="ckName" placeholder="Nimal Perera"></div>
            <div class="field"><label>Phone</label><input id="ckPhone" placeholder="07X XXX XXXX"></div>
          </div>
          <div class="field"><label>Address</label><input id="ckAddr" placeholder="No. 42, Galle Road"></div>
          <div class="grid2">
            <div class="field"><label>City</label><input id="ckCity" placeholder="Colombo 03"></div>
            <div class="field"><label>Postal code</label><input id="ckZip" placeholder="00300"></div>
          </div>
          <div class="field"><label>Delivery note (optional)</label>
            <textarea id="ckNote" rows="2" placeholder="Call before arriving"></textarea></div>

          <h2 style="font-size:19px; margin:24px 0 14px">Payment</h2>

          <div class="pay-opt sel">
            <div class="radio"></div>
            <div>
              <div style="font-weight:600; font-size:13.5px">
                Demo payment <span class="status-pill st-escrow" style="margin-left:6px">simulated</span>
              </div>
              <div class="muted" style="font-size:12px">
                This is a university demonstration project — no real money moves and
                no card details are collected or stored.
              </div>
            </div>
          </div>

          <div class="notice"><span>◆</span><span>Sellers only see your name, address and phone — never your email. Your payment is recorded as held, and is released to each seller only after you confirm that their delivery arrived.</span></div>
        </div>

        <div>
          <div class="summary">
            <div class="srow"><span>${State.cart.length} item${State.cart.length>1?'s':''} · ${sellers} seller${sellers>1?'s':''}</span><span class="mono">${fmt(t.subtotal)}</span></div>
            <div class="srow"><span>Delivery</span><span class="mono">${fmt(t.delivery)}</span></div>
            <div class="srow total"><span>Total</span><span>${fmt(total)}</span></div>
            <button class="btn btn-primary btn-block" style="margin-top:14px" id="payBtn" onclick="submitOrder()">Place order · ${fmt(total)}</button>
            <p class="muted" style="font-size:11.5px; text-align:center; margin-top:8px">
              ${sellers > 1
                ? `Your ${sellers} sellers ship separately, so this becomes ${sellers} shipments you track individually.`
                : 'You will be able to track this shipment from your orders page.'}
            </p>
          </div>
        </div>
      </div>`;
  } catch(err){
    console.error('Checkout render failed:', err);
    renderErrorState(box, 'clear-cart');
  }
}

function validateCheckout(){
  const okName  = validateField('ckName', 'required', 'Full name is required');
  const okPhone = validateField('ckPhone', 'phone', 'Enter a valid Sri Lankan number (07X XXXXXXX)');
  const okAddr  = validateField('ckAddr', 'required', 'Delivery address is required');
  const okCity  = validateField('ckCity', 'required', 'City is required');
  const okZip   = validateField('ckZip', 'required', 'Postal code is required');
  return okName && okPhone && okAddr && okCity && okZip;
}

function submitOrder(){
  // Client-side validation is the courtesy; api/orders/checkout.php
  // validates the same fields again and is what actually decides.
  if (!validateCheckout()){
    toast('Please fix the highlighted fields');
    return;
  }

  const shipping = {
    name:    $('#ckName').value.trim(),
    phone:   $('#ckPhone').value.trim(),
    address: $('#ckAddr').value.trim(),
    city:    $('#ckCity').value.trim(),
    zip:     $('#ckZip').value.trim(),
    note:    $('#ckNote').value.trim()
  };

  const btn = $('#payBtn');
  const restore = () => {
    btn.disabled = false;
    btn.textContent = `Place order · ${fmt(State.cartTotals.total)}`;
  };

  btn.disabled = true;
  btn.textContent = 'Placing your order…';

  /* One POST. The server validates stock, re-prices from the database,
     writes the order + sub-orders + items + payment, decrements stock and
     empties the cart — all in one transaction. Nothing is half-done. */
  API.Orders.checkout(shipping)
    .then(order => {
      // The cart is empty on the server now; refresh the mirror so the nav
      // badge is right the moment the next page paints.
      State.cart = [];
      State.cartTotals = { count: 0, sellers: 0, subtotal: 0, delivery: 0, total: 0 };
      updateCartBadge();

      // Hand the reference to success.html. Only the id travels — that page
      // re-reads the order from the server, so what it shows is the real
      // stored order rather than a copy that could drift.
      try { sessionStorage.setItem('consult_last_order_id', String(order.id)); } catch(e){}
      location.href = 'success.html';
    })
    .catch(err => {
      console.error('Order placement failed:', err);
      restore();

      /* A rejected ADDRESS comes back as per-field errors (422) — put each
         message under its own input. showApiErrors toasts by itself, so
         this branch must not toast again. */
      if (err.fields){
        showApiErrors(err, {
          name: 'ckName', phone: 'ckPhone', address: 'ckAddr',
          city: 'ckCity', zip: 'ckZip', note: 'ckNote'
        });
        return;
      }

      toast(err.message || 'Could not place your order. Please try again.');

      /* A CART problem (409, no fields): something sold out or was taken
         down while this page sat open. Nothing was charged and the cart is
         untouched, so re-read it — the figures on screen then match what
         the server actually holds and the shopper can fix the line. */
      if (err.code === 'VALIDATION'){
        loadCart().then(() => renderCheckout()).catch(() => {});
      }
    });
}

document.addEventListener('DOMContentLoaded', () => {
  if (!requireAuth('checkout')) return;

  // The cart comes from MySQL, so there is nothing to render until it has
  // arrived — rendering early would bounce to cart.html as "empty".
  Session.ready
    .then(user => (user ? loadCart() : null))
    .then(() => renderCheckout())
    .catch(err => {
      console.error('Could not load the cart for checkout:', err);
      renderErrorState($('#checkoutBody'), 'reload');
    });
});
