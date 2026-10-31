(() => {
  const board = document.getElementById('mf-board');
  if (!board) return;

  /** @type {HTMLElement|null} */
  let dragEl = null;
  /** @type {'item'|'folder'|null} */
  let dragKind = null;

  const flash = (text, isErr = false) => {
    let el = document.getElementById('mf-banner');
    if (!el) {
      el = document.createElement('div');
      el.id = 'mf-banner';
      board.parentElement?.insertBefore(el, board);
    }
    el.className = 'banner ' + (isErr ? 'error' : 'info');
    el.hidden = false;
    el.textContent = text;
  };

  const post = (fields) => {
    const body = new URLSearchParams(fields);
    body.set('ajax', '1');
    return fetch('/mdm/machines.php', {
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

  const ownDrop = (folder) => folder.querySelector(':scope > .mf-drop');

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
    board.querySelectorAll('.mf-folder').forEach((folder) => {
      const drop = ownDrop(folder);
      const countEl = folder.querySelector(':scope > .mf-folder-head .mf-count');
      if (!drop || !countEl) return;
      const n = drop.querySelectorAll(':scope > .mf-card').length;
      countEl.textContent = String(n);
      let empty = drop.querySelector(':scope > .mf-empty');
      if (n === 0) {
        if (!empty) {
          empty = document.createElement('div');
          empty.className = 'mf-empty';
          empty.textContent = 'Déposez des machines ici';
          drop.appendChild(empty);
        }
      } else if (empty) {
        empty.remove();
      }
    });
  };

  const clearOver = () => {
    board.querySelectorAll('.mf-drop.is-over, .mf-folder.is-folder-over').forEach((z) => {
      z.classList.remove('is-over', 'is-folder-over');
    });
  };

  /** Zone de dépôt la plus profonde sous le curseur. */
  const resolveDrop = (target) => {
    if (!(target instanceof Element)) return null;
    const drop = target.closest('.mf-drop');
    if (drop && board.contains(drop)) return drop;
    const folder = target.closest('.mf-folder');
    if (folder && board.contains(folder)) return ownDrop(folder);
    return null;
  };

  const isDescendantFolder = (ancestor, maybeChild) => {
    if (!ancestor || !maybeChild || ancestor === maybeChild) return ancestor === maybeChild;
    return ancestor.contains(maybeChild);
  };

  board.addEventListener('dragstart', (ev) => {
    const t = ev.target;
    if (!(t instanceof Element) || !(ev instanceof DragEvent) || !ev.dataTransfer) return;

    if (t.closest('select, button, input, a, form.mf-assign, form.mf-folder-assign, form.mf-subfolder-create, .mf-folder-name')) {
      // autoriser grip dossier / carte
      if (!t.closest('.mf-folder-grip, .mf-card-grip, .mf-card')) {
        ev.preventDefault();
        return;
      }
    }

    const folderGrip = t.closest('.mf-folder-grip');
    if (folderGrip) {
      const folder = folderGrip.closest('.mf-folder');
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

    // Empêche le drag du dossier parent quand on tire une carte
    if (t.closest('.mf-folder') && t.closest('.mf-card')) {
      // ok
    }

    const card = t.closest('.mf-card');
    if (!card || !board.contains(card)) {
      // dragstart sur section dossier (draggable) hors grip → ignorer sauf grip
      if (t.closest('.mf-folder') && !t.closest('.mf-card')) {
        ev.preventDefault();
      }
      return;
    }
    dragKind = 'item';
    dragEl = card;
    card.classList.add('is-dragging');
    ev.dataTransfer.effectAllowed = 'move';
    ev.dataTransfer.setData('text/plain', 'item:' + (card.getAttribute('data-client-id') || ''));
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
      const targetFolder = drop.closest('.mf-folder');
      if (!targetFolder || isDescendantFolder(dragEl, targetFolder)) {
        return;
      }
    }

    ev.preventDefault();
    clearOver();
    drop.classList.add('is-over');
    drop.closest('.mf-folder')?.classList.add('is-folder-over');
    if (ev instanceof DragEvent && ev.dataTransfer) ev.dataTransfer.dropEffect = 'move';
  });

  board.addEventListener('dragleave', (ev) => {
    const drop = resolveDrop(ev.target);
    if (!drop) return;
    if (ev instanceof DragEvent && ev.relatedTarget instanceof Node && drop.contains(ev.relatedTarget)) return;
    drop.classList.remove('is-over');
    drop.closest('.mf-folder')?.classList.remove('is-folder-over');
  });

  board.addEventListener('drop', (ev) => {
    const drop = resolveDrop(ev.target);
    if (!drop || !dragEl || !dragKind) return;
    ev.preventDefault();
    ev.stopPropagation();
    clearOver();

    const targetFolderId = drop.getAttribute('data-folder-id') || '0';

    if (dragKind === 'item') {
      const clientId = dragEl.getAttribute('data-client-id');
      const fromFolder = dragEl.getAttribute('data-folder-id') || '0';
      if (!clientId || targetFolderId === fromFolder) return;

      const empty = drop.querySelector(':scope > .mf-empty');
      if (empty) empty.remove();
      drop.appendChild(dragEl);
      dragEl.setAttribute('data-folder-id', targetFolderId);
      refreshCounts();

      post({
        action: 'move_folder',
        client_id: clientId,
        folder_id: targetFolderId === '0' ? '0' : targetFolderId,
      })
        .then(() => flash('Machine déplacée.'))
        .catch((e) => {
          flash(e.message || 'Erreur', true);
          window.location.reload();
        });
      return;
    }

    // Déplacement de dossier → devient enfant du dossier cible (0 = racine)
    const movedId = dragEl.getAttribute('data-folder-id');
    if (!movedId || movedId === '0') return;
    const targetFolder = drop.closest('.mf-folder');
    if (!targetFolder || isDescendantFolder(dragEl, targetFolder)) {
      flash('Impossible de déplacer un dossier dans lui-même.', true);
      return;
    }
    if (movedId === targetFolderId) return;

    const parentId = targetFolderId === '0' ? '0' : targetFolderId;
    // DOM : racine → board ; sinon → children du parent
    if (parentId === '0') {
      board.appendChild(dragEl);
      dragEl.classList.remove('mf-folder-nested');
      dragEl.style.marginLeft = '';
    } else {
      const box = ensureChildren(targetFolder);
      box.appendChild(dragEl);
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
