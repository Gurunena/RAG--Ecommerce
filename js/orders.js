/* ============================================================
   orders.js — orders.html: the signed-in customer's order history.

   Everything here comes from api/orders/list.php. The old
   localStorage order store is gone: an order is a row in MySQL, so
   it survives a cleared browser, follows the account to another
   device, and is the same record the seller and admin dashboards
   read.

   Each sub-order renders a tracking timeline built from
   order_status_log — the append-only audit trail — and, once a
   seller marks a shipment shipped, the button that confirms
   delivery and releases that seller's payment out of escrow.
   ============================================================ */

/* The happy path, in order. Mirrors ORDER_FLOW in api/orders/_orders.php;
   the server is what enforces it, this is only how the timeline is drawn. */
const ORDER_FLOW = ['pending', 'packed', 'shipped', 'delivered'];

const STEP_LABEL = {
  pending:   'Order placed',
  packed:    'Packed by the seller',
  shipped:   'Handed to the courier',
  delivered: 'Delivered — payment released'
};

const STATUS_LABEL = {
  pending:   'Order placed',
  packed:    'Packed',
  shipped:   'Shipped',
  delivered: 'Delivered',
  cancelled: 'Cancelled',
  disputed:  'Disputed',
  refunded:  'Refunded'
};

const PAYOUT_LABEL = {
  escrow:    'Payment held until you confirm delivery',
  released:  'Payment released to the seller',
  refunded:  'Refunded to you',
  cancelled: 'Cancelled — nothing was paid out',
  held:      'Payment held while the dispute is reviewed'
};

/* ---------- dates (orders.html does not load dash-common.js) ---------- */

function orderDate(iso){
  if (!iso) return '';
  // 'YYYY-MM-DD HH:MM:SS' from MySQL — Safari refuses that with a space.
  const d = new Date(String(iso).replace(' ', 'T'));
  if (isNaN(d)) return String(iso);
  return d.toLocaleDateString('en-LK', { day: 'numeric', month: 'short', year: 'numeric' });
}

function orderTime(iso){
  if (!iso) return '';
  const d = new Date(String(iso).replace(' ', 'T'));
  if (isNaN(d)) return '';
  return d.toLocaleTimeString('en-LK', { hour: 'numeric', minute: '2-digit' });
}

/* ---------- one sub-order's tracking timeline ---------- */

function timelineHtml(sub){
  const log = sub.timeline || [];

  // How far along the flow this shipment actually got. A cancelled or
  // refunded shipment still shows the steps it did reach.
  const reached = Math.max(
    ORDER_FLOW.indexOf(sub.status),
    ...log.map(e => ORDER_FLOW.indexOf(e.status))
  );

  const steps = ORDER_FLOW.map((s, i) => {
    const entry = log.find(e => e.status === s);
    const cls = i < reached ? 'done' : i === reached ? 'now' : 'todo';
    const when = entry ? entry.changed_at : null;

    return `<li class="${cls}">
      <strong>${STEP_LABEL[s]}</strong>
      <span>${when
        ? `${orderDate(when)} · ${orderTime(when)}${entry.changed_by === 'admin' ? ' · by an admin' : ''}`
        : 'Not yet'}</span>
    </li>`;
  }).join('');

  // An exit from the flow (cancelled / disputed / refunded) is appended so
  // the timeline never silently drops what happened.
  const exits = log
    .filter(e => !ORDER_FLOW.includes(e.status))
    .map(e => `<li class="exit">
      <strong>${STATUS_LABEL[e.status] || e.status}</strong>
      <span>${orderDate(e.changed_at)} · ${orderTime(e.changed_at)} · by ${escapeHtml(e.changed_by)}</span>
      ${e.note ? `<span class="muted">${escapeHtml(e.note)}</span>` : ''}
    </li>`).join('');

  return `<ol class="ord-timeline">${steps}${exits}</ol>`;
}

/* ---------- one seller's shipment ---------- */

function subOrderHtml(sub){
  const canConfirm = sub.can && sub.can.confirm_delivery;

  return `
    <div class="seller-group" id="sub-${sub.id}">
      <div class="seller-head">
        <span>${escapeHtml(sub.seller.name)}${sub.seller.verified
          ? ' <span class="status-pill st-verified" style="margin-left:6px">verified</span>'
          : ''}</span>
        <span class="status-pill st-${sub.status}">${STATUS_LABEL[sub.status] || sub.status}</span>
      </div>

      ${(sub.items || []).map(l => {
        const p = safeProduct(l.product);
        const title = escapeHtml(l.title);
        return `<div class="line">
          <div class="line-thumb">${productThumb(p, p.icon, 30)}</div>
          <div style="flex:1">
            <div class="ptitle" style="font-size:13.5px">${l.product && l.product.available
              ? `<a href="product-detail.html?id=${encodeURIComponent(p.id)}" style="color:inherit">${title}</a>`
              : title}</div>
            <div class="pmeta">Qty ${l.qty} · ${fmt(l.unit_price)} each</div>
          </div>
          <div class="pprice">${fmt(l.line_total)}</div>
        </div>`;
      }).join('')}

      <div class="ord-sub-foot">
        <div>
          <span class="muted" style="font-size:12px">Shipment total</span>
          <span class="mono" style="margin-left:8px">${fmt(sub.total)}</span>
          <span class="muted" style="font-size:11.5px; display:block; margin-top:2px">
            ${fmt(sub.subtotal)} + ${fmt(sub.delivery_fee)} delivery
          </span>
        </div>
        <span class="status-pill st-${sub.payout.state === 'released' ? 'released' : sub.payout.state}">
          ${PAYOUT_LABEL[sub.payout.state] || sub.payout.state}
        </span>
      </div>

      ${sub.tracking_number ? `
        <p class="muted" style="font-size:12px; margin-top:8px">
          ${escapeHtml(sub.courier_name || 'Courier')} ·
          tracking <span class="mono">${escapeHtml(sub.tracking_number)}</span>
        </p>` : ''}

      ${timelineHtml(sub)}

      ${sub.dispute ? `
        <div class="notice" style="margin-top:12px; border-color:var(--maroon)">
          <span>◆</span>
          <span><strong>Dispute opened ${orderDate(sub.dispute.opened_at)}.</strong>
          ${escapeHtml(sub.dispute.reason || '')}
          ${sub.dispute.resolution
            ? `<br><strong>Resolved:</strong> ${escapeHtml(sub.dispute.resolution)}`
            : '<br>An admin is reviewing it — your payment stays held until it is resolved.'}</span>
        </div>` : ''}

      ${canConfirm ? `
        <div class="ord-actions">
          <button type="button" class="btn btn-primary"
                  onclick="confirmDelivery(${sub.id}, this)">
            Confirm delivery
          </button>
          <span class="muted" style="font-size:11.5px">
            Only confirm once the parcel is in your hands — this releases
            ${fmt(sub.payout.net)} to the seller and cannot be undone.
          </span>
        </div>` : ''}
    </div>`;
}

/* ---------- one order ---------- */

function orderHtml(order){
  const subs = order.sub_orders || [];

  return `
    <article class="ord-card">
      <header class="ord-card-head">
        <div>
          <p class="eyebrow" style="margin-bottom:2px">
            Reference <span class="mono">${escapeHtml(order.ref)}</span>
          </p>
          <p class="muted" style="font-size:12.5px">
            Placed ${orderDate(order.placed_at)} ·
            ${subs.length} shipment${subs.length > 1 ? 's' : ''} ·
            ${fmt(order.total)}
            ${order.payment && order.payment.demo
              ? ' · <span class="status-pill st-escrow">demo payment</span>'
              : ''}
          </p>
        </div>
      </header>
      ${subs.map(subOrderHtml).join('')}
    </article>`;
}

/* ---------- the page ---------- */

function renderOrders(result){
  const box = $('#ordersBody');
  const orders = result.items || [];

  if (!orders.length){
    box.innerHTML = `
      <div style="text-align:center; padding:70px 0">
        <p class="serif" style="font-size:20px; margin-bottom:8px">No orders yet</p>
        <p class="muted" style="margin-bottom:18px">Anything you buy will appear here, with live tracking for each seller.</p>
        <a class="btn btn-dark" href="index.html">Start shopping</a>
      </div>`;
    return;
  }

  box.innerHTML = `
    <h1 style="font-size:24px; margin-bottom:6px">Your orders</h1>
    <p class="muted" style="margin-bottom:20px">
      ${result.total} order${result.total > 1 ? 's' : ''}
    </p>
    ${orders.map(orderHtml).join('')}
    ${result.pages > 1 ? `
      <p class="muted" style="text-align:center; margin-top:22px; font-size:12.5px">
        Showing page ${result.page} of ${result.pages}
      </p>` : ''}`;
}

/**
 * Confirm one seller's delivery. This is the call that releases that
 * sub-order's payment out of escrow, so it asks first and the button
 * locks while it is in flight — a double click must not fire twice.
 */
function confirmDelivery(subOrderId, btn){
  if (!confirm('Confirm that this delivery arrived?\n\nThis releases the payment to the seller and cannot be undone.')){
    return;
  }

  btn.disabled = true;
  btn.textContent = 'Confirming…';

  API.Orders.confirmDelivery(subOrderId)
    .then(res => {
      toast(res.message || 'Delivery confirmed — payment released.');
      // Re-read the list so the pill, the payout line and the timeline all
      // come from the server rather than being patched in place.
      return loadOrders();
    })
    .catch(err => {
      console.error('Confirm delivery failed:', err);
      btn.disabled = false;
      btn.textContent = 'Confirm delivery';
      toast(err.message || 'Could not confirm that delivery. Please try again.');
      // Already advanced in another tab, or no longer confirmable: the
      // server's version is the truth, so repaint from it.
      if (err.code === 'VALIDATION' || err.code === 'NOT_FOUND') loadOrders();
    });
}

function loadOrders(){
  return API.Orders.list({ per_page: 20 })
    .then(renderOrders)
    .catch(err => {
      console.error('Could not load your orders:', err);
      if (err.code === 'AUTH') return;      // api.js is already redirecting
      renderErrorState($('#ordersBody'), 'reload');
    });
}

document.addEventListener('DOMContentLoaded', () => {
  if (!requireAuth('orders')) return;
  Session.ready.then(user => (user ? loadOrders() : null));
});
