/* ============================================================
   register.js — register.html (customer sign up)
   ============================================================ */

function updateStrength(){
  const v = $('#suPass').value;
  const fill = $('#suStrengthFill');
  const label = $('#suStrengthLabel');
  if (!fill) return;
  let pct = 0, color = 'var(--maroon)', text = '', mood = 'neutral';
  if (v.length >= 8 && /[a-z]/i.test(v) && /[A-Z0-9]/.test(v)){
    pct = 100; color = '#227850'; text = 'Strong'; mood = 'happy';
  } else if (v.length >= 6){
    pct = 66; color = 'var(--mustard)'; text = 'Okay — add a number or symbol'; mood = 'neutral';
  } else if (v.length > 0){
    pct = 33; color = 'var(--maroon)'; text = 'Too short'; mood = 'worried';
  }
  fill.style.width = pct + '%';
  fill.style.background = color;
  if (label) label.textContent = text;
  setMood('signupScene', mood);
}

function updateGiftProgress(){
  const ids = ['suFirst', 'suLast', 'suEmail', 'suPhone', 'suPass'];
  const filled = ids.filter(id => $('#' + id)?.value.trim()).length;
  const fill = $('#giftFill');
  if (fill) fill.style.height = (filled / ids.length * 100) + '%';
  const label = $('#giftLabel');
  if (label) label.textContent = filled + ' / ' + ids.length + ' fields';
}

/* API field name -> this page's input id, for showApiErrors().
   The server sees one `name`; this form collects it as first + last, so
   both of its errors are shown on the first-name field. */
const SIGNUP_FIELDS = {
  name: 'suFirst',
  email: 'suEmail',
  phone: 'suPhone',
  password: 'suPass',
  accept_terms: 'suTerms'
};

function setSignupBusy(busy){
  const btn = $('#signupForm button[type="submit"]');
  if (!btn) return;
  if (busy){
    btn._label = btn._label || btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Creating account…';
  } else {
    btn.disabled = false;
    if (btn._label) btn.textContent = btn._label;
  }
}

function handleSignUp(){
  const okFirst = validateField('suFirst', 'required', 'First name is required');
  const okEmail = validateField('suEmail', 'email', 'Enter a valid email address');
  const okPhone = validateField('suPhone', 'phone', 'Enter a valid Sri Lankan number (07X XXXXXXX)');
  const okPass  = validateField('suPass', 'minLength8', 'Password must be at least 8 characters');
  const okTerms = validateField('suTerms', 'checked', 'You must accept the terms to continue');
  if (!(okFirst && okEmail && okPhone && okPass && okTerms)){
    shakeHead('signupScene');
    toast('Please fix the highlighted fields');
    return;
  }

  const fn = $('#suFirst').value.trim();
  const ln = $('#suLast').value.trim();

  setSignupBusy(true);

  /* Whether sign-ups are open, and whether this email is already taken,
     are both decided by PHP now — the browser cannot be trusted with
     either, and the client-side copy of settings would go stale anyway. */
  Session.signUp({
    name: (fn + ' ' + ln).trim(),
    email: $('#suEmail').value.trim(),
    phone: $('#suPhone').value.trim(),
    password: $('#suPass').value,
    accept_terms: $('#suTerms').checked
  })
    .then(() => {
      celebrate('signupScene');
      spawnConfetti($('#signupCard'));
      toast(`Account created — welcome to Consult, ${fn}!`);
      setTimeout(() => { location.href = postLoginRedirect(); }, 1100);
    })
    .catch(err => {
      setSignupBusy(false);
      shakeHead('signupScene');
      showApiErrors(err, SIGNUP_FIELDS, 'suEmail');
    });
}

document.addEventListener('DOMContentLoaded', () => {
  wireFocus('signupScene', 'suEmail', 'suPass');
  wireAuthCrossLinks();
  setMood('signupScene', 'neutral');

  $('#signupForm').addEventListener('submit', e => { e.preventDefault(); handleSignUp(); });
  $('#suPass').addEventListener('input', updateStrength);
  ['suFirst', 'suLast', 'suEmail', 'suPhone', 'suPass'].forEach(id => {
    $('#' + id)?.addEventListener('input', updateGiftProgress);
  });
  $$('.oauth-btn').forEach(b => b.addEventListener('click', () => toast('Google sign-in is a demo in this build')));
});
