'use strict';

const api = {
  async get(action, params = {}) {
    const qs = new URLSearchParams({ action, ...params });
    return api._handle(await fetch(`/api.php?${qs}`, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    }));
  },
  async post(action, body = {}) {
    return api._handle(await fetch(`/api.php?action=${encodeURIComponent(action)}`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(body),
    }));
  },
  async _handle(res) {
    let data;
    try {
      data = await res.json();
    } catch {
      throw new Error(`Réponse invalide du serveur (HTTP ${res.status})`);
    }
    if (!res.ok) {
      const err = new Error(data.error || 'Erreur');
      err.data = data;
      err.status = res.status;
      throw err;
    }
    return data;
  },
};

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function formatBytes(n) {
  if (n == null) return '';
  const u = ['o', 'Ko', 'Mo', 'Go', 'To'];
  let i = 0;
  while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
  return `${n.toLocaleString('fr-FR', { maximumFractionDigits: i > 1 ? 1 : 0 })} ${u[i]}`;
}

let toastTimer;
function toast(message, kind = 'info') {
  const el = document.getElementById('toast');
  el.textContent = message;
  el.className = `toast show ${kind}`;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { el.className = 'toast'; }, kind === 'error' ? 6000 : 3000);
}

function debounce(fn, ms) {
  let t;
  return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
}

function setProgress(percent, label, title) {
  const dialog = document.getElementById('progress-dialog');
  if (!dialog) return;
  const pct = Math.max(0, Math.min(100, Math.round(percent)));
  if (title) document.getElementById('progress-title').textContent = title;
  if (label != null) document.getElementById('progress-label').textContent = label;
  document.getElementById('progress-fill').style.width = `${pct}%`;
  document.getElementById('progress-pct').textContent = `${pct} %`;
  document.getElementById('progress-fill').classList.toggle('indeterminate', pct > 0 && pct < 8);
}

function openProgress(title, label = 'Veuillez patienter…') {
  const dialog = document.getElementById('progress-dialog');
  if (!dialog) return;
  setProgress(5, label, title);
  if (!dialog.open) dialog.showModal();
}

function closeProgress() {
  const dialog = document.getElementById('progress-dialog');
  if (dialog?.open) dialog.close();
}

/**
 * Télécharge une URL en affichant une barre de progression (génération + transfert).
 */
async function downloadWithProgress(url, title = 'Téléchargement') {
  openProgress(title, 'Génération du fichier…');
  let fake = 8;
  const tick = setInterval(() => {
    fake = Math.min(35, fake + 2);
    setProgress(fake, 'Génération du fichier…', title);
  }, 400);
  try {
    const res = await fetch(url, { headers: { Accept: '*/*' } });
    clearInterval(tick);
    if (!res.ok) {
      throw new Error(`Échec du téléchargement (HTTP ${res.status})`);
    }
    const total = Number(res.headers.get('Content-Length')) || 0;
    const reader = res.body?.getReader();
    let received = 0;
    const chunks = [];
    if (!reader) {
      setProgress(70, 'Réception…', title);
      const blob = await res.blob();
      triggerBlobDownload(blob, filenameFromResponse(res, url));
      setProgress(100, 'Terminé', title);
      setTimeout(closeProgress, 400);
      return;
    }
    setProgress(total ? 40 : 45, 'Téléchargement…', title);
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      chunks.push(value);
      received += value.byteLength;
      if (total > 0) {
        const pct = 40 + Math.round((received / total) * 55);
        setProgress(pct, `Téléchargement… ${formatBytes(received)} / ${formatBytes(total)}`, title);
      } else {
        setProgress(Math.min(92, 45 + Math.round(received / 50000)), `Téléchargement… ${formatBytes(received)}`, title);
      }
    }
    const blob = new Blob(chunks);
    triggerBlobDownload(blob, filenameFromResponse(res, url));
    setProgress(100, 'Terminé', title);
    setTimeout(closeProgress, 450);
  } catch (err) {
    clearInterval(tick);
    closeProgress();
    throw err;
  }
}

function filenameFromResponse(res, fallbackUrl) {
  const cd = res.headers.get('Content-Disposition') || '';
  const m = /filename="?([^";]+)"?/i.exec(cd);
  if (m) return m[1];
  try {
    return new URL(fallbackUrl, location.href).pathname.split('/').pop() || 'download.zip';
  } catch {
    return 'download.zip';
  }
}

function triggerBlobDownload(blob, name) {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = name;
  a.rel = 'noopener';
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 30_000);
}

document.addEventListener('click', async (ev) => {
  const a = ev.target.closest('[data-progress-download]');
  if (!a || !a.href) return;
  ev.preventDefault();
  try {
    await downloadWithProgress(a.href, a.dataset.progressDownload || 'Téléchargement');
  } catch (e) {
    toast(e.message || String(e), 'error');
  }
});
