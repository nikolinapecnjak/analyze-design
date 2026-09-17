(function () {
  var messages = {
    en: {
      sending: 'Sending…',
      ok: "Thanks — your message has been sent. We'll get back to you within 1–2 business days.",
      err: 'Something went wrong — please email us directly at info@analyze-design.hr.'
    },
    hr: {
      sending: 'Šalje se…',
      ok: 'Hvala — poruka je poslana. Javit ćemo se u roku 1–2 radna dana.',
      err: 'Došlo je do pogreške — molimo pošaljite e-mail izravno na info@analyze-design.hr.'
    }
  };

  function setStatus(el, text, kind) {
    if (!el) return;
    el.textContent = text;
    el.className = 'form-status' + (kind ? ' ' + kind : '');
  }

  function bind(form) {
    var lang = messages[form.getAttribute('data-lang')] ? form.getAttribute('data-lang') : 'en';
    var t = messages[lang];
    var statusEl = form.querySelector('.form-status');
    var submitBtn = form.querySelector('button[type="submit"]');
    var submitLabel = submitBtn ? submitBtn.textContent : '';

    form.addEventListener('submit', function (e) {
      e.preventDefault();

      var formData = new FormData(form);
      formData.set('type', form.getAttribute('data-type') || '');
      formData.set('lang', lang);

      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = t.sending;
      }
      setStatus(statusEl, t.sending, '');

      fetch('/send-mail.php', { method: 'POST', body: formData })
        .then(function (res) {
          return res.json().catch(function () { return { ok: false }; });
        })
        .then(function (data) {
          if (data && data.ok) {
            setStatus(statusEl, t.ok, 'ok');
            form.reset();
          } else {
            setStatus(statusEl, t.err, 'err');
          }
        })
        .catch(function () {
          setStatus(statusEl, t.err, 'err');
        })
        .finally(function () {
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = submitLabel;
          }
        });
    });
  }

  document.querySelectorAll('form[data-type]').forEach(bind);
})();
