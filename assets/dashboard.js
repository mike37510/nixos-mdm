'use strict';

function refreshProjectCount() {
  const list = document.querySelector('.vm-list');
  const count = list ? list.querySelectorAll('.vm-row').length : 0;
  const badge = document.querySelector('.section-title .count');
  if (badge) badge.textContent = String(count);
  if (list && count === 0) {
    list.replaceWith(Object.assign(document.createElement('div'), {
      className: 'empty',
      textContent: 'Aucun projet pour l\'instant. Choisissez une image de base ci-dessus pour commencer.',
    }));
  }
}

document.addEventListener('click', async (ev) => {
  const btn = ev.target.closest('[data-action]');
  if (!btn) return;
  const row = btn.closest('[data-id]');
  const id = Number(row.dataset.id);
  const name = row.querySelector('.vm-name').textContent;
  try {
    if (btn.dataset.action === 'delete') {
      if (!confirm(`Supprimer « ${name} » et toutes ses images construites ?`)) return;
      await api.post('delete', { id });
      row.remove();
      refreshProjectCount();
      toast('Projet supprimé');
    } else if (btn.dataset.action === 'duplicate') {
      const res = await api.post('duplicate', { id });
      location.href = `edit.php?id=${res.id}`;
    }
  } catch (e) {
    toast(e.message, 'error');
  }
});

const STATUS_LABEL = {
  queued: 'En file',
  running: 'En cours',
  success: 'OVA + Docker prêts',
  failed: 'Échec',
  cancelled: 'Annulé',
};

function applyProgress(row, build) {
  const p = build.progress || {};
  const pct = Math.max(0, Math.min(100, Number(p.percent) || 0));
  const label = p.label || STATUS_LABEL[build.status] || build.status;
  const statusEl = row.querySelector('[data-status]');
  const detailEl = row.querySelector('[data-status-detail]');
  const labelEl = row.querySelector('[data-progress-label]');
  const pctEl = row.querySelector('[data-progress-pct]');
  const fill = row.querySelector('[data-progress-fill]');

  if (statusEl) {
    statusEl.className = `status ${build.status}`;
    statusEl.textContent = STATUS_LABEL[build.status] || build.status;
  }
  if (detailEl) detailEl.textContent = label;
  if (labelEl) labelEl.textContent = label;
  if (pctEl) pctEl.textContent = `${pct} %`;
  if (fill) {
    fill.style.width = `${Math.max(pct, 8)}%`;
    fill.classList.toggle('indeterminate', pct < 8);
  }
}

/** Progression inline sur la liste — sans bloquer la navigation. */
async function pollLiveBuilds() {
  const rows = [...document.querySelectorAll('.vm-row[data-build-id]')];
  if (!rows.length) return;

  let stillGoing = false;
  let finished = false;

  await Promise.all(rows.map(async (row) => {
    const buildId = Number(row.dataset.buildId);
    if (!buildId) return;
    try {
      const { build } = await api.get('build', { id: buildId });
      applyProgress(row, build);
      if (['queued', 'running'].includes(build.status)) {
        stillGoing = true;
      } else {
        finished = true;
      }
    } catch {
      stillGoing = true;
    }
  }));

  if (finished && !stillGoing) {
    location.reload();
    return;
  }
  if (stillGoing) {
    setTimeout(pollLiveBuilds, 2000);
  }
}

pollLiveBuilds();
