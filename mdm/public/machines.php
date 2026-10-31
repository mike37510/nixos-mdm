<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Settings.php';
require_once dirname(__DIR__) . '/lib/Clients.php';
require_once dirname(__DIR__) . '/lib/MachineFolders.php';
require_once dirname(__DIR__) . '/lib/AppSettings.php';

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require dirname(__DIR__, 2) . '/src/layout.php';

use NixVm\Auth;

Auth::requireLogin();

$settings = new Settings(dirname(__DIR__) . '/data/settings.json');
$clients = new Clients();
$foldersApi = new MachineFolders();
$profiles = $settings->listProfiles();
$installUrl = (new AppSettings())->installScriptUrl('default', true);
$message = null;
$error = null;

$wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
    || (($_POST['ajax'] ?? '') === '1')
    || (($_GET['ajax'] ?? '') === '1');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'assign') {
            $clients->assignProfile((int) ($_POST['client_id'] ?? 0), (int) ($_POST['profile_id'] ?? 0));
            $message = 'Profil réassigné. Le client basculera au prochain sync (ou sync forcé).';
        } elseif ($action === 'assign_folder_profile') {
            $fidRaw = $_POST['folder_id'] ?? '';
            $folderId = ($fidRaw === '' || $fidRaw === null || $fidRaw === '0') ? null : (int) $fidRaw;
            $profileId = (int) ($_POST['profile_id'] ?? 0);
            $n = $clients->assignProfileToFolder($folderId, $profileId);
            $label = '';
            foreach ($profiles as $p) {
                if ((int) $p['id'] === $profileId) {
                    $label = (string) $p['label'];
                    break;
                }
            }
            $message = $n === 0
                ? 'Aucune machine dans ce dossier.'
                : ($n . ' machine' . ($n > 1 ? 's' : '') . ' → profil « '
                    . ($label !== '' ? $label : ('#' . $profileId))
                    . ' ». Basculent au prochain sync.');
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'updated' => $n, 'message' => $message], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } elseif ($action === 'delete') {
            $clients->delete((int) ($_POST['client_id'] ?? 0));
            $message = 'Machine retirée de l’inventaire.';
        } elseif ($action === 'folder_create') {
            $parentRaw = $_POST['parent_id'] ?? '';
            $parentId = ($parentRaw === '' || $parentRaw === null || $parentRaw === '0')
                ? null : (int) $parentRaw;
            $id = $foldersApi->create((string) ($_POST['folder_name'] ?? ''), $parentId);
            $message = $parentId ? 'Sous-dossier créé.' : 'Dossier créé.';
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'id' => $id, 'message' => $message], JSON_UNESCAPED_UNICODE);
                exit;
            }
            header('Location: /mdm/machines.php?msg=folder_created');
            exit;
        } elseif ($action === 'folder_rename') {
            $foldersApi->rename((int) ($_POST['folder_id'] ?? 0), (string) ($_POST['folder_name'] ?? ''));
            $message = 'Dossier renommé.';
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'message' => $message], JSON_UNESCAPED_UNICODE);
                exit;
            }
            header('Location: /mdm/machines.php?msg=folder_renamed');
            exit;
        } elseif ($action === 'folder_delete') {
            $foldersApi->delete((int) ($_POST['folder_id'] ?? 0));
            $message = 'Dossier supprimé (machines remises dans « Sans dossier »).';
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'message' => $message], JSON_UNESCAPED_UNICODE);
                exit;
            }
            header('Location: /mdm/machines.php?msg=folder_deleted');
            exit;
        } elseif ($action === 'folder_move') {
            $fid = (int) ($_POST['folder_id'] ?? 0);
            $parentRaw = $_POST['parent_id'] ?? '';
            $parentId = ($parentRaw === '' || $parentRaw === null || $parentRaw === '0')
                ? null : (int) $parentRaw;
            $foldersApi->move($fid, $parentId);
            $message = 'Dossier déplacé.';
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'message' => $message], JSON_UNESCAPED_UNICODE);
                exit;
            }
            header('Location: /mdm/machines.php?msg=folder_moved');
            exit;
        } elseif ($action === 'move_folder') {
            $fidRaw = $_POST['folder_id'] ?? '';
            $folderId = ($fidRaw === '' || $fidRaw === null || $fidRaw === '0') ? null : (int) $fidRaw;
            $clients->assignFolder((int) ($_POST['client_id'] ?? 0), $folderId);
            $message = 'Machine déplacée.';
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'message' => $message], JSON_UNESCAPED_UNICODE);
                exit;
            }
            header('Location: /mdm/machines.php?msg=moved');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($wantsJson) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

$msgKey = (string) ($_GET['msg'] ?? '');
if ($message === null && $msgKey !== '') {
    $message = match ($msgKey) {
        'folder_created' => 'Dossier créé.',
        'folder_renamed' => 'Dossier renommé.',
        'folder_deleted' => 'Dossier supprimé.',
        'folder_moved' => 'Dossier déplacé.',
        'folder_profile' => 'Profil appliqué à toutes les machines du dossier.',
        'moved' => 'Machine déplacée.',
        default => null,
    };
}

$rows = $clients->listAll();
$folders = $foldersApi->listAll();
$folderTree = $foldersApi->tree();
$folderOptions = $foldersApi->flatOptions();
$unfiledCount = $clients->countUnfiled();

/** @var array<string, list<array<string, mixed>>> $byFolder */
$byFolder = ['0' => []];
foreach ($folders as $f) {
    $byFolder[(string) $f['id']] = [];
}
foreach ($rows as $row) {
    $fid = isset($row['folder_id']) && $row['folder_id'] !== null && $row['folder_id'] !== ''
        ? (string) (int) $row['folder_id']
        : '0';
    if (!isset($byFolder[$fid])) {
        $byFolder[$fid] = [];
    }
    $byFolder[$fid][] = $row;
}

layout_header('Machines', 'machines');
?>
<section class="hero">
  <div>
    <h1>Machines</h1>
    <p>
      Inventaire des postes et <strong>classement en dossiers</strong> (glisser-déposer).
      Le profil déployé se change toujours ici — indépendamment du dossier.
    </p>
  </div>
</section>

<?php if ($message): ?><div class="banner info" id="mf-banner"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="banner error" id="mf-banner-err"><?= e($error) ?></div><?php endif; ?>

<section class="card mf-toolbar">
  <div class="mf-toolbar-row">
    <h2 style="margin:0">Dossiers <span class="count"><?= count($folders) ?></span></h2>
    <form method="post" class="mf-create" id="mf-create-form">
      <input type="hidden" name="action" value="folder_create">
      <input type="text" name="folder_name" placeholder="Nouveau dossier (ex. Bâtiment A)" required maxlength="120" autocomplete="off">
      <select name="parent_id" aria-label="Dossier parent">
        <option value="0">À la racine</option>
        <?php foreach ($folderOptions as $opt): ?>
          <option value="<?= (int) $opt['id'] ?>"><?= e($opt['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn primary small" type="submit">Créer</button>
    </form>
  </div>
  <p class="hint" style="margin:.55rem 0 0">
    Glissez machines <strong>et dossiers</strong> (poignée ⠿) entre dossiers / sous-dossiers. <strong>Profil → dossier</strong> inclut les sous-dossiers.
  </p>
</section>

<?php
$renderMachine = static function (array $row) use ($profiles): void {
    $meta = [];
    if (!empty($row['meta'])) {
        $decoded = json_decode((string) $row['meta'], true);
        $meta = is_array($decoded) ? $decoded : [];
    }
    $deployed = (string) ($meta['deployed_profile'] ?? '');
    $assignedSlug = (string) ($row['profile_slug'] ?? '');
    $pending = $deployed !== '' && $deployed !== $assignedSlug;
    $fid = isset($row['folder_id']) && $row['folder_id'] !== null && $row['folder_id'] !== ''
        ? (int) $row['folder_id']
        : 0;
    $syncStatus = (string) ($row['sync_status'] ?? '');
    $outOfSync = Clients::isOutOfSync($row);
    $syncClass = match ($syncStatus) {
        'ok' => $outOfSync ? 'sync-warn' : 'sync-ok',
        'failed' => 'sync-fail',
        default => 'sync-unknown',
    };
    $syncLabel = match ($syncStatus) {
        'ok' => $outOfSync ? 'Décalé' : 'OK',
        'failed' => !empty($row['rolled_back']) ? 'Échec + rollback' : 'Échec',
        default => 'Inconnu',
    };
    $appliedVer = isset($row['profile_version']) && $row['profile_version'] !== null
        ? (int) $row['profile_version'] : null;
    $expectedVer = isset($row['expected_profile_version']) && $row['expected_profile_version'] !== null
        ? (int) $row['expected_profile_version'] : null;
    $gen = isset($row['nix_generation']) && $row['nix_generation'] !== null
        ? (int) $row['nix_generation'] : null;
    $err = trim((string) ($row['sync_error'] ?? ''));
    ?>
    <article class="mf-card" draggable="true"
             data-client-id="<?= (int) $row['id'] ?>"
             data-folder-id="<?= $fid ?>">
      <div class="mf-card-grip" title="Glisser" aria-hidden="true">⠿</div>
      <div class="mf-card-body">
        <div class="mf-card-title">
          <strong><?= e((string) ($row['hostname'] ?: '—')) ?></strong>
          <span class="muted mono"><?= e((string) ($row['mac'] ?: '')) ?></span>
          <span class="sync-pill <?= e($syncClass) ?>" title="Statut dernière sync"><?= e($syncLabel) ?></span>
        </div>
        <div class="mf-card-meta muted">
          IP <?= e((string) ($row['last_ip'] ?: '—')) ?>
          · sync <?= !empty($row['last_sync_at']) ? e((string) $row['last_sync_at']) : 'jamais' ?>
          <?php if ($gen !== null): ?>
            · gen <span class="sw-version"><?= $gen ?></span>
          <?php endif; ?>
          <?php if ($appliedVer !== null || $expectedVer !== null): ?>
            · profil
            <span class="sw-version">
              v<?= $appliedVer !== null ? $appliedVer : '?' ?>
              <?php if ($expectedVer !== null && $appliedVer !== $expectedVer): ?>
                → v<?= $expectedVer ?>
              <?php endif; ?>
            </span>
          <?php endif; ?>
          <?php if ($deployed !== ''): ?>
            · <?= $pending
              ? '<span class="sw-badge">' . e($deployed) . ' → ' . e($assignedSlug) . '</span>'
              : '<span class="sw-version">' . e($deployed) . '</span>' ?>
          <?php endif; ?>
        </div>
        <?php if ($err !== ''): ?>
          <div class="mf-card-error" title="<?= e($err) ?>"><?= e(mb_strlen($err) > 160 ? mb_substr($err, 0, 160) . '…' : $err) ?></div>
        <?php endif; ?>
        <div class="mf-card-actions">
          <form method="post" class="mf-assign">
            <input type="hidden" name="action" value="assign">
            <input type="hidden" name="client_id" value="<?= (int) $row['id'] ?>">
            <select name="profile_id" onchange="this.form.submit()" aria-label="Profil">
              <?php foreach ($profiles as $p): ?>
                <option value="<?= (int) $p['id'] ?>" <?= (int) $row['profile_id'] === (int) $p['id'] ? 'selected' : '' ?>>
                  <?= e($p['label']) ?><?= $p['is_default'] ? ' ★' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </form>
          <form method="post" onsubmit="return confirm('Retirer cette machine de l’inventaire ?');">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="client_id" value="<?= (int) $row['id'] ?>">
            <button class="btn small danger" type="submit">Retirer</button>
          </form>
        </div>
      </div>
    </article>
    <?php
};

$renderFolderTree = null;
$renderFolderTree = static function (
    string $key,
    string $title,
    array $machines,
    bool $deletable,
    array $children,
    int $depth
) use (&$renderFolderTree, $renderMachine, $profiles, $byFolder): void {
    $folderIdAttr = $key === '0' ? '0' : $key;
    $count = count($machines);
    $confirmAssign = 'Appliquer ce profil à toutes les machines de « ' . $title . ' »'
        . ($children !== [] ? ' (et sous-dossiers)' : '') . ' ?';
    $confirmJs = json_encode($confirmAssign, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP);
    $confirmDel = 'Supprimer « ' . $title . ' »'
        . ($children !== [] ? ' et ses sous-dossiers' : '')
        . ' ? Les machines iront dans « Sans dossier ».';
    $confirmDelJs = json_encode($confirmDel, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP);
    ?>
    <section class="mf-folder card<?= $depth > 0 ? ' mf-folder-nested' : '' ?>"
             data-folder-id="<?= e($folderIdAttr) ?>"
             data-folder-depth="<?= $depth ?>"
             <?= $deletable ? 'draggable="true"' : '' ?>
             style="<?= $depth > 0 ? 'margin-left:' . min($depth * 1.1, 4) . 'rem' : '' ?>">
      <header class="mf-folder-head">
        <div class="mf-folder-title">
          <?php if ($deletable): ?>
            <span class="mf-folder-grip" title="Glisser le dossier" aria-hidden="true">⠿</span>
          <?php endif; ?>
          <span class="mf-folder-ico" aria-hidden="true"><?= $depth > 0 ? '📂' : '📁' ?></span>
          <h2>
            <?php if ($deletable): ?>
              <button type="button" class="mf-folder-name" data-folder-id="<?= (int) $key ?>"
                      title="Cliquer pour renommer"><?= e($title) ?></button>
            <?php else: ?>
              <span class="mf-folder-name is-static"><?= e($title) ?></span>
            <?php endif; ?>
            <span class="count mf-count"><?= $count ?></span>
          </h2>
        </div>
        <div class="mf-folder-actions">
          <?php if ($key !== '0'): ?>
            <form method="post" class="mf-subfolder-create" title="Créer un sous-dossier">
              <input type="hidden" name="action" value="folder_create">
              <input type="hidden" name="parent_id" value="<?= (int) $key ?>">
              <input type="text" name="folder_name" placeholder="Sous-dossier…" required maxlength="120" autocomplete="off">
              <button class="btn small" type="submit">+</button>
            </form>
          <?php endif; ?>
          <form method="post" class="mf-folder-assign"
                onsubmit="return this.profile_id.value !== '' && confirm(<?= $confirmJs ?>);">
            <input type="hidden" name="action" value="assign_folder_profile">
            <input type="hidden" name="folder_id" value="<?= e($folderIdAttr) ?>">
            <select name="profile_id" aria-label="Profil pour tout le dossier">
              <option value="">Profil → dossier…</option>
              <?php foreach ($profiles as $p): ?>
                <option value="<?= (int) $p['id'] ?>">
                  <?= e($p['label']) ?><?= !empty($p['is_default']) ? ' ★' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button class="btn small primary" type="submit">Appliquer</button>
          </form>
          <?php if ($deletable): ?>
            <form method="post" onsubmit="return confirm(<?= $confirmDelJs ?>);">
              <input type="hidden" name="action" value="folder_delete">
              <input type="hidden" name="folder_id" value="<?= (int) $key ?>">
              <button class="btn small danger" type="submit">Supprimer</button>
            </form>
          <?php endif; ?>
        </div>
      </header>
      <div class="mf-drop" data-folder-id="<?= e($folderIdAttr) ?>">
        <?php if ($machines === []): ?>
          <div class="mf-empty">Déposez des machines ici</div>
        <?php else: ?>
          <?php foreach ($machines as $m): $renderMachine($m); endforeach; ?>
        <?php endif; ?>
      </div>
      <?php if ($children !== []): ?>
        <div class="mf-folder-children">
          <?php foreach ($children as $child):
              $cid = (string) (int) $child['id'];
              $renderFolderTree(
                  $cid,
                  (string) $child['name'],
                  $byFolder[$cid] ?? [],
                  true,
                  $child['children'] ?? [],
                  $depth + 1
              );
          endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
    <?php
};
?>

<div class="mf-board" id="mf-board" data-folder-rename="/mdm/machines.php">
  <?php
  $renderFolderTree('0', 'Sans dossier', $byFolder['0'] ?? [], false, [], 0);
  foreach ($folderTree as $node) {
      $id = (string) (int) $node['id'];
      $renderFolderTree(
          $id,
          (string) $node['name'],
          $byFolder[$id] ?? [],
          true,
          $node['children'] ?? [],
          0
      );
  }
  ?>
</div>

<?php if ($rows === []): ?>
<section class="card" style="margin-top:1rem">
  <div class="empty">
    Aucune machine enregistrée pour l’instant.
    Sur un client : <code>curl -fsSL <?= e($installUrl) ?> | sudo bash</code>
  </div>
</section>
<?php endif; ?>

<section class="card" style="margin-top:1rem">
  <h2>Profil vs dossier</h2>
  <p class="hint" style="margin:0">
    Le <strong>dossier</strong> classe l’inventaire. Le <strong>profil</strong> détermine les logiciels.
    Vous pouvez appliquer un profil à <strong>toutes</strong> les machines d’un dossier via le sélecteur en tête de dossier.
  </p>
</section>
<?php
layout_footer('machines.js', 'folders-rename.js');
