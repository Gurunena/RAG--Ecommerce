/* ============================================================
   account.js — account.html: view / edit the signed-in user's
   profile.

   Saved through POST api/auth/update_profile.php, which takes the
   user id from the PHP session and never from the request — so this
   page can only ever edit the account it is signed in as.
   ============================================================ */

const ACCOUNT_FIELDS = { name: 'acFullName', email: 'acEmail', phone: 'acPhone' };

function fillAccount(){
  const u = Session.user;
  if (!u) return;
  $('#acctAvatar').textContent = (u.name || u.email || '?').trim().charAt(0).toUpperCase();
  $('#acctName').textContent = u.name || u.email;
  $('#acctRole').textContent = u.role === 'admin' ? 'Platform admin' : u.role === 'seller' ? 'Seller account' : 'Customer account';
  $('#acFullName').value = u.name || '';
  $('#acEmail').value = u.email || '';
  $('#acPhone').value = u.phone || '';
}

function setAccountBusy(busy){
  const btn = $('#accountForm button[type="submit"]');
  if (!btn) return;
  if (busy){
    btn._label = btn._label || btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Saving…';
  } else {
    btn.disabled = false;
    if (btn._label) btn.textContent = btn._label;
  }
}

function saveAccount(){
  const okName  = validateField('acFullName', 'required', 'Full name is required');
  const okEmail = validateField('acEmail', 'email', 'Enter a valid email address');
  // phoneAny, not phone: a profile number may be a landline (the seeded
  // admin's is 0112000000). Sign-up still asks for a mobile.
  const okPhone = validateField('acPhone', 'phoneAny', 'Enter a valid 10-digit Sri Lankan number');
  if (!(okName && okEmail && okPhone)){
    toast('Please fix the highlighted fields');
    return;
  }

  setAccountBusy(true);

  API.Auth.updateProfile({
    name: $('#acFullName').value.trim(),
    email: $('#acEmail').value.trim(),
    phone: $('#acPhone').value.trim()
  })
    .then(data => {
      setAccountBusy(false);
      // Adopt what the server actually saved (the phone comes back
      // normalised to 0XXXXXXXXX), not what was typed.
      Session.login(data.user);
      fillAccount();
      toast('Profile updated');
    })
    .catch(err => {
      setAccountBusy(false);
      showApiErrors(err, ACCOUNT_FIELDS, 'acEmail');
    });
}

document.addEventListener('DOMContentLoaded', () => {
  if (!requireAuth('account')) return;
  fillAccount();
  // The snapshot drew the form; redraw it once PHP confirms who we are, in
  // case the profile changed in another tab.
  if (Session.ready) Session.ready.then(() => fillAccount());
  $('#accountForm').addEventListener('submit', e => { e.preventDefault(); saveAccount(); });
});
