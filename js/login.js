/* ============================================================
   login.js — login.html

   Signs in against POST api/auth/login.php. The client-side checks
   below stay (they are the faster feedback loop, and they're graded),
   but they only decide whether the request is worth sending — PHP
   validates everything again and is the only thing that can actually
   authenticate anyone.
   ============================================================ */

/* API field name -> this page's input id, for showApiErrors() */
const SIGNIN_FIELDS = { email: 'siEmail', password: 'siPass' };

function setSigninBusy(busy){
  const btn = $('#signinForm button[type="submit"]');
  if (!btn) return;
  if (busy){
    btn._label = btn._label || btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Signing in…';
  } else {
    btn.disabled = false;
    if (btn._label) btn.textContent = btn._label;
  }
}

function handleSignIn(){
  const okEmail = validateField('siEmail', 'email', 'Enter a valid email address');
  const okPass  = validateField('siPass', 'minLength8', 'Password must be at least 8 characters');

  if (!okEmail || !okPass){
    shakeHead('signinScene');
    toast('Please fix the highlighted fields');
    return;
  }

  const email = $('#siEmail').value.trim();
  const password = $('#siPass').value;

  setSigninBusy(true);

  Session.signIn(email, password)
    .then(user => {
      celebrate('signinScene');
      toast('Welcome back!');
      setTimeout(() => { location.href = landingFor(user); }, 800);
    })
    .catch(err => {
      setSigninBusy(false);
      shakeHead('signinScene');
      // Wrong credentials, a suspended account and a throttled one all come
      // back without field errors, so they land under the password input.
      showApiErrors(err, SIGNIN_FIELDS, 'siPass');
    });
}

/* where each role lands after sign-in, unless ?return= says otherwise */
function landingFor(u){
  if (returnTarget()) return postLoginRedirect();
  if (u.role === 'admin')  return 'admin-dashboard.html';
  if (u.role === 'seller') return 'seller-dashboard.html';
  return 'index.html';
}

/* ---------- demo accounts ----------
   These fill the form in; they do NOT bypass authentication. Clicking one
   drops its email and the shared demo password into the fields and submits
   them through the same api/auth/login.php as anyone else, so what you see
   in a demo is the real login path.

   The list is static on purpose: these are the accounts sql/seed.sql
   creates, and reading them from the database would mean a public endpoint
   that lists real accounts and their roles — exactly what shouldn't exist.
   Delete this block and the <details> in login.html before any real
   deployment. */
const DEMO_ACCOUNTS = [
  { email: 'admin@consult.lk',         label: 'Web-master',       hint: 'admin console' },
  { email: 'techhub@example.com',      label: 'Colombo Tech Hub', hint: 'verified seller' },
  { email: 'kandygadgets@example.com', label: 'Kandy Gadgets',    hint: 'seller, KYC pending' },
  { email: 'nimal@example.com',        label: 'Nimal Perera',     hint: 'customer' }
];
const DEMO_PASSWORD = 'Consult@2026';

function renderDemoAccounts(){
  const box = $('#demoAccounts');
  if (!box) return;

  box.innerHTML = DEMO_ACCOUNTS.map(a => `
    <button type="button" class="demo-acct" data-email="${escapeHtml(a.email)}">
      <span class="demo-acct-avatar">${escapeHtml(a.label.charAt(0))}</span>
      <span class="demo-acct-copy">
        <strong>${escapeHtml(a.label)}</strong>
        <span>${escapeHtml(a.hint)} · ${escapeHtml(a.email)}</span>
      </span>
    </button>`).join('');

  box.addEventListener('click', e => {
    const btn = e.target.closest('.demo-acct');
    if (!btn) return;
    $('#siEmail').value = btn.dataset.email;
    $('#siPass').value = DEMO_PASSWORD;
    setFieldValid($('#siEmail'), true, '');
    setFieldValid($('#siPass'), true, '');
    handleSignIn();
  });
}

document.addEventListener('DOMContentLoaded', () => {
  wireFocus('signinScene', 'siEmail', 'siPass');
  wireAuthCrossLinks();
  setMood('signinScene', 'neutral');
  renderDemoAccounts();

  $('#signinForm').addEventListener('submit', e => { e.preventDefault(); handleSignIn(); });
  $$('.oauth-btn').forEach(b => b.addEventListener('click', () => toast('Google sign-in is a demo in this build')));
});
