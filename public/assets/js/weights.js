/* DKG SelectieTool — interactieve weight-sliders met proportionele
   herverdeling. Binnen elke groep (data-weight-group) wordt de som op 100
   gehouden: als slider X verandert, schalen de overige sliders mee.
*/
(function () {
  'use strict';

  function fmt(n) {
    return (Math.round(n * 10) / 10).toFixed(1);
  }

  function collect(groupEl) {
    return Array.from(groupEl.querySelectorAll('input[type=range][data-weight]'));
  }

  function updateLabel(sl) {
    const out = sl.parentElement.querySelector('[data-weight-value]');
    if (out) out.textContent = fmt(parseFloat(sl.value)) + '%';
  }

  function onInput(e) {
    const sl      = e.target;
    const group   = sl.closest('[data-weight-group]');
    if (!group) return;

    const all     = collect(group);
    if (all.length < 2) { updateLabel(sl); return; }

    const newVal  = Math.max(0, Math.min(100, parseFloat(sl.value) || 0));
    const others  = all.filter(x => x !== sl);

    // Som van de overige sliders vóór aanpassing (uit hun data-prev of value)
    const prevOthers = others.map(x => parseFloat(x.dataset.prev || x.value) || 0);
    const prevSum    = prevOthers.reduce((a, b) => a + b, 0);
    const target     = 100 - newVal;

    others.forEach((x, i) => {
      let v;
      if (prevSum > 0.0001) {
        v = prevOthers[i] * (target / prevSum);
      } else {
        v = target / others.length;
      }
      v = Math.max(0, Math.min(100, v));
      x.value = v;
      x.dataset.prev = v;
      updateLabel(x);
    });

    sl.value = newVal;
    sl.dataset.prev = newVal;
    updateLabel(sl);
    renderSum(group);
  }

  function renderSum(group) {
    const all = collect(group);
    const sum = all.reduce((a, x) => a + (parseFloat(x.value) || 0), 0);
    const out = group.querySelector('[data-weight-sum]');
    if (out) {
      out.textContent = fmt(sum) + '%';
      out.classList.toggle('off', Math.abs(sum - 100) > 0.5);
    }
  }

  function init() {
    document.querySelectorAll('[data-weight-group]').forEach(group => {
      collect(group).forEach(sl => {
        sl.dataset.prev = sl.value;
        updateLabel(sl);
        sl.addEventListener('input', onInput);
      });
      renderSum(group);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
