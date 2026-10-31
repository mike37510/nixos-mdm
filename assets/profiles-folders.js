(() => {
  const board = document.getElementById('pf-board');
  if (!board) return;

  /** @type {HTMLElement|null} */
  let dragEl = null;
  /** @type {'item'|'folder'|null} */
  let dragKind = null;

  const currentProfileQs = () => {
    const m = window.location.search.match(/[?&]profile=(\d+)/);
    return m ? ('?profile=' + encodeURIComponent(m[1])) : '';
  };

  const flash = (text, isErr = false) => {
    let el = document.getElementById('pf-banner');
    if (!el) {
      el = document.createElement('div');
      el.id = 'pf-banner';
      board.parentElement?.insertBefore(el, board);
    }
    el.className = 'banner ' + (isErr ? 'error' : 'info');
    el.hidden = false;
    el.textContent = text;
  };

  const post = (fields) => {
    const body = new URLSearchParams(fields);
    body.set('ajax', '1');
    return fetch('/mdm/' + currentProfileQs(), {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
      },
      body,
      credentials: 'same-origin',
    }).then(async (r) => {
      const data = await r.json().catch(() => ({}));
      if (!r.ok || !data.ok) throw new Error(data.error || 'Déplacement impossible');
      return data;
    });
  };

  const ownDrop = (folder) => folder.querySelector(':scope > .mf-drop, :scope > .pf-drop');

  const ensureChildren = (folder) => {
    let box = folder.querySelector(':scope > .mf-folder-children');
    if (!box) {
      box = document.createElement('div');
      box.className = 'mf-folder-children';
      folder.appendChild(box);
    }
    return box;
  };

  const refreshCounts = () => {
    board.querySelectorAll('.mf-folder, .pf-folder').forEach((folder) => {
      const drop = ownDrop(folder);
      const countEl = folder.querySelector(':scope > .mf-folder-head .mf-count');
      if (!drop || !countEl) return;
      const n = drop.querySelectorAll(':scope > .mf-card, :scope > .pf-card').length;
      countEl.textContent = String(n);
      let empty = drop.querySelector(':scope > .mf-empty');
      if (n === 0) {
        if (!empty) {
          empty = document.createElement('div');
          empty.className = 'mf-empty';
          empty.textContent = 'Déposez des profils ici';
          drop.appendChild(empty);
        }
      } else if (empty) {
        empty.remove();
      }
    });
  };

  const clearOver = () => {
    board.querySelectorAll('.mf-drop.is-over, .pf-drop.is-over, .mf-folder.is-folder-over').forEach((z) => {
      z.classList.remove('is-over', 'is-folder-over');
    });
  };

  const resolveDrop = (target) => {
    if (!(target instanceof Element)) return null;
    const drop = target.closest('.mf-drop, .pf-drop');
    if (drop && board.contains(drop)) return drop;
    const folder = target.closest('.mf-folder, .pf-folder');
    if (folder && board.contains(folder)) return ownDrop(folder);
    return null;
  };

  const isDescendantFolder = (ancestor, maybeChild) => {
    if (!ancestor || !maybeChild) return false;
    if (ancestor === maybeChild) return true;
    return ancestor.contains(maybeChild);
  };

  board.addEventListener('dragstart', (ev) => {
    const t = ev.target;
    if (!(t instanceof Element) || !(ev instanceof DragEvent) || !ev.dataTransfer) return;

    if (t.closest('button, input, select, form, .pf-card-actions, .mf-folder-name')
      && !t.closest('.mf-folder-grip, .mf-card-grip')) {
      ev.preventDefault();
      return;
    }

    const folderGrip = t.closest('.mf-folder-grip');
    if (folderGrip) {
      const folder = folderGrip.closest('.mf-folder, .pf-folder');
      const fid = folder?.getAttribute('data-folder-id');
      if (!folder || !fid || fid === '0') {
        ev.preventDefault();
        return;
      }
      dragKind = 'folder';
      dragEl = folder;
      folder.classList.add('is-dragging');
      ev.dataTransfer.effectAllowed = 'move';
      ev.dataTransfer.setData('text/plain', 'folder:' + fid);
      ev.stopPropagation();
      return;
    }

    const card = t.closest('.pf-card, .mf-card');
    if (!card || !board.contains(card)) {
      if (t.closest('.mf-folder, .pf-folder') && !t.closest('.pf-card, .mf-card')) {
        ev.preventDefault();
      }
      return;
    }
    dragKind = 'item';
    dragEl = card;
    card.classList.add('is-dragging');
    ev.dataTransfer.effectAllowed = 'move';
    ev.dataTransfer.setData('text/plain', 'item:' + (card.getAttribute('data-profile-id') || ''));
    ev.stopPropagation();
  });

  board.addEventListener('dragend', () => {
    if (dragEl) dragEl.classList.remove('is-dragging');
    dragEl = null;
    dragKind = null;
    clearOver();
  });

  board.addEventListener('dragover', (ev) => {
    if (!dragEl || !dragKind) return;
    const drop = resolveDrop(ev.target);
    if (!drop) return;

    if (dragKind === 'folder') {
      const targetFolder = drop.closest('.mf-folder, .pf-folder');
      if (!targetFolder || isDescendantFolder(dragEl, targetFolder)) return;
    }

    ev.preventDefault();
    clearOver();
    drop.classList.add('is-over');
    drop.closest('.mf-folder, .pf-folder')?.classList.add('is-folder-over');
    if (ev instanceof DragEvent && ev.dataTransfer) ev.dataTransfer.dropEffect = 'move';
  });

  board.addEventListener('dragleave', (ev) => {
    const drop = resolveDrop(ev.target);
    if (!drop) return;
    if (ev instanceof DragEvent && ev.relatedTarget instanceof Node && drop.contains(ev.relatedTarget)) return;
    drop.classList.remove('is-over');
    drop.closest('.mf-folder, .pf-folder')?.classList.remove('is-folder-over');
  });

  board.addEventListener('drop', (ev) => {
    const drop = resolveDrop(ev.target);
    if (!drop || !dragEl || !dragKind) return;
    ev.preventDefault();
    ev.stopPropagation();
    clearOver();

    const targetFolderId = drop.getAttribute('data-folder-id') || '0';

    if (dragKind === 'item') {
      const pid = dragEl.getAttribute('data-profile-id');
      const fromFolder = dragEl.getAttribute('data-folder-id') || '0';
      if (!pid || targetFolderId === fromFolder) return;

      const empty = drop.querySelector(':scope > .mf-empty');
      if (empty) empty.remove();
      drop.appendChild(dragEl);
      dragEl.setAttribute('data-folder-id', targetFolderId);
      refreshCounts();

      post({
        action: 'move_folder',
        profile_id: pid,
        folder_id: targetFolderId === '0' ? '0' : targetFolderId,
      })
        .then(() => flash('Profil déplacé.'))
        .catch((e) => {
          flash(e.message || 'Erreur', true);
          window.location.reload();
        });
      return;
    }

    const movedId = dragEl.getAttribute('data-folder-id');
    if (!movedId || movedId === '0') return;
    const targetFolder = drop.closest('.mf-folder, .pf-folder');
    if (!targetFolder || isDescendantFolder(dragEl, targetFolder)) {
      flash('Impossible de déplacer un dossier dans lui-même.', true);
      return;
    }
    if (movedId === targetFolderId) return;

    const parentId = targetFolderId === '0' ? '0' : targetFolderId;
    if (parentId === '0') {
      board.appendChild(dragEl);
      dragEl.classList.remove('mf-folder-nested');
      dragEl.style.marginLeft = '';
    } else {
      ensureChildren(targetFolder).appendChild(dragEl);
      dragEl.classList.add('mf-folder-nested');
    }

    post({
      action: 'folder_move',
      folder_id: movedId,
      parent_id: parentId,
    })
      .then(() => flash('Dossier déplacé.'))
      .catch((e) => {
        flash(e.message || 'Erreur', true);
        window.location.reload();
      });
  });
})();
