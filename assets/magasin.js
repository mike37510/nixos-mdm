'use strict';

(() => {
  const boot = window.LINUX_MDM_MAGASIN || {};
  const query = document.getElementById('magasin-query');
  const results = document.getElementById('magasin-results');
  const attrInput = document.getElementById('magasin-attr');
  const labelInput = document.getElementById('magasin-label');
  const descInput = document.getElementById('magasin-description');
  const form = document.getElementById('magasin-add-form');
  if (!query || !results || !form) return;

  const release = boot.release || '26.05';
  const existing = new Set(Array.isArray(boot.existing) ? boot.existing : []);
  let lastResults = [];
  let searchSeq = 0;

  function fillForm(r) {
    attrInput.value = r.attr || '';
    if (labelInput && (!labelInput.value || labelInput.dataset.auto === '1')) {
      labelInput.value = r.attr || '';
      labelInput.dataset.auto = '1';
    }
    if (descInput && (!descInput.value || descInput.dataset.auto === '1')) {
      descInput.value = r.description || '';
      descInput.dataset.auto = '1';
    }
  }

  function renderResults() {
    if (!lastResults.length) return;
    results.innerHTML = lastResults.map((r, i) => {
      const on = existing.has(r.attr);
      return `<button type="button" class="result${on ? ' on' : ''}" data-idx="${i}">
        <span class="r-main"><span class="mono">${esc(r.attr)}</span> <small>${esc(r.version || '')}</small>
        ${r.unfree ? '<span class="badge unfree">unfree</span>' : ''}</span>
        <span class="r-desc">${esc(r.description || '')}</span>
        <span class="r-add">${on ? 'Déjà au catalogue' : 'Sélectionner'}</span></button>`;
    }).join('');
  }

  const doSearch = debounce(async () => {
    const q = query.value.trim();
    const seq = ++searchSeq;
    if (q.length < 2) {
      results.hidden = true;
      lastResults = [];
      return;
    }
    results.hidden = false;
    results.innerHTML = '<div class="r-info">Recherche dans nixpkgs…</div>';
    try {
      const res = await api.get('search', { q, release });
      if (seq !== searchSeq) return;
      lastResults = res.results || [];
      if (!lastResults.length) {
        results.innerHTML = `<div class="r-info">Aucun résultat pour « ${esc(q)} ». Saisissez l’attribut exact ci-dessous.</div>`;
      } else {
        renderResults();
      }
    } catch (e) {
      if (seq !== searchSeq) return;
      results.innerHTML = `<div class="r-info error">${esc(e.message)}</div>`;
    }
  }, 250);

  query.addEventListener('input', doSearch);
  query.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') results.hidden = true;
    if (e.key === 'Enter') {
      e.preventDefault();
      if (lastResults[0]) {
        fillForm(lastResults[0]);
        results.hidden = true;
        query.value = '';
        attrInput.focus();
      }
    }
  });

  results.addEventListener('click', (e) => {
    const b = e.target.closest('[data-idx]');
    if (!b) return;
    const r = lastResults[Number(b.dataset.idx)];
    if (!r) return;
    fillForm(r);
    results.hidden = true;
    query.value = '';
    if (existing.has(r.attr)) {
      toast(`« ${r.attr} » est déjà au catalogue`, 'info');
    } else {
      toast(`« ${r.attr} » sélectionné — validez pour ajouter`, 'info');
      attrInput.focus();
    }
  });

  document.addEventListener('click', (e) => {
    if (!e.target.closest('#magasin-search')) results.hidden = true;
  });

  labelInput?.addEventListener('input', () => { labelInput.dataset.auto = '0'; });
  descInput?.addEventListener('input', () => { descInput.dataset.auto = '0'; });
})();
