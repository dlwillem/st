/* DKG SelectieTool V2 — client helpers (placeholder). */

(function () {
  'use strict';

  // CSRF-token opnemen in elke AJAX POST
  const meta = document.querySelector('meta[name="csrf-token"]');
  window.DKG = window.DKG || {};
  window.DKG.csrfToken = meta ? meta.content : '';

  // Klikbare tabelrijen: <tr class="row-link" data-href="/url">…</tr>
  // Cellen met [data-no-rowlink] zijn uitgesloten, net als interactieve elementen.
  document.addEventListener('click', function (e) {
    const tr = e.target.closest('tr.row-link');
    if (!tr) return;
    if (e.target.closest('[data-no-rowlink]')) return;
    if (e.target.closest('a,button,form,input,textarea,select,label')) return;
    const href = tr.dataset.href;
    if (!href) return;
    if (e.metaKey || e.ctrlKey || e.button === 1) {
      window.open(href, '_blank');
    } else {
      window.location = href;
    }
  });

  window.DKG.postJSON = function (url, data) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': window.DKG.csrfToken,
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify(data || {}),
    }).then((r) => r.json());
  };
})();
