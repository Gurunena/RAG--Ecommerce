/* ============================================================
   contact.js — contact.html: the contact form and the public FAQ.

   The form posts to api/contact_submit.php, which stores a row in
   contact_messages and is what the admin console's Messages list
   reads. Validation runs here as a courtesy and again on the server,
   which is the one that decides.

   Three anti-spam measures, all server-side:
     · the #cWebsite honeypot below — rendered, hidden, never filled
       by a person, always filled by a bot that walks the inputs
     · a time check, stamped by API.Contact.open() when this page loads
     · a rate limit of five messages per IP per hour

   The FAQ underneath comes from api/faqs.php — the same faqs rows the
   admin edits, so a change there shows here on the next load.
   ============================================================ */

/* ============================================================
   THE FORM
   ============================================================ */

function handleContact(){
  const okName    = validateField('cName', 'required', 'Your name is required');
  const okEmail   = validateField('cEmail', 'email', 'Enter a valid email address');
  const okSubject = validateField('cSubject', 'required', 'Add a short subject');
  const okMessage = validateField('cMessage', 'required', 'Message can\'t be empty');

  if (!(okName && okEmail && okSubject && okMessage)){
    toast('Please fix the highlighted fields');
    return;
  }

  const btn = $('#cSubmit');
  const label = btn ? btn.textContent : '';
  if (btn){ btn.disabled = true; btn.textContent = 'Sending…'; }

  const name = $('#cName').value.trim();

  API.Contact.submit({
    name:    name,
    email:   $('#cEmail').value.trim(),
    subject: $('#cSubject').value.trim(),
    message: $('#cMessage').value.trim(),
    // The honeypot. A person never sees this input, so it travels empty.
    website: $('#cWebsite') ? $('#cWebsite').value : ''
  })
    .then(res => {
      $('#contactForm').reset();
      toast(res.message || `Thanks, ${name} — we'll be in touch soon`);
      if (btn){ btn.disabled = false; btn.textContent = label; }

      /* The server's timing stamp is one-shot, so a second message needs a
         fresh one — without this, sending twice in a row would be refused
         as "too quick". */
      API.Contact.open().catch(() => {});
    })
    .catch(err => {
      if (btn){ btn.disabled = false; btn.textContent = label; }
      console.error('Contact submit failed:', err);

      // Per-field errors land under their own inputs; showApiErrors toasts
      // by itself, so this branch must not toast again.
      if (err.fields){
        showApiErrors(err, {
          name: 'cName', email: 'cEmail', subject: 'cSubject', message: 'cMessage'
        });
        return;
      }
      toast(err.message || 'Could not send your message. Please try again.');
    });
}

/* ============================================================
   THE FAQ
   ============================================================ */

function renderFaqs(data){
  const box = $('#faqBody');
  if (!box) return;

  const groups = data.groups || [];

  if (!groups.length){
    // Nothing to show is not an error — an admin may simply not have
    // written any answers yet. Say so quietly rather than leaving a gap.
    box.innerHTML = `
      <p class="muted" style="font-size:13.5px">
        No questions have been answered here yet — send us a message above and we'll reply directly.
      </p>`;
    return;
  }

  box.innerHTML = groups.map(group => `
    <div class="faq-group">
      <h3 class="faq-group-title">${escapeHtml(group.label)}</h3>
      ${group.items.map(item => `
        <details class="faq-item">
          <summary>
            <span>${escapeHtml(item.question)}</span>
            <i class="faq-chev" aria-hidden="true"></i>
          </summary>
          <div class="faq-answer">${escapeHtml(item.answer)}</div>
        </details>`).join('')}
    </div>`).join('');
}

function loadFaqs(){
  return API.Faqs.list()
    .then(renderFaqs)
    .catch(err => {
      console.warn('[contact] could not load the FAQ —', err.message);
      const box = $('#faqBody');
      if (box){
        box.innerHTML = `
          <p class="muted" style="font-size:13.5px">
            The FAQ could not be loaded just now.
            <button type="button" class="btn btn-ghost btn-sm" style="margin-left:8px"
                    onclick="loadFaqs()">Try again</button>
          </p>`;
      }
    });
}

/* ============================================================
   BOOT
   ============================================================ */

document.addEventListener('DOMContentLoaded', () => {
  $('#contactForm').addEventListener('submit', e => { e.preventDefault(); handleContact(); });

  /* Stamps the session with the time this form was opened, and prefills the
     name and email for anybody already signed in. Failing is not fatal:
     without the stamp the server simply skips the timing check. */
  API.Contact.open()
    .then(d => {
      const pre = d.prefill || {};
      if (pre.name && $('#cName') && !$('#cName').value) $('#cName').value = pre.name;
      if (pre.email && $('#cEmail') && !$('#cEmail').value) $('#cEmail').value = pre.email;
    })
    .catch(err => console.warn('[contact] could not prepare the form —', err.message));

  loadFaqs();
});
