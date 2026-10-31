'use strict';

(() => {
  const boot = window.NIXVM;
  const cat = boot.catalog;
  const form = document.getElementById('form');
  const $ = (sel) => document.querySelector(sel);

  const state = {
    id: boot.id,
    hasPassword: boot.hasPassword,
    packages: [...boot.spec.packages],
    services: [...boot.spec.services],
    unfree: new Set(cat.unfree),
    missing: new Set(),
    meta: {},
    errors: {},
    buildsTimer: null,
    logTimer: null,
  };

  const ATTR_RE = /^[A-Za-z_][A-Za-z0-9_-]*(\.[A-Za-z_][A-Za-z0-9_-]*){0,4}$/;

  // ---------------------------------------------------------------- formulaire

  function setPath(obj, path, value) {
    const keys = path.split('.');
    let o = obj;
    keys.slice(0, -1).forEach((k) => { o = o[k] ??= {}; });
    o[keys.at(-1)] = value;
  }

  function collect() {
    const spec = {};
    form.querySelectorAll('[data-field]').forEach((el) => {
      if (el.type === 'radio') {
        if (el.checked) setPath(spec, el.dataset.field, el.value);
      } else if (el.type === 'checkbox') {
        setPath(spec, el.dataset.field, el.checked);
      } else {
        setPath(spec, el.dataset.field, el.value);
      }
    });
    spec.base = form.querySelector('input[name=base]:checked')?.value;
    spec.packages = state.packages;
    spec.services = state.services;
    return spec;
  }

  function field(path) {
    return form.querySelector(`[data-field="${path}"]`);
  }

  function setField(path, value) {
    const el = field(path);
    if (!el) return;
    if (el.type === 'checkbox') el.checked = !!value; else el.value = value;
  }

  // ---------------------------------------------------------------- image de base

  form.querySelectorAll('input[name=base]').forEach((radio) => {
    radio.addEventListener('change', () => {
      const b = cat.bases[radio.value];
      if (!b) return;
      const msg = `Appliquer le préréglage « ${b.label} » ?\n\nLes paquets, services, bureau, vCPU et mémoire seront remplacés.`;
      if (!confirm(msg)) {
        preview();
        return;
      }
      state.packages = [...b.packages];
      state.services = [...b.services];
      setField('desktop', b.desktop);
      setField('vm.cpus', b.cpus);
      setField('vm.memory', b.memory);
      renderPackages();
      renderServices();
      changed();
    });
  });

  // ---------------------------------------------------------------- paquets

  function addPackage(attr, meta) {
    if (!ATTR_RE.test(attr)) {
      toast(`Nom de paquet invalide : ${attr}`, 'error');
      return;
    }
    if (meta) state.meta[attr] = meta;
    if (meta?.unfree) state.unfree.add(attr);
    if (!state.packages.includes(attr)) {
      state.packages.push(attr);
      checkPackages();
    }
    if (state.unfree.has(attr) && !field('allowUnfree').checked) {
      field('allowUnfree').checked = true;
      toast(`${attr} n'est pas libre : allowUnfree activé`);
    }
    renderPackages();
    changed();
  }

  function removePackage(attr) {
    state.packages = state.packages.filter((p) => p !== attr);
    renderPackages();
    changed();
  }

  function togglePackage(attr, meta) {
    if (state.packages.includes(attr)) removePackage(attr); else addPackage(attr, meta);
  }

  function renderPackages() {
    $('#pkg-count').textContent = state.packages.length;
    $('#pkg-selected').innerHTML = state.packages.length
      ? state.packages.map((p) => {
        const warn = state.missing.has(p);
        const title = warn ? 'Introuvable dans ce canal nixpkgs : vérifiez le nom' : (state.meta[p]?.description || p);
        return `<span class="chip selected${warn ? ' warn' : ''}" title="${esc(title)}">
          <span class="mono">${esc(p)}</span>${state.unfree.has(p) ? '<span class="badge unfree">unfree</span>' : ''}
          <button type="button" data-remove="${esc(p)}" aria-label="Retirer ${esc(p)}">×</button></span>`;
      }).join('')
      : '<span class="muted">Aucun paquet sélectionné.</span>';

    $('#pkg-groups').innerHTML = Object.entries(cat.groups).map(([group, pkgs]) => `
      <div class="pkg-group"><h4>${esc(group)}</h4><div>
        ${pkgs.map((p) => `<button type="button" class="chip${state.packages.includes(p) ? ' on' : ''}" data-toggle="${esc(p)}">${esc(p)}</button>`).join('')}
      </div></div>`).join('');

    renderResults();
  }

  $('#pkg-selected').addEventListener('click', (e) => {
    const b = e.target.closest('[data-remove]');
    if (b) removePackage(b.dataset.remove);
  });
  $('#pkg-groups').addEventListener('click', (e) => {
    const b = e.target.closest('[data-toggle]');
    if (b) togglePackage(b.dataset.toggle);
  });

  let lastResults = [];
  let searchSeq = 0;
  const query = $('#pkg-query');
  const results = $('#pkg-results');

  function renderResults() {
    if (!lastResults.length) return;
    results.innerHTML = lastResults.map((r, i) => {
      const on = state.packages.includes(r.attr);
      return `<button type="button" class="result${on ? ' on' : ''}" data-idx="${i}">
        <span class="r-main"><span class="mono">${esc(r.attr)}</span> <small>${esc(r.version)}</small>
        ${r.unfree ? '<span class="badge unfree">unfree</span>' : ''}</span>
        <span class="r-desc">${esc(r.description)}</span>
        <span class="r-add">${on ? 'Ajouté' : 'Ajouter'}</span></button>`;
    }).join('');
  }

  const doSearch = debounce(async () => {
    const q = query.value.trim();
    const seq = ++searchSeq;
    if (q.length < 2) { results.hidden = true; lastResults = []; return; }
    results.hidden = false;
    results.innerHTML = '<div class="r-info">Recherche…</div>';
    try {
      const res = await api.get('search', { q, release: field('release').value });
      if (seq !== searchSeq) return;
      lastResults = res.results;
      res.results.forEach((r) => { if (r.unfree) state.unfree.add(r.attr); });
      if (!lastResults.length) {
        results.innerHTML = `<div class="r-info">Aucun résultat. Entrée pour ajouter « ${esc(q)} » tel quel.</div>`;
      } else {
        renderResults();
      }
    } catch (e) {
      if (seq !== searchSeq) return;
      results.innerHTML = `<div class="r-info error">${esc(e.message)} — vous pouvez saisir le nom exact puis Entrée.</div>`;
    }
  }, 250);

  query.addEventListener('input', doSearch);
  query.addEventListener('keydown', (e) => {
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
  results.addEventListener('click', (e) => {
    const b = e.target.closest('[data-idx]');
    if (!b) return;
    const r = lastResults[Number(b.dataset.idx)];
    togglePackage(r.attr, r);
  });
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.pkg-search')) results.hidden = true;
  });
  query.addEventListener('focus', () => { if (lastResults.length) results.hidden = false; });

  const checkPackages = debounce(async () => {
    try {
      const res = await api.post('check-packages', { packages: state.packages, release: field('release').value });
      state.missing = new Set(res.missing);
      renderPackages();
    } catch {
      state.missing = new Set();
    }
  }, 800);

  // ---------------------------------------------------------------- services

  function renderServices() {
    $('#services').innerHTML = Object.entries(cat.services).map(([key, s]) => `
      <label class="service${state.services.includes(key) ? ' on' : ''}">
        <input type="checkbox" data-service="${esc(key)}" ${state.services.includes(key) ? 'checked' : ''}>
        <span><strong>${esc(s.label)}</strong><small>${esc(s.description)}</small>
        ${s.ports.length ? `<em>port${s.ports.length > 1 ? 's' : ''} ${s.ports.join(', ')}</em>` : ''}</span>
      </label>`).join('');
  }

  $('#services').addEventListener('change', (e) => {
    const key = e.target.dataset.service;
    if (!key) return;
    state.services = e.target.checked ? [...state.services, key] : state.services.filter((s) => s !== key);
    e.target.closest('.service').classList.toggle('on', e.target.checked);
    changed();
  });

  // ---------------------------------------------------------------- réseau

  function syncNetwork() {
    const isStatic = form.querySelector('[data-field="network.mode"]:checked')?.value === 'static';
    $('#static-fields').hidden = !isStatic;
  }

  // ---------------------------------------------------------------- validation

  function renderErrors() {
    form.querySelectorAll('.invalid').forEach((el) => el.classList.remove('invalid'));
    const entries = Object.entries(state.errors);
    entries.forEach(([path]) => field(path)?.classList.add('invalid'));
    const box = $('#errors');
    box.hidden = !entries.length;
    box.innerHTML = entries.length
      ? `<strong>À corriger avant d'enregistrer :</strong><ul>${entries.map(([, m]) => `<li>${esc(m)}</li>`).join('')}</ul>`
      : '';
  }

  let previewSeq = 0;
  async function preview() {
    const seq = ++previewSeq;
    try {
      const res = await api.post('preview', { id: state.id, spec: collect() });
      if (seq !== previewSeq) return;
      state.errors = res.errors;
      if (state.hasPassword && state.errors['user.password'] && !field('user.password').value) {
        delete state.errors['user.password'];
      }
      renderErrors();
    } catch (e) {
      toast(e.message, 'error');
    }
  }
  const previewSoon = debounce(preview, 300);

  function changed() {
    syncNetwork();
    previewSoon();
  }

  form.addEventListener('input', (e) => {
    if (e.target === query) return;
    changed();
  });
  form.addEventListener('change', (e) => {
    if (e.target.name === 'base' || e.target.dataset.service || e.target === query) return;
    if (e.target.dataset.field === 'release') checkPackages();
    changed();
  });

  // ---------------------------------------------------------------- enregistrement / build

  async function save({ build = false } = {}) {
    const btn = $('#btn-build');
    btn.disabled = true;
    try {
      const wantBuild = !!build;
      const res = await api.post('save', {
        id: state.id,
        spec: collect(),
        build: wantBuild && boot.env.canBuild,
      });
      const isNew = !state.id;
      state.id = res.id;
      if (field('user.password')?.value) {
        state.hasPassword = true;
        field('user.password').value = '';
        field('user.password').placeholder = '••••••••';
      }
      if (isNew) history.replaceState(null, '', `edit.php?id=${res.id}`);
      $('#builds-panel').hidden = false;
      state.errors = {};
      renderErrors();
      if (wantBuild && !boot.env.canBuild) {
        toast('Enregistré. Nix absent : téléchargement des sources (Nix + Docker/Kasm)…');
        await downloadWithProgress(`download.php?config=${res.id}&format=ova`, 'ZIP sources Nix');
        await downloadWithProgress(`download.php?config=${res.id}&format=docker`, 'ZIP Docker / KasmVNC');
      } else if (wantBuild) {
        toast('Construction lancée — suivez la progression sur Mes projets', 'success');
        location.href = 'index.php';
        return res;
      } else {
        toast('Configuration enregistrée', 'success');
      }
      loadBuilds();
      return res;
    } catch (e) {
      if (e.data?.errors) {
        state.errors = e.data.errors;
        renderErrors();
        toast('Certains champs sont invalides', 'error');
      } else {
        toast(e.message, 'error');
      }
      throw e;
    } finally {
      btn.disabled = false;
    }
  }

  $('#btn-build').addEventListener('click', () => {
    save({ build: true }).catch(() => {});
  });
  if (!boot.env.canBuild) {
    $('#btn-build').textContent = 'Télécharger les sources';
    $('#btn-build').title = 'Nix n\'est pas installé : télécharge les ZIP Nix + Docker/Kasm à construire ailleurs';
  }
  // ---------------------------------------------------------------- builds

  const STATUS = {
    queued: 'En file', running: 'En cours', success: 'OVA + Docker prêts', failed: 'Échec', cancelled: 'Annulé',
  };
  const ART_LABEL = { ova: 'OVA', docker: 'Docker/Kasm', vmdk: 'VMDK', vmx: 'VMX', ovf: 'OVF', mf: 'Manifeste' };

  function duration(b) {
    if (!b.startedAt) return '';
    const end = b.finishedAt ? new Date(b.finishedAt.replace(' ', 'T')) : new Date();
    const s = Math.max(0, Math.round((end - new Date(b.startedAt.replace(' ', 'T'))) / 1000));
    return s >= 60 ? `${Math.floor(s / 60)} min ${s % 60} s` : `${s} s`;
  }

  function progressBlock(b) {
    if (!['queued', 'running'].includes(b.status)) return '';
    const p = b.progress || {};
    const pct = Math.max(0, Math.min(100, Number(p.percent) || 5));
    const label = p.label || STATUS[b.status];
    return `<div class="build-progress">
      <div class="build-progress-meta"><span>${esc(label)}</span><strong>${pct} %</strong></div>
      <div class="progress-track"><div class="progress-fill${pct < 8 ? ' indeterminate' : ''}" style="width:${Math.max(pct, 8)}%"></div></div>
    </div>`;
  }

  async function loadBuilds() {
    if (!state.id) return;
    clearTimeout(state.buildsTimer);
    try {
      const { builds } = await api.get('builds', { config: state.id });
      const building = builds.some((b) => ['queued', 'running'].includes(b.status));
      const buildBtn = $('#btn-build');
      buildBtn.disabled = building && boot.env.canBuild;
      if (building && boot.env.canBuild) {
        const active = builds.find((b) => ['queued', 'running'].includes(b.status));
        const p = active?.progress;
        buildBtn.title = p ? `${p.label} (${p.percent} %)` : 'Build en cours…';
        buildBtn.textContent = p ? `Construction… ${p.percent} %` : 'Construction…';
      } else if (!boot.env.canBuild) {
        buildBtn.textContent = 'Télécharger les sources';
        buildBtn.title = 'Nix n\'est pas installé : télécharge les ZIP Nix + Docker/Kasm à construire ailleurs';
      } else {
        buildBtn.title = '';
        buildBtn.textContent = 'Construire OVA + Docker';
      }
      $('#builds').innerHTML = builds.length ? builds.map((b) => `
        <div class="build" data-build="${b.id}">
          <div class="build-head">
            <span class="status ${b.status}">${STATUS[b.status]}</span>
            <span class="mono">#${b.id}</span>
            <small class="muted">${esc(b.createdAt)}${duration(b) ? ` · ${duration(b)}` : ''}</small>
          </div>
          ${progressBlock(b)}
          ${b.error ? `<div class="build-error">${esc(b.error)}</div>` : ''}
          ${b.artifacts.length ? `<div class="build-files">${b.artifacts
            .filter((a) => ['ova', 'docker', 'vmdk'].includes(a.ext))
            .sort((a, c) => {
              const order = { ova: 0, docker: 1, vmdk: 2 };
              return (order[a.ext] ?? 9) - (order[c.ext] ?? 9);
            })
            .map((a) => `<a class="btn small primary" href="download.php?build=${b.id}&file=${a.ext}" data-progress-download="Téléchargement ${ART_LABEL[a.ext] ?? a.ext}" title="${a.size.toLocaleString('fr-FR')} octets">${ART_LABEL[a.ext] ?? a.ext} <small>${formatBytes(a.size)}</small></a>`).join('')}</div>` : ''}
          <div class="build-actions">
            <button type="button" class="btn ghost small" data-log="${b.id}">Journal</button>
            ${['queued', 'running'].includes(b.status)
              ? `<button type="button" class="btn ghost small danger" data-cancel="${b.id}">Annuler</button>`
              : `<button type="button" class="btn ghost small danger" data-del="${b.id}">Supprimer</button>`}
          </div>
        </div>`).join('') : `<div class="muted pad">${boot.env.canBuild
        ? 'Aucun build. Cliquez sur « Enregistrer et construire l\'image ».'
        : 'Nix n\'est pas installé sur ce serveur : téléchargez le projet .zip et lancez ./build.sh sur une machine Linux avec Nix.'}</div>`;
      if (building) {
        state.buildsTimer = setTimeout(loadBuilds, 2000);
      }
    } catch (e) {
      $('#builds').innerHTML = `<div class="build-error">${esc(e.message)}</div>`;
    }
  }

  $('#builds').addEventListener('click', async (e) => {
    const t = e.target.closest('button');
    if (!t) return;
    try {
      if (t.dataset.log) openLog(Number(t.dataset.log));
      if (t.dataset.cancel && confirm('Annuler ce build ?')) {
        await api.post('cancel', { id: Number(t.dataset.cancel) });
        loadBuilds();
      }
      if (t.dataset.del && confirm('Supprimer ce build et ses fichiers ?')) {
        await api.post('delete-build', { id: Number(t.dataset.del) });
        loadBuilds();
      }
    } catch (err) {
      toast(err.message, 'error');
    }
  });

  const dialog = $('#log-dialog');
  async function openLog(buildId) {
    $('#log-title').textContent = `Journal du build #${buildId}`;
    $('#log-download').href = `download.php?build=${buildId}&file=log`;
    $('#log-body').textContent = 'Chargement…';
    if (!dialog.open) dialog.showModal();
    clearTimeout(state.logTimer);
    const tick = async () => {
      try {
        const res = await api.get('build', { id: buildId });
        const p = res.build.progress;
        $('#log-title').textContent = p && ['queued', 'running'].includes(res.build.status)
          ? `Journal #${buildId} — ${p.percent} % · ${p.label}`
          : `Journal du build #${buildId}`;
        const body = $('#log-body');
        const atBottom = body.scrollTop + body.clientHeight >= body.scrollHeight - 20;
        body.textContent = res.log || '(vide)';
        if (atBottom) body.scrollTop = body.scrollHeight;
        if (dialog.open && ['queued', 'running'].includes(res.build.status)) {
          state.logTimer = setTimeout(tick, 1500);
        }
      } catch (err) {
        $('#log-body').textContent = err.message;
      }
    };
    tick();
  }
  $('#log-close').addEventListener('click', () => dialog.close());
  dialog.addEventListener('close', () => clearTimeout(state.logTimer));

  // ---------------------------------------------------------------- init

  renderPackages();
  renderServices();
  syncNetwork();
  preview();
  checkPackages();
  loadBuilds();
})();
