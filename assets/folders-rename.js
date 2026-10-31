(() => {
  const boards = document.querySelectorAll('[data-folder-rename]');
  if (!boards.length) return;

  const flashNear = (board, text, isErr = false) => {
    let el = board.parentElement?.querySelector('.mf-rename-banner');
    if (!el) {
      el = document.createElement('div');
      el.className = 'mf-rename-banner banner';
      board.parentElement?.insertBefore(el, board);
    }
    el.className = 'mf-rename-banner banner ' + (isErr ? 'error' : 'info');
    el.hidden = false;
    el.textContent = text;
  };

  const postRename = (url, folderId, name) => {
    const body = new URLSearchParams();
    body.set('action', 'folder_rename');
    body.set('ajax', '1');
    body.set('folder_id', String(folderId));
    body.set('folder_name', name);
    return fetch(url, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
      },
      body,
      credentials: 'same-origin',
    }).then(async (r) => {
      const data = await r.json().catch(() => ({}));
      if (!r.ok || !data.ok) throw new Error(data.error || 'Renommage impossible');
      return data;
    });
  };

  const endEdit = (input, btn, keepName) => {
    const name = keepName ? String(input.value || '').trim() : (btn.dataset.original || '');
    btn.textContent = name || btn.dataset.original || '';
    input.replaceWith(btn);
  };

  const startEdit = (btn, board) => {
    if (btn.dataset.editing === '1') return;
    const folderId = btn.getAttribute('data-folder-id');
    if (!folderId || folderId === '0') return;

    const original = (btn.textContent || '').trim();
    btn.dataset.original = original;
    btn.dataset.editing = '1';

    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'mf-folder-name-input';
    input.value = original;
    input.maxLength = 120;
    input.setAttribute('aria-label', 'Nom du dossier');
    btn.replaceWith(input);
    input.focus();
    input.select();

    let done = false;
    const finish = (save) => {
      if (done) return;
      done = true;
      const next = String(input.value || '').trim();
      if (!save || next === '' || next === original) {
        endEdit(input, btn, false);
        btn.dataset.editing = '0';
        return;
      }
      const url = board.getAttribute('data-folder-rename') || window.location.pathname;
      input.disabled = true;
      postRename(url, folderId, next)
        .then(() => {
          btn.dataset.original = next;
          endEdit(input, btn, true);
          btn.dataset.editing = '0';
          flashNear(board, 'Dossier renommé.');
        })
        .catch((e) => {
          endEdit(input, btn, false);
          btn.dataset.editing = '0';
          flashNear(board, e.message || 'Erreur', true);
        });
    };

    input.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter') {
        ev.preventDefault();
        finish(true);
      } else if (ev.key === 'Escape') {
        ev.preventDefault();
        finish(false);
      }
    });
    input.addEventListener('blur', () => finish(true));
  };

  boards.forEach((board) => {
    board.addEventListener('click', (ev) => {
      const t = ev.target;
      if (!(t instanceof Element)) return;
      const btn = t.closest('.mf-folder-name');
      if (!btn || !board.contains(btn)) return;
      ev.preventDefault();
      ev.stopPropagation();
      startEdit(btn, board);
    });
  });
})();
