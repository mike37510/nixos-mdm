'use strict';

(() => {
  const boot = window.LINUX_MDM || window.NIX_MDM || {};
  const form = document.getElementById('mdm-form');
  if (!form) return;

  const $ = (sel) => document.querySelector(sel);
  const state = {
    packages: Array.isArray(boot.packages) ? [...boot.packages] : [],
    unfree: new Set(),
  };

  const ATTR_RE = /^[A-Za-z_][A-Za-z0-9_-]*(\.[A-Za-z_][A-Za-z0-9_-]*){0,6}$/;
  const release = boot.release || '26.05';

  function syncHidden() {
    const hidden = $('#packages-field');
    if (hidden) hidden.value = state.packages.join('\n');
    const count = $('#pkg-count');
    if (count) count.textContent = String(state.packages.length);
  }

  function renderPackages() {
    const box = $('#pkg-selected');
    if (!box) return;
    box.innerHTML = state.packages.length
      ? state.packages.map((p) => `<span class="chip selected" title="${esc(p)}">
          <span class="mono">${esc(p)}</span>${state.unfree.has(p) ? '<span class="badge unfree">unfree</span>' : ''}
          <button type="button" data-remove="${esc(p)}" aria-label="Retirer ${esc(p)}">×</button>
        </span>`).join('')
      : '<span class="muted">Aucun paquet. Recherchez ci-dessous pour en ajouter.</span>';
    syncHidden();
    renderResults();
  }

  function addPackage(attr, meta) {
    attr = String(attr || '').trim();
    if (!attr || !ATTR_RE.test(attr)) {
      toast('Nom de paquet invalide', 'error');
      return;
    }
    if (!state.packages.includes(attr)) {
      state.packages.push(attr);
      if (meta?.unfree) state.unfree.add(attr);
    }
    renderPackages();
  }

  function removePackage(attr) {
    state.packages = state.packages.filter((p) => p !== attr);
    renderPackages();
  }

  $('#pkg-selected')?.addEventListener('click', (e) => {
    const b = e.target.closest('[data-remove]');
    if (b) removePackage(b.dataset.remove);
  });

  let lastResults = [];
  let searchSeq = 0;
  const query = $('#pkg-query');
  const results = $('#pkg-results');

  function renderResults() {
    if (!results || !lastResults.length) return;
    results.innerHTML = lastResults.map((r, i) => {
      const on = state.packages.includes(r.attr);
      return `<button type="button" class="result${on ? ' on' : ''}" data-idx="${i}">
        <span class="r-main"><span class="mono">${esc(r.attr)}</span> <small>${esc(r.version || '')}</small>
        ${r.unfree ? '<span class="badge unfree">unfree</span>' : ''}</span>
        <span class="r-desc">${esc(r.description || '')}</span>
        <span class="r-add">${on ? 'Ajouté' : 'Ajouter'}</span></button>`;
    }).join('');
  }

  const doSearch = debounce(async () => {
    if (!query || !results) return;
    const q = query.value.trim();
    const seq = ++searchSeq;
    if (q.length < 2) {
      results.hidden = true;
      lastResults = [];
      return;
    }
    results.hidden = false;
    results.innerHTML = '<div class="r-info">Recherche…</div>';
    try {
      const res = await api.get('search', { q, release });
      if (seq !== searchSeq) return;
      lastResults = res.results || [];
      lastResults.forEach((r) => { if (r.unfree) state.unfree.add(r.attr); });
      if (!lastResults.length) {
        results.innerHTML = `<div class="r-info">Aucun résultat. Entrée pour ajouter « ${esc(q)} » tel quel.</div>`;
      } else {
        renderResults();
      }
    } catch (e) {
      if (seq !== searchSeq) return;
      results.innerHTML = `<div class="r-info error">${esc(e.message)} — saisissez le nom exact puis Entrée.</div>`;
    }
  }, 250);

  query?.addEventListener('input', doSearch);
  query?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      const q = query.value.trim();
      if (!q) return;
      const exact = lastResults.find((r) => r.attr === q);
      addPackage(exact ? exact.attr : q, exact);
      query.value = '';
      results.hidden = true;
      lastResults = [];
    } else if (e.key === 'Escape') {
      results.hidden = true;
    }
  });
  results?.addEventListener('click', (e) => {
    const b = e.target.closest('[data-idx]');
    if (!b) return;
    const r = lastResults[Number(b.dataset.idx)];
    if (r) addPackage(r.attr, r);
  });
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.pkg-search')) {
      if (results) results.hidden = true;
    }
  });

  form.addEventListener('submit', () => syncHidden());

  const select = $('#frequency_preset');
  const custom = $('#custom-freq');
  function syncCustom() {
    if (custom && select) custom.hidden = select.value !== 'custom';
  }
  select?.addEventListener('change', syncCustom);
  syncCustom();

  renderPackages();
})();
