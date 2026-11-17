/* ============================================================
   success.js — success.html: order confirmation.

   checkout.js hands over only the order ID (in sessionStorage,
   because a real page navigation just happened and there is no
   in-memory JS state left to read). The order itself is fetched
   back from api/orders/detail.php, so this page shows the order as
   the DATABASE stored it — if the two ever disagreed, you would
   want to see the stored one.
   ============================================================ */

const ST_LABEL = {
  pending:   'Order placed',
  packed:    'Packed',
  shipped:   'Shipped',
  delivered: 'Delivered',
  cancelled: 'Cancelled',
  disputed:  'Disputed',
  refunded:  'Refunded'
};

function renderSuccess(order){
  const subs = order.sub_orders || [];

  $('#successBody').innerHTML = `
    <div style="text-align:center; padding:50px 0 20px">
      <div class="success-mark">✓</div>
      <h1 style="font-size:27px; margin-bottom:6px">Order confirmed</h1>
      <p class="muted">Reference <span class="mono">${escapeHtml(order.ref)}</span> · ${fmt(order.total)}</p>
    </div>

    <div class="notice" style="max-width:560px; margin:0 auto 14px">
      <span>◆</span><span>Your payment is recorded as <strong>held</strong>, and is released to each
      seller only after you confirm their delivery arrived.</span>
    </div>

    ${order.payment && order.payment.demo ? `
    <div class="notice" style="max-width:560px; margin:0 auto 24px; border-color:var(--mustard)">
      <span>◆</span><span><strong>Demo payment.</strong> This is a demonstration project — no real
      money moved and no card details were collected. Gateway reference
      <span class="mono">${escapeHtml(order.payment.reference || 'DEMO')}</span>.</span>
    </div>` : ''}

    <div style="max-width:560px; margin:0 auto">
      <p class="eyebrow" style="margin-bottom:10px">
        Split into ${subs.length} shipment${subs.length > 1 ? 's' : ''}${subs.length > 1
          ? ' — each seller ships and is paid separately'
          : ''}
      </p>

      ${subs.map(so => `
        <div class="seller-group">
          <div class="seller-head">
            <span>${escapeHtml(so.seller.name)}</span>
            <span class="status-pill st-${so.status}">${ST_LABEL[so.status] || so.status}</span>
          </div>
          ${(so.items || []).map(l => {
            const p = safeProduct(l.product);
            return `<div class="line">
              <div class="line-thumb">${productThumb(p, p.icon, 30)}</div>
              <div style="flex:1">
                <div class="ptitle" style="font-size:13.5px">${escapeHtml(l.title)}</div>
                <div class="pmeta">Qty ${l.qty} · ${fmt(l.unit_price)} each</div>
              </div>
              <div class="pprice">${fmt(l.line_total)}</div>
            </div>`;
          }).join('')}
          <div class="srow" style="border:0; padding-top:10px">
            <span class="muted" style="font-size:12px">Shipment total incl. delivery</span>
            <span class="mono">${fmt(so.total)}</span>
          </div>
        </div>`).join('')}

      <div class="summary" style="margin-top:16px">
        <div class="srow"><span>Items</span><span class="mono">${fmt(order.subtotal)}</span></div>
        <div class="srow"><span>Delivery (${subs.length} × ${fmt(order.delivery / (subs.length || 1))})</span><span class="mono">${fmt(order.delivery)}</span></div>
        <div class="srow total"><span>Paid</span><span>${fmt(order.total)}</span></div>
      </div>

      <div style="margin-top:18px; padding:14px; background:var(--surface-2); border-radius:var(--r-sm)">
        <p class="eyebrow" style="margin-bottom:6px">Delivering to</p>
        <p style="font-size:13px; line-height:1.6">
          ${escapeHtml(order.shipping.name)}<br>
          ${escapeHtml(order.shipping.address)}, ${escapeHtml(order.shipping.city)}${order.shipping.zip ? ' ' + escapeHtml(order.shipping.zip) : ''}<br>
          <span class="muted">${escapeHtml(order.shipping.phone)}</span>
        </p>
      </div>

      <div style="display:flex; gap:10px; margin-top:18px; flex-wrap:wrap">
        <a class="btn btn-dark" href="orders.html">Track this order</a>
        <a class="btn btn-ghost" href="index.html">Keep shopping</a>
      </div>
    </div>`;
}

document.addEventListener('DOMContentLoaded', () => {
  if (!requireAuth('success')) return;

  let orderId = null;
  try { orderId = sessionStorage.getItem('consult_last_order_id'); } catch(e){}

  // Landed here directly, or after a refresh in a new session: there is no
  // order to confirm, so send them where their orders actually live.
  if (!orderId){
    location.href = 'orders.html';
    return;
  }

  Session.ready
    .then(() => API.Orders.detail(orderId))
    .then(order => {
      renderSuccess(order);
      // One-shot: a reload should not re-confirm an older order.
      try { sessionStorage.removeItem('consult_last_order_id'); } catch(e){}
    })
    .catch(err => {
      console.error('Could not load the confirmed order:', err);
      if (err.code === 'NOT_FOUND'){
        location.href = 'orders.html';
        return;
      }
      renderErrorState($('#successBody'), 'reload');
    });
});
