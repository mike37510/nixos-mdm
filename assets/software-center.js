(() => {
  const cfg = window.LINUX_MDM_SC || window.NIX_MDM_SC || {};
  const token = cfg.token;
  if (!token) return;

  const AGENT = 'http://127.0.0.1:17380';
  const POLL_MS = 12000;
  const LOG_KEY = 'linux-mdm-sc-log';
  const LOG_MAX = 200;
  let syncTimer = null;
  let syncBusy = false;
  let refreshBusy = false;
  let lastSyncAt = null;
  let pollAfterSync = null;
  let journalPoll = null;
  /** @type {Map<string, 'install'|'remove'>} */
  const pendingOps = new Map();
  let clientEvents = loadEvents();

  const api = (action, opts = {}) => {
    const url = new URL('client-api.php', window.location.href);
    url.searchParams.set('action', action);
    url.searchParams.set('token', token);
    url.searchParams.set('_', String(Date.now()));
    const init = {
      method: opts.method || 'GET',
      headers: { 'Cache-Control': 'no-cache', Pragma: 'no-cache' },
      cache: 'no-store',
    };
    if (opts.body) {
      init.method = 'POST';
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify({ token, ...opts.body });
    }
    return fetch(url, init).then(async (r) => {
      const data = await r.json().catch(() => ({}));
      if (!r.ok) throw new Error(data.error || 'Erreur réseau');
      return data;
    });
  };

  const agentFetch = (path, opts = {}) => {
    const init = {
      method: opts.method || 'GET',
      headers: {
        Authorization: 'Bearer ' + token,
        'Cache-Control': 'no-cache',
        ...(opts.headers || {}),
      },
      cache: 'no-store',
    };
    if (opts.body !== undefined) {
      init.method = 'POST';
      init.headers['Content-Type'] = 'application/json';
      init.body = typeof opts.body === 'string' ? opts.body : JSON.stringify(opts.body || {});
    }
    return fetch(AGENT + path, init).then(async (r) => {
      const data = await r.json().catch(() => ({}));
      if (!r.ok) throw new Error(data.error || 'agent ' + r.status);
      return data;
    });
  };

  const banner = document.getElementById('sc-banner');
  const syncBtn = document.getElementById('sc-sync-now');
  const syncLabel = syncBtn?.querySelector('.sc-sync-label') || syncBtn;
  const syncStatus = document.getElementById('sc-sync-status');
  const logEl = document.getElementById('sc-log');
  const logStatus = document.getElementById('sc-log-status');

  const markPending = (attr, kind) => {
    pendingOps.set(attr, kind);
    applyPendingCards();
  };
  const clearPending = () => {
    pendingOps.clear();
    applyPendingCards();
  };
  const applyPendingCards = () => {
    document.querySelectorAll('.sw-card[data-attr]').forEach((el) => {
      const attr = el.getAttribute('data-attr') || '';
      const kind = pendingOps.get(attr);
      el.classList.toggle('is-pending', !!kind);
      let label = el.querySelector('.sw-pending-label');
      if (kind) {
        if (!label) {
          label = document.createElement('p');
          label.className = 'sw-pending-label';
          el.querySelector('.sw-body')?.appendChild(label);
        }
        label.textContent = kind === 'remove' ? 'Retrait en cours…' : 'Installation en cours…';
      } else if (label) {
        label.remove();
      }
    });
  };

  function loadEvents() {
    try {
      const raw = sessionStorage.getItem(LOG_KEY);
      const arr = raw ? JSON.parse(raw) : [];
      return Array.isArray(arr) ? arr.slice(-LOG_MAX) : [];
    } catch (_) {
      return [];
    }
  }

  function saveEvents() {
    try {
      sessionStorage.setItem(LOG_KEY, JSON.stringify(clientEvents.slice(-LOG_MAX)));
    } catch (_) { /* ignore quota */ }
  }

  const log = (msg, level = 'info') => {
    const ts = new Date().toLocaleTimeString('fr-FR', { hour12: false });
    clientEvents.push({ ts, level, msg: String(msg) });
    if (clientEvents.length > LOG_MAX) clientEvents = clientEvents.slice(-LOG_MAX);
    saveEvents();
    renderLog();
  };

  let agentLines = [];
  let agentActive = null;
  let agentReachable = null;

  const renderLog = () => {
    if (!logEl) return;
    const parts = [];
    parts.push('── Événements Centre Logiciels ──');
    if (!clientEvents.length) {
      parts.push('(aucun événement pour l’instant)');
    } else {
      clientEvents.forEach((e) => {
        const tag = e.level === 'err' ? 'ERR' : e.level === 'ok' ? 'OK ' : e.level === 'sys' ? 'SYS' : 'INF';
        parts.push(`[${e.ts}] ${tag}  ${e.msg}`);
      });
    }
    parts.push('');
    parts.push('── Journal sync locale (agent) ──');
    if (agentReachable === false) {
      parts.push('(agent local inaccessible — republier le profil / sync pour activer /sync-log)');
    } else if (!agentLines.length) {
      parts.push('(aucune ligne — lancez une synchronisation)');
    } else {
      agentLines.forEach((line) => parts.push(line));
    }
    logEl.textContent = parts.join('\n');
    logEl.scrollTop = logEl.scrollHeight;
    if (logStatus) {
      if (agentReachable === false) {
        logStatus.textContent = 'Agent local : hors ligne';
      } else if (agentActive === true) {
        logStatus.textContent = 'Sync systemd : active (nixos-rebuild en cours)';
      } else if (agentActive === false) {
        logStatus.textContent = 'Sync systemd : inactive';
      } else {
        logStatus.textContent = 'Journal prêt';
      }
    }
  };

  const pullAgentLog = () => agentFetch('/sync-log?n=120')
    .then((data) => {
      agentReachable = true;
      agentActive = !!data.active;
      agentLines = Array.isArray(data.lines) ? data.lines : [];
      renderLog();
      return data;
    })
    .catch(() => {
      // Ancien agent sans /sync-log : tenter /health
      return agentFetch('/health')
        .then(() => {
          agentReachable = true;
          agentActive = null;
          if (!agentLines.length) {
            agentLines = ['Agent joignable, mais /sync-log absent — republiez le profil MDM puis synchronisez le poste.'];
          }
          renderLog();
          return null;
        })
        .catch(() => {
          agentReachable = false;
          agentActive = null;
          renderLog();
          return null;
        });
    });

  const showMsg = (text, isErr = false) => {
    if (!banner) return;
    banner.hidden = false;
    banner.className = 'banner ' + (isErr ? 'error' : 'info');
    banner.textContent = text;
    log(text, isErr ? 'err' : 'info');
  };

  const setSyncBusy = (busy, detail) => {
    syncBusy = busy;
    if (syncBtn) {
      syncBtn.disabled = busy;
      syncBtn.classList.toggle('is-busy', busy);
      if (syncLabel) syncLabel.textContent = busy ? 'Sync…' : 'Synchroniser';
    }
    if (syncStatus) {
      if (busy) {
        syncStatus.hidden = false;
        syncStatus.textContent = detail || 'Synchronisation…';
      } else {
        syncStatus.hidden = true;
        syncStatus.textContent = '';
      }
    }
    if (busy && !journalPoll) {
      journalPoll = setInterval(() => { pullAgentLog(); }, 3000);
      pullAgentLog();
    }
    if (!busy && journalPoll) {
      clearInterval(journalPoll);
      journalPoll = null;
      pullAgentLog();
      clearPending();
    }
  };

  const refresh = (opts = {}) => {
    if (refreshBusy && !opts.force) return Promise.resolve();
    refreshBusy = true;
    return Promise.all([api('status'), api('catalog')]).then(([status, catalog]) => {
      const hostEl = document.getElementById('sc-host');
      const lineEl = document.getElementById('sc-profile-line');
      if (hostEl) {
        hostEl.textContent =
          (status.hostname || 'Poste') + ' · ' + (status.profile?.label || status.profile?.slug || '');
      }
      if (lineEl) {
        lineEl.textContent =
          'Profil « ' + (status.profile?.label || '') + ' »'
          + (status.last_sync_at ? ' · dernier sync ' + status.last_sync_at : '');
      }
      const prev = lastSyncAt;
      lastSyncAt = status.last_sync_at || null;
      fill('list-mandatory', status.mandatory || [], 'mandatory');
      fill('list-optional', status.optional || [], 'optional');
      fill('list-store', catalog.items || [], 'store');
      if (opts.notifySyncDone && prev && lastSyncAt && prev !== lastSyncAt) {
        showMsg('Synchronisation terminée — liste mise à jour.');
        log('last_sync_at = ' + lastSyncAt, 'ok');
      }
      return status;
    }).catch((e) => {
      if (!opts.silent) showMsg(e.message, true);
      else log('Rafraîchissement : ' + e.message, 'err');
    }).finally(() => { refreshBusy = false; });
  };

  const watchSyncCompletion = (baseline) => {
    if (pollAfterSync) clearInterval(pollAfterSync);
    const started = Date.now();
    let tries = 0;
    setSyncBusy(true, 'nixos-rebuild en cours…');
    pollAfterSync = setInterval(() => {
      tries += 1;
      const elapsed = Math.round((Date.now() - started) / 1000);
      if (syncStatus && syncBusy) {
        syncStatus.textContent = agentActive
          ? `nixos-rebuild · ${elapsed}s`
          : `Sync · ${elapsed}s`;
      }
      refresh({ silent: true, force: true, notifySyncDone: true }).then((status) => {
        const cur = status?.last_sync_at || null;
        const hubDone = (baseline && cur && cur !== baseline) || (!baseline && cur);
        const localDone = agentReachable === true && agentActive === false && tries >= 2;
        if (hubDone || (localDone && tries >= 3) || tries >= 45) {
          clearInterval(pollAfterSync);
          pollAfterSync = null;
          setSyncBusy(false);
          if (tries >= 45 && baseline && cur === baseline) {
            showMsg('Sync lancée — la liste se mettra à jour au prochain cycle.');
          } else if (hubDone) {
            log('Sync terminée (hub last_sync_at mis à jour)', 'ok');
          }
        }
      });
      if (Date.now() - started > 12 * 60 * 1000) {
        clearInterval(pollAfterSync);
        pollAfterSync = null;
        setSyncBusy(false);
        log('Timeout surveillance sync (12 min)', 'err');
      }
    }, 3500);
  };

  const runSyncNow = async () => {
    if (syncBusy) return;
    const baseline = lastSyncAt;
    setSyncBusy(true, 'Démarrage de la synchronisation…');
    log('Demande de sync immédiate', 'sys');
    try {
      const data = await agentFetch('/sync-now', { method: 'POST', body: '{}' });
      showMsg(data.message || 'Synchronisation démarrée — installation en cours…');
      watchSyncCompletion(baseline);
      return;
    } catch (e1) {
      log('Agent /sync-now : ' + e1.message + ' — repli protocole', 'sys');
      try {
        const iframe = document.createElement('iframe');
        iframe.style.display = 'none';
        iframe.src = 'linux-mdm://sync-now';
        document.body.appendChild(iframe);
        setTimeout(() => iframe.remove(), 2500);
        showMsg('Synchronisation demandée — installation en cours…');
        watchSyncCompletion(baseline);
      } catch (e2) {
        setSyncBusy(false);
        showMsg(
          'Impossible de démarrer la sync. Essayez : systemctl start nix-builder-mdm-sync.service',
          true
        );
      }
    }
  };

  const scheduleSyncNow = () => {
    if (syncTimer) clearTimeout(syncTimer);
    log('Sync planifiée dans 2,5 s', 'sys');
    syncTimer = setTimeout(() => {
      syncTimer = null;
      runSyncNow();
    }, 2500);
  };

  if (syncBtn) {
    syncBtn.addEventListener('click', () => {
      if (syncTimer) {
        clearTimeout(syncTimer);
        syncTimer = null;
      }
      runSyncNow();
    });
  }

  document.getElementById('sc-log-refresh')?.addEventListener('click', () => {
    log('Actualisation manuelle du journal', 'sys');
    pullAgentLog();
  });
  document.getElementById('sc-log-clear')?.addEventListener('click', () => {
    clientEvents = [];
    saveEvents();
    agentLines = [];
    renderLog();
  });

  const icon = (attr) => {
    const letter = (attr.replace(/[^a-zA-Z0-9]/g, '')[0] || 'P').toUpperCase();
    let h = 0;
    for (let i = 0; i < attr.length; i++) h = (h + attr.charCodeAt(i) * (i + 1)) % 360;
    return `<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40"><rect width="40" height="40" rx="10" fill="hsl(${h} 42% 38%)"/><text x="20" y="26" text-anchor="middle" font-family="system-ui,sans-serif" font-size="18" font-weight="700" fill="#fff">${letter}</text></svg>`;
  };

  const card = (item, mode) => {
    const el = document.createElement('article');
    el.className = 'sw-card';
    el.setAttribute('data-attr', item.attr || '');
    const version = item.version ? `<span class="sw-version">${escapeHtml(item.version)}</span>` : '';
    const desc = item.description
      ? `<p class="sw-desc">${escapeHtml(truncate(item.description, 120))}</p>`
      : '';
    let actions = '';
    let status = '';
    if (mode === 'mandatory') {
      status = '<p class="sw-status">Profil — installé automatiquement</p>';
    } else if (mode === 'optional') {
      status = '<p class="sw-status">Magasin — sur ce poste</p>';
      actions = `<div class="sc-actions"><button type="button" class="btn small danger" data-remove="${escapeAttr(item.attr)}">Retirer</button></div>`;
    } else if (item.state === 'mandatory') {
      status = '<p class="sw-status">Déjà dans le profil</p>';
    } else if (item.state === 'installed') {
      status = '<p class="sw-status">Déjà choisi</p>';
      actions = `<div class="sc-actions"><button type="button" class="btn small danger" data-remove="${escapeAttr(item.attr)}">Retirer</button></div>`;
    } else {
      actions = `<div class="sc-actions"><button type="button" class="btn small primary" data-install="${escapeAttr(item.attr)}">Installer</button></div>`;
    }
    el.innerHTML = `
      <div class="sw-icon">${item.icon_url ? `<img src="${escapeAttr(item.icon_url)}" width="40" height="40" alt="">` : icon(item.attr)}<span class="sw-card-spin" aria-hidden="true"></span></div>
      <div class="sw-body">
        <h3>${escapeHtml(item.label || item.attr)}</h3>
        <p class="sw-meta"><code>${escapeHtml(item.attr)}</code>${version}</p>
        ${desc}${status}${actions}
      </div>`;
    return el;
  };

  const fill = (id, items, mode) => {
    const box = document.getElementById(id);
    if (!box) return;
    box.innerHTML = '';
    if (!items.length) {
      box.innerHTML = '<div class="sc-empty">Aucun élément</div>';
      return;
    }
    items.forEach((it) => box.appendChild(card(it, mode)));
    applyPendingCards();
  };

  document.querySelectorAll('.sc-tab').forEach((btn) => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.sc-tab').forEach((b) => b.classList.toggle('is-active', b === btn));
      const tab = btn.dataset.tab;
      document.getElementById('panel-installed').hidden = tab !== 'installed';
      document.getElementById('panel-store').hidden = tab !== 'store';
      document.getElementById('panel-log').hidden = tab !== 'log';
      if (tab === 'log') pullAgentLog();
      else refresh({ silent: true, force: true });
    });
  });

  document.addEventListener('click', (ev) => {
    const t = ev.target;
    if (!(t instanceof HTMLElement)) return;
    const install = t.getAttribute('data-install');
    const remove = t.getAttribute('data-remove');
    if (install) {
      t.disabled = true;
      markPending(install, 'install');
      log('Install demandé : ' + install, 'sys');
      api('install', { body: { attr: install } })
        .then((r) => {
          showMsg(r.message || 'Enregistré — sync dans un instant…');
          scheduleSyncNow();
          return refresh({ force: true });
        })
        .catch((e) => {
          pendingOps.delete(install);
          applyPendingCards();
          showMsg(e.message, true);
        })
        .finally(() => { t.disabled = false; });
    }
    if (remove) {
      t.disabled = true;
      markPending(remove, 'remove');
      log('Retrait demandé : ' + remove, 'sys');
      api('remove', { body: { attr: remove } })
        .then((r) => {
          showMsg(r.message || 'Retrait enregistré — sync dans un instant…');
          scheduleSyncNow();
          return refresh({ force: true });
        })
        .catch((e) => {
          pendingOps.delete(remove);
          applyPendingCards();
          showMsg(e.message, true);
        })
        .finally(() => { t.disabled = false; });
    }
  });

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') refresh({ silent: true, force: true });
  });
  window.addEventListener('focus', () => refresh({ silent: true, force: true }));
  setInterval(() => {
    if (document.visibilityState === 'visible') refresh({ silent: true });
  }, POLL_MS);

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  function escapeAttr(s) { return escapeHtml(s).replace(/`/g, ''); }
  function truncate(s, n) { return s.length > n ? s.slice(0, n - 1) + '…' : s; }

  log('Centre Logiciels ouvert', 'sys');
  renderLog();
  refresh({ force: true });
  pullAgentLog();
})();
