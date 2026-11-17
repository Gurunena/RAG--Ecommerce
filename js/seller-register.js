/* ============================================================
   seller-register.js — seller-register.html (3-step seller signup)
   ============================================================ */

let regStep = 1;

function validateRegStep1(){
  const okName = validateField('rgBizName', 'required', 'Business name is required');
  const okCity = validateField('rgCity', 'required', 'City is required');
  const okCat  = validateField('rgCategory', 'required', 'Choose a primary category');
  return okName && okCity && okCat;
}
function validateRegStep2(){
  const okFirst = validateField('rgFirst', 'required', 'First name is required');
  const okLast  = validateField('rgLast', 'required', 'Last name is required');
  const okNIC   = validateField('rgNIC', 'required', 'NIC number is required');
  const okEmail = validateField('rgEmail', 'email', 'Enter a valid email address');
  const okPhone = validateField('rgPhone', 'phone', 'Enter a valid Sri Lankan number (07X XXXXXXX)');
  // 8, to match api/config.php's password_min_length — a client rule looser
  // than the server's just turns into a rejection the shopper cannot see.
  const okPass  = validateField('rgPass', 'minLength8', 'Password must be at least 8 characters');
  return okFirst && okLast && okNIC && okEmail && okPhone && okPass;
}
function validateRegStep3(){
  const okBank  = validateField('rgBank', 'required', 'Select your bank');
  const okAcct  = validateField('rgAcct', 'required', 'Account number is required');
  const okAgree = validateField('rgAgree', 'checked', 'You must accept the seller agreement');
  return okBank && okAcct && okAgree;
}

function regNext(step){
  if (step > regStep){
    if (regStep === 1 && !validateRegStep1()){ shakeHead('registerScene'); toast('Fill in the required business details first'); return; }
    if (regStep === 2 && !validateRegStep2()){ shakeHead('registerScene'); toast('Fill in every owner field correctly'); return; }
  }
  regStep = step;

  $$('.reg-step').forEach(s => s.classList.toggle('on', +s.dataset.step === step));
  $$('.step-dot').forEach(d => {
    const n = +d.dataset.step;
    d.classList.toggle('done', n < step);
    d.classList.toggle('on', n === step);
    d.textContent = n < step ? '✓' : n;
  });
  $$('.step-line').forEach((l, i) => l.classList.toggle('done', i < step - 1));

  const scene = $('#registerScene');
  if (scene) scene.className = 'auth-scene step-' + step;

  $$('#registerScene .acc').forEach(a => a.classList.remove('on'));
  const map = { 1: 'acc-clipboard', 2: 'acc-badge', 3: 'acc-key' };
  $('#registerScene .' + map[step])?.classList.add('on');

  const walk = $('#registerScene .char-walk');
  walk?.classList.add('walking');
  setTimeout(() => walk?.classList.remove('walking'), 650);
}

/* ------------------------------------------------------------------
   Submits the application to api/seller/register.php, which creates BOTH
   rows a seller needs in one transaction: users (role 'seller', password
   hashed) and sellers (kyc_status 'pending').

   'pending' is the point. The account exists and can sign in, but the
   backend refuses every publish until an admin approves it, so there is
   nothing here that can put a listing in front of a shopper.

   It deliberately does NOT sign the applicant in: there is nothing to do in
   the dashboard until a human has looked at the application, and a signed-in
   nav on a dormant account reads as though something went through.

   The three steps, their validation and the animation are unchanged.
   ------------------------------------------------------------------ */

/* Which step each server-side field lives on, so a rejection can send the
   seller back to the step that actually has the problem. */
const REG_FIELD_STEP = {
  business_name: 1, business_type: 1, registration_no: 1,
  city: 1, district: 1, primary_category: 1,
  first_name: 2, last_name: 2, email: 2, phone: 2, password: 2, nic_number: 2,
  bank_name: 3, account_number: 3, branch: 3, accept_terms: 3
};

/* Server field name → this page's input id. */
const REG_FIELD_INPUT = {
  business_name: 'rgBizName', business_type: 'rgBizType', registration_no: 'rgBizReg',
  city: 'rgCity', district: 'rgDistrict', primary_category: 'rgCategory',
  first_name: 'rgFirst', last_name: 'rgLast', email: 'rgEmail',
  phone: 'rgPhone', password: 'rgPass', nic_number: 'rgNIC',
  bank_name: 'rgBank', account_number: 'rgAcct', branch: 'rgBranch',
  accept_terms: 'rgAgree'
};

function handleRegister(){
  if (!validateRegStep3()){
    shakeHead('registerScene');
    toast('Please fix the highlighted fields');
    return;
  }

  const val = id => ($('#' + id)?.value || '').trim();
  // The select's option values are the enum values themselves now, so there
  // is no label→key translation here to fall out of step with the schema.
  const bizType = val('rgBizType') || 'other';

  const btn = $('#rgSubmit');
  const label = btn ? btn.textContent : '';
  if (btn){ btn.disabled = true; btn.textContent = 'Submitting…'; }

  API.Seller.register({
    first_name:       val('rgFirst'),
    last_name:        val('rgLast'),
    email:            val('rgEmail'),
    phone:            val('rgPhone'),
    password:         $('#rgPass').value,          // raw: spaces are legal
    nic_number:       val('rgNIC'),

    business_name:    val('rgBizName'),
    business_type:    bizType,
    registration_no:  val('rgBizReg'),
    city:             val('rgCity'),
    district:         val('rgDistrict'),
    primary_category: val('rgCategory'),

    bank_name:        val('rgBank'),
    account_number:   val('rgAcct'),
    branch:           val('rgBranch'),
    accept_terms:     !!$('#rgAgree')?.checked
  })
    .then(res => {
      celebrate('registerScene');
      $('#shopDoor')?.classList.add('open');
      toast(res.message || 'Application received — an admin will review it shortly.');
      // Sign-in, not the dashboard: the account is real but dormant until
      // it is approved, and login.html explains that state.
      setTimeout(() => { location.href = 'login.html?next=seller-dashboard.html'; }, 2200);
    })
    .catch(err => {
      if (btn){ btn.disabled = false; btn.textContent = label; }
      shakeHead('registerScene');

      // Put each server message under its own input, then jump to the
      // earliest step that has one — otherwise an error on step 1 is
      // invisible while step 3 is on screen.
      if (err.fields){
        showApiErrors(err, REG_FIELD_INPUT);
        const steps = Object.keys(err.fields)
          .map(f => REG_FIELD_STEP[f])
          .filter(Boolean);
        if (steps.length){
          const first = Math.min.apply(null, steps);
          if (first !== regStep) regNext(first);
        }
        return;
      }

      toast(err.message || 'Could not submit your application. Please try again.');
    });
}

function uploadStub(){
  toast('File upload is a demo in this build');
}

/* The category dropdown is built from the live settings table, so it can
   never offer a category the backend would reject. The markup carries the
   current five as a fallback for the case where this call fails. */
function loadRegisterSettings(){
  return API.request('settings/public.php')
    .then(cfg => {
      const sel = $('#rgCategory');
      if (sel && Array.isArray(cfg.categories) && cfg.categories.length){
        const chosen = sel.value;
        sel.innerHTML = '<option value="">Select a category</option>' +
          cfg.categories.map(c =>
            `<option value="${escapeHtml(c.key)}">${escapeHtml(c.label)}</option>`
          ).join('');
        if (chosen) sel.value = chosen;
      }

      // Applications closed: say so here rather than letting the seller fill
      // in three steps and be refused at the end.
      if (cfg.seller_signups_open === false){
        const btn = $('#rgSubmit');
        if (btn){
          btn.disabled = true;
          btn.textContent = 'Applications are closed';
        }
        toast('Seller applications are closed at the moment. Check back soon.');
      }
    })
    .catch(err => {
      // Not fatal — the fallback options in the HTML are the real keys too.
      console.warn('[seller-register] could not load site settings —', err.message);
    });
}

document.addEventListener('DOMContentLoaded', () => {
  wireFocus('registerScene', 'rgEmail', 'rgPass');
  wireAuthCrossLinks();
  setMood('registerScene', 'neutral');
  loadRegisterSettings();
});
