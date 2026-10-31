<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Settings.php';
require_once dirname(__DIR__) . '/lib/NixGenerator.php';
require_once dirname(__DIR__) . '/lib/ProfileVersions.php';
require_once dirname(__DIR__) . '/lib/ProfileFolders.php';
require_once dirname(__DIR__) . '/lib/AppSettings.php';

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require dirname(__DIR__, 2) . '/src/layout.php';

use NixVm\Auth;

Auth::requireLogin();

$root = dirname(__DIR__);
$settingsPath = $root . '/data/settings.json';
$generatedDir = $root . '/public/generated';

$profileId = isset($_GET['profile']) ? (int) $_GET['profile'] : null;
if ($profileId !== null && $profileId <= 0) {
    $profileId = null;
}

$message = null;
$error = null;

$baseUrl = (new AppSettings())->publicHubUrl();

try {
    $settings = new Settings($settingsPath, $profileId);
} catch (Throwable $e) {
    $settings = new Settings($settingsPath);
    $error = $e->getMessage();
}

$generator = new NixGenerator($generatedDir);
$versions = new ProfileVersions();
$profileFolders = new ProfileFolders();

$wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
    || (($_POST['ajax'] ?? '') === '1');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'publish');
    try {
        if ($action === 'select') {
            $settings->loadProfile((int) ($_POST['profile_id'] ?? 0));
            header('Location: /mdm/?profile=' . $settings->profileId());
            exit;
        }

        if ($action === 'create') {
            $label = trim((string) ($_POST['new_label'] ?? ''));
            $clone = !empty($_POST['clone_current']);
            $id = $settings->createProfile($label, $clone ? $settings->profileId() : null);
            $folderId = (int) ($_POST['new_folder_id'] ?? 0);
            if ($folderId > 0) {
                $settings->assignFolder($id, $folderId);
            }
            $generator->generate($settings->all(), [
                'baseUrl' => $baseUrl,
                'profileSlug' => $settings->slug(),
                'isDefault' => $settings->isDefault(),
            ]);
            $versions->record($id, $settings->all(), $clone ? 'Création (clone)' : 'Création');
            header('Location: /mdm/?profile=' . $id . '&msg=created');
            exit;
        }

        if ($action === 'rename') {
            $settings->renameProfile((string) ($_POST['rename_label'] ?? ''));
            header('Location: /mdm/?profile=' . $settings->profileId() . '&msg=renamed');
            exit;
        }

        if ($action === 'set_default') {
            $settings->setAsDefault();
            $settings->setHubUrl($baseUrl);
            $generator->generate($settings->all(), [
                'baseUrl' => $baseUrl,
                'profileSlug' => $settings->slug(),
                'isDefault' => true,
            ]);
            header('Location: /mdm/?profile=' . $settings->profileId() . '&msg=default');
            exit;
        }

        if ($action === 'delete') {
            $delId = (int) ($_POST['profile_id'] ?? $settings->profileId());
            if ($delId > 0 && $delId !== $settings->profileId()) {
                $settings->loadProfile($delId);
            }
            $deletedSlug = $settings->slug();
            $wasDefault = $settings->isDefault();
            $settings->deleteProfile();
            if (!$wasDefault && $deletedSlug !== '' && $deletedSlug !== 'default') {
                $dir = $generatedDir . '/profiles/' . $deletedSlug;
                if (is_dir($dir)) {
                    foreach (glob($dir . '/*') ?: [] as $f) {
                        if (is_file($f)) {
                            @unlink($f);
                        }
                    }
                    @rmdir($dir);
                }
            }
            header('Location: /mdm/?profile=' . $settings->profileId() . '&msg=deleted');
            exit;
        }

        if ($action === 'folder_create') {
            $parentRaw = $_POST['parent_id'] ?? '';
            $parentId = ($parentRaw === '' || $parentRaw === null || $parentRaw === '0')
                ? null : (int) $parentRaw;
            $profileFolders->create((string) ($_POST['folder_name'] ?? ''), $parentId);
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'message' => $parentId ? 'Sous-dossier créé.' : 'Dossier créé.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            header('Location: /mdm/?profile=' . $settings->profileId() . '&msg=folder_created');
            exit;
        }

        if ($action === 'folder_rename') {
            $profileFolders->rename((int) ($_POST['folder_id'] ?? 0), (string) ($_POST['folder_name'] ?? ''));
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'message' => 'Dossier renommé.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            header('Location: /mdm/?profile=' . $settings->profileId() . '&msg=folder_renamed');
            exit;
        }

        if ($action === 'folder_delete') {
            $profileFolders->delete((int) ($_POST['folder_id'] ?? 0));
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'message' => 'Dossier supprimé.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            header('Location: /mdm/?profile=' . $settings->profileId() . '&msg=folder_deleted');
            exit;
        }

        if ($action === 'folder_move') {
            $fid = (int) ($_POST['folder_id'] ?? 0);
            $parentRaw = $_POST['parent_id'] ?? '';
            $parentId = ($parentRaw === '' || $parentRaw === null || $parentRaw === '0')
                ? null : (int) $parentRaw;
            $profileFolders->move($fid, $parentId);
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'message' => 'Dossier déplacé.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            header('Location: /mdm/?profile=' . $settings->profileId() . '&msg=folder_moved');
            exit;
        }

        if ($action === 'move_folder') {
            $fidRaw = $_POST['folder_id'] ?? '';
            $folderId = ($fidRaw === '' || $fidRaw === null || $fidRaw === '0') ? null : (int) $fidRaw;
            $settings->assignFolder((int) ($_POST['profile_id'] ?? 0), $folderId);
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'message' => 'Profil déplacé.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            header('Location: /mdm/?profile=' . $settings->profileId() . '&msg=moved');
            exit;
        }

        if ($action === 'restore_version') {
            $vid = (int) ($_POST['version_id'] ?? 0);
            $snap = $versions->get($vid, $settings->profileId());
            if ($snap === null) {
                throw new InvalidArgumentException('Version introuvable pour ce profil.');
            }
            $settings->restoreData($snap['data']);
            $settings->setHubUrl($baseUrl);
            $generator->generate($settings->all(), [
                'baseUrl' => $baseUrl,
                'profileSlug' => $settings->slug(),
                'isDefault' => $settings->isDefault(),
            ]);
            $recorded = $versions->record(
                $settings->profileId(),
                $settings->all(),
                'Restauration v' . $snap['version_num']
            );
            $vLabel = $recorded ? ('v' . $recorded['version_num']) : 'courante';
            header(
                'Location: /mdm/?profile=' . $settings->profileId()
                . '&msg=restored&from=' . (int) $snap['version_num']
                . '&as=' . rawurlencode($vLabel)
            );
            exit;
        }

        // publish (défaut)
        $settings->update($_POST);
        $settings->setHubUrl($baseUrl);
        $generator->generate($settings->all(), [
            'baseUrl' => $baseUrl,
            'profileSlug' => $settings->slug(),
            'isDefault' => $settings->isDefault(),
        ]);
        $recorded = $versions->record($settings->profileId(), $settings->all(), 'Publication');
        $message = 'Profil « ' . $settings->label() . ' » publié. Les clients de ce profil le récupéreront au prochain cycle.';
        if ($recorded !== null) {
            $message .= ' Version v' . $recorded['version_num'] . ' enregistrée.';
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
} elseif (!is_file($generatedDir . '/parc.nix') || !is_file($generatedDir . '/install-on-client.sh')) {
    try {
        $settings->setHubUrl($baseUrl);
        $generator->generate($settings->all(), [
            'baseUrl' => $baseUrl,
            'profileSlug' => $settings->slug(),
            'isDefault' => $settings->isDefault(),
        ]);
    } catch (Throwable $e) {
        $error = 'Génération initiale : ' . $e->getMessage();
    }
}

$msgKey = (string) ($_GET['msg'] ?? '');
if ($message === null && $msgKey !== '') {
    $message = match ($msgKey) {
        'created' => 'Profil créé et publié.',
        'renamed' => 'Profil renommé.',
        'default' => 'Profil défini comme profil par défaut.',
        'deleted' => 'Profil supprimé.',
        'folder_created' => 'Dossier de profils créé.',
        'folder_renamed' => 'Dossier renommé.',
        'folder_deleted' => 'Dossier supprimé (profils remis dans « Sans dossier »).',
        'moved' => 'Profil déplacé.',
        'restored' => sprintf(
            'Profil restauré depuis v%s → %s. Les clients récupéreront ce contenu au prochain sync.',
            (string) ($_GET['from'] ?? '?'),
            (string) ($_GET['as'] ?? 'version courante')
        ),
        default => null,
    };
}

$data = $settings->all();
$profiles = $settings->listProfiles();
$profFolders = $profileFolders->listAll();
$profFolderTree = $profileFolders->tree();
$profFolderOptions = $profileFolders->flatOptions();
$profUnfiled = $profileFolders->countUnfiled();
/** @var array<string, list<array<string, mixed>>> $profilesByFolder */
$profilesByFolder = ['0' => []];
foreach ($profFolders as $pf) {
    $profilesByFolder[(string) $pf['id']] = [];
}
foreach ($profiles as $p) {
    $fid = $p['folder_id'] !== null ? (string) (int) $p['folder_id'] : '0';
    if (!isset($profilesByFolder[$fid])) {
        $profilesByFolder[$fid] = [];
    }
    $profilesByFolder[$fid][] = $p;
}
$presets = Settings::frequencyPresets();
$preset = (string) ($data['frequencyPreset'] ?? '30min');
if (!isset($presets[$preset])) {
    $preset = 'custom';
}

// Première visite : seed v1 si historique vide et profil déjà peuplé
if ($versions->countForProfile($settings->profileId()) === 0) {
    try {
        $versions->record($settings->profileId(), $settings->all(), 'État initial');
    } catch (Throwable $e) {
        // non bloquant
    }
}
$versionRows = $versions->listForProfile($settings->profileId(), 40);
$viewVersionId = isset($_GET['v']) ? (int) $_GET['v'] : 0;
$viewSnap = $viewVersionId > 0 ? $versions->get($viewVersionId, $settings->profileId()) : null;

$slug = $settings->slug();
$isDefault = $settings->isDefault();
$installRel = $isDefault ? '/mdm/generated/install-on-client.sh' : '/mdm/generated/profiles/' . rawurlencode($slug) . '/install-on-client.sh';
$installUrl = $baseUrl . ($isDefault ? '/generated/install-on-client.sh' : '/generated/profiles/' . $slug . '/install-on-client.sh');

$packages = array_values(array_map('strval', (array) ($data['packages'] ?? [])));
$viewDiff = $viewSnap !== null
    ? ProfileVersions::packageDiff($packages, $viewSnap['packages'])
    : null;
$latestVerNum = $versionRows[0]['version_num'] ?? null;

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$boot = [
    'packages' => $packages,
    'release' => '26.05',
];

layout_header('Profils', 'mdm');
?>
<section class="hero">
  <div>
    <h1>Linux MDM</h1>
    <p>
      Gérez les <strong>profils</strong>, publiez, puis déployez le script
      sur les postes concernés.
    </p>
  </div>
</section>

<?php if ($message): ?>
  <div class="banner info"><?= h($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="banner error"><strong>Erreur :</strong> <?= h($error) ?></div>
<?php endif; ?>

<div class="editor" style="padding-bottom: 0">
  <section class="card mdm-profiles" style="margin-bottom:1rem">
    <div class="mdm-profiles-head">
      <h2 style="margin:0"><span class="step">0</span> Profils
        <span class="count"><?= count($profiles) ?></span>
      </h2>
      <p class="muted mdm-profiles-meta">
        Actif : <strong><?= h($settings->label()) ?></strong>
        · <code><?= h($slug) ?></code>
        <?php if ($isDefault): ?>· défaut ★<?php endif; ?>
      </p>
    </div>

    <div class="mf-toolbar-row" style="margin:.85rem 0">
      <form method="post" action="/mdm/?profile=<?= $settings->profileId() ?>" class="mf-create">
        <input type="hidden" name="action" value="folder_create">
        <input type="text" name="folder_name" placeholder="Nouveau dossier (ex. Services)" required maxlength="120" autocomplete="off">
        <select name="parent_id" aria-label="Dossier parent">
          <option value="0">À la racine</option>
          <?php foreach ($profFolderOptions as $opt): ?>
            <option value="<?= (int) $opt['id'] ?>"><?= h($opt['label']) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn primary small" type="submit">Créer un dossier</button>
      </form>
      <p class="hint" style="margin:0">Glissez profils et dossiers (⠿) entre tous les niveaux.</p>
    </div>

    <?php
    $renderProfCard = static function (array $p, int $activeId): void {
        $fid = $p['folder_id'] !== null ? (int) $p['folder_id'] : 0;
        $active = (int) $p['id'] === $activeId;
        $isDef = !empty($p['is_default']);
        $label = (string) $p['label'];
        $confirmDel = 'Supprimer le profil « ' . $label . ' » ? Les machines seront rattachées au profil par défaut.';
        $confirmJs = json_encode($confirmDel, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP);
        ?>
        <article class="mf-card pf-card <?= $active ? 'is-active' : '' ?>"
                 draggable="true"
                 data-profile-id="<?= (int) $p['id'] ?>"
                 data-folder-id="<?= $fid ?>">
          <div class="mf-card-grip" title="Glisser" aria-hidden="true">⠿</div>
          <a class="mf-card-body pf-card-link" href="/mdm/?profile=<?= (int) $p['id'] ?>">
            <div class="mf-card-title">
              <strong><?= h($label) ?><?= $isDef ? ' ★' : '' ?></strong>
              <span class="muted"><?= (int) $p['packages'] ?> paquets</span>
            </div>
            <div class="mf-card-meta muted"><code><?= h($p['slug']) ?></code></div>
          </a>
          <div class="mf-card-actions pf-card-actions">
            <?php if ($isDef): ?>
              <span class="hint" title="Définir un autre profil comme défaut pour pouvoir le supprimer">défaut</span>
            <?php else: ?>
              <form method="post" action="/mdm/?profile=<?= $activeId ?>"
                    onsubmit="return confirm(<?= $confirmJs ?>);">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="profile_id" value="<?= (int) $p['id'] ?>">
                <button class="btn small danger" type="submit" title="Supprimer ce profil">Supprimer</button>
              </form>
            <?php endif; ?>
          </div>
        </article>
        <?php
    };
    $renderProfFolderTree = null;
    $renderProfFolderTree = static function (
        string $key,
        string $title,
        array $items,
        bool $deletable,
        array $children,
        int $depth,
        int $activeId,
        callable $renderProfCard
    ) use (&$renderProfFolderTree, $profilesByFolder): void {
        $folderIdAttr = $key === '0' ? '0' : $key;
        $count = count($items);
        $confirmDel = 'Supprimer « ' . $title . ' »'
            . ($children !== [] ? ' et ses sous-dossiers' : '')
            . ' ? Les profils iront dans « Sans dossier ».';
        $confirmDelJs = json_encode($confirmDel, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP);
        ?>
        <section class="mf-folder pf-folder<?= $depth > 0 ? ' mf-folder-nested' : '' ?>"
                 data-folder-id="<?= h($folderIdAttr) ?>"
                 data-folder-depth="<?= $depth ?>"
                 <?= $deletable ? 'draggable="true"' : '' ?>
                 style="margin-bottom:.85rem;<?= $depth > 0 ? 'margin-left:' . min($depth * 1.1, 4) . 'rem;' : '' ?>">
          <header class="mf-folder-head">
            <div class="mf-folder-title">
              <?php if ($deletable): ?>
                <span class="mf-folder-grip" title="Glisser le dossier" aria-hidden="true">⠿</span>
              <?php endif; ?>
              <span class="mf-folder-ico" aria-hidden="true"><?= $depth > 0 ? '📂' : '📁' ?></span>
              <h3 style="margin:0;font-size:1rem;font-weight:500">
                <?php if ($deletable): ?>
                  <button type="button" class="mf-folder-name" data-folder-id="<?= (int) $key ?>"
                          title="Cliquer pour renommer"><?= h($title) ?></button>
                <?php else: ?>
                  <span class="mf-folder-name is-static"><?= h($title) ?></span>
                <?php endif; ?>
                <span class="count mf-count"><?= $count ?></span>
              </h3>
            </div>
            <div class="mf-folder-actions">
              <?php if ($key !== '0'): ?>
                <form method="post" action="/mdm/?profile=<?= $activeId ?>" class="mf-subfolder-create"
                      title="Créer un sous-dossier">
                  <input type="hidden" name="action" value="folder_create">
                  <input type="hidden" name="parent_id" value="<?= (int) $key ?>">
                  <input type="text" name="folder_name" placeholder="Sous-dossier…" required maxlength="120" autocomplete="off">
                  <button class="btn small" type="submit">+</button>
                </form>
              <?php endif; ?>
              <?php if ($deletable): ?>
                <form method="post" action="/mdm/?profile=<?= $activeId ?>"
                      onsubmit="return confirm(<?= $confirmDelJs ?>);">
                  <input type="hidden" name="action" value="folder_delete">
                  <input type="hidden" name="folder_id" value="<?= (int) $key ?>">
                  <button class="btn small danger" type="submit">Supprimer</button>
                </form>
              <?php endif; ?>
            </div>
          </header>
          <div class="mf-drop pf-drop" data-folder-id="<?= h($folderIdAttr) ?>">
            <?php if ($items === []): ?>
              <div class="mf-empty">Déposez des profils ici</div>
            <?php else: ?>
              <?php foreach ($items as $item): $renderProfCard($item, $activeId); endforeach; ?>
            <?php endif; ?>
          </div>
          <?php if ($children !== []): ?>
            <div class="mf-folder-children">
              <?php foreach ($children as $child):
                  $cid = (string) (int) $child['id'];
                  $renderProfFolderTree(
                      $cid,
                      (string) $child['name'],
                      $profilesByFolder[$cid] ?? [],
                      true,
                      $child['children'] ?? [],
                      $depth + 1,
                      $activeId,
                      $renderProfCard
                  );
              endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
        <?php
    };
    ?>

    <div class="mf-board" id="pf-board" data-folder-rename="/mdm/?profile=<?= (int) $settings->profileId() ?>">
      <?php
      $activePid = $settings->profileId();
      $renderProfFolderTree('0', 'Sans dossier', $profilesByFolder['0'] ?? [], false, [], 0, $activePid, $renderProfCard);
      foreach ($profFolderTree as $node) {
          $id = (string) (int) $node['id'];
          $renderProfFolderTree(
              $id,
              (string) $node['name'],
              $profilesByFolder[$id] ?? [],
              true,
              $node['children'] ?? [],
              0,
              $activePid,
              $renderProfCard
          );
      }
      ?>
    </div>

    <div class="mdm-profile-toolbar" style="margin-top:1rem">
      <form method="post" action="/mdm/?profile=<?= $settings->profileId() ?>" class="mdm-inline-form">
        <input type="hidden" name="action" value="rename">
        <input type="text" name="rename_label" value="<?= h($settings->label()) ?>" required aria-label="Nom du profil">
        <button class="btn small" type="submit">Renommer le profil actif</button>
      </form>
      <div class="mdm-profile-actions">
        <?php if (!$isDefault): ?>
          <form method="post" action="/mdm/?profile=<?= $settings->profileId() ?>" onsubmit="return confirm('Définir ce profil comme profil par défaut ?');">
            <input type="hidden" name="action" value="set_default">
            <button class="btn small" type="submit">Définir comme défaut</button>
          </form>
          <form method="post" action="/mdm/?profile=<?= $settings->profileId() ?>"
                onsubmit="return confirm('Supprimer le profil « <?= h($settings->label()) ?> » ? Les machines seront rattachées au profil par défaut.');">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="profile_id" value="<?= $settings->profileId() ?>">
            <button class="btn small danger" type="submit">Supprimer le profil</button>
          </form>
        <?php else: ?>
          <span class="hint" style="margin:0">Profil par défaut — définissez un autre défaut pour pouvoir le supprimer.</span>
        <?php endif; ?>
      </div>
    </div>

    <details class="mdm-profile-create">
      <summary>Créer un nouveau profil</summary>
      <form method="post" action="/mdm/?profile=<?= $settings->profileId() ?>" class="mdm-create-form">
        <input type="hidden" name="action" value="create">
        <label>Nom
          <input type="text" name="new_label" placeholder="ex : Comptabilité" required>
        </label>
        <label>Dossier
          <select name="new_folder_id">
            <option value="0">Sans dossier</option>
            <?php foreach ($profFolderOptions as $opt): ?>
              <option value="<?= (int) $opt['id'] ?>"><?= h($opt['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="check">
          <input type="checkbox" name="clone_current" value="1" checked>
          Cloner le profil actuel (paquets &amp; planning)
        </label>
        <button class="btn primary small" type="submit">Créer</button>
      </form>
    </details>
  </section>

  <div class="grid-2" style="align-items: start">
    <section class="card">
      <h2><span class="step">1</span> Logiciels &amp; planning
        <span class="count" id="pkg-count"><?= count($packages) ?></span>
      </h2>
      <p class="muted" style="margin-top:-.5rem">
        Dernière publication :
        <?= !empty($data['updatedAt']) ? h((string) $data['updatedAt']) : 'pas encore publiée' ?>
      </p>

      <form method="post" action="/mdm/?profile=<?= $settings->profileId() ?>" autocomplete="off" id="mdm-form">
        <input type="hidden" name="action" value="publish">
        <input type="hidden" name="packages" id="packages-field" value="<?= h(implode("\n", $packages)) ?>">

        <div class="pkg-selected" id="pkg-selected" aria-live="polite"></div>
        <div class="pkg-search">
          <input type="search" id="pkg-query" placeholder="Rechercher parmi +120 000 paquets nixpkgs (ex : htop, vscode, python3)…" autocomplete="off">
          <div class="pkg-results" id="pkg-results" hidden></div>
        </div>
        <p class="hint">Recherchez puis cliquez pour ajouter. Entrée ajoute le nom exact. Publiez pour mettre à jour ce profil.</p>

        <label class="check" style="display:flex;align-items:center;gap:.5rem;margin:1rem 0">
          <input type="checkbox" name="autoUpgradeEnable" value="1" <?= !empty($data['autoUpgradeEnable']) ? 'checked' : '' ?>>
          Activer la synchro automatique sur les clients
        </label>

        <label>Fréquence de synchro
          <select name="frequency_preset" id="frequency_preset">
            <?php foreach ($presets as $key => $info): ?>
              <option value="<?= h($key) ?>" <?= $preset === $key ? 'selected' : '' ?>>
                <?= h($info['label']) ?> — <?= h($info['dates']) ?>
              </option>
            <?php endforeach; ?>
            <option value="custom" <?= $preset === 'custom' ? 'selected' : '' ?>>Personnalisé (systemd OnCalendar)</option>
          </select>
        </label>

        <div id="custom-freq" <?= $preset === 'custom' ? '' : 'hidden' ?>>
          <label>Expression OnCalendar
            <input type="text" name="dates_custom" value="<?= h($preset === 'custom' ? (string) $data['dates'] : '*:0/30') ?>" placeholder="*:0/30" class="mono">
          </label>
        </div>

        <input type="hidden" name="hostname" value="nixos-{mac}">
        <p class="hint" style="margin:0 0 1rem">
          <strong>Nom d’hôte client</strong> : généré automatiquement à l’install —
          <code>nixos-&lt;mac&gt;</code> (ex. <code>nixos-a1b2c3d4e5f6</code> à partir de la clé MAC).
        </p>

        <input type="hidden" name="operation" value="<?= h((string) ($data['operation'] ?? 'switch')) ?>">
        <input type="hidden" name="randomizedDelaySec" value="<?= h((string) ($data['randomizedDelaySec'] ?? '0')) ?>">

        <label class="check" style="display:flex;align-items:center;gap:.5rem;margin:1rem 0">
          <input type="checkbox" name="allowReboot" value="1" <?= !empty($data['allowReboot']) ? 'checked' : '' ?>>
          Autoriser le redémarrage automatique si nécessaire
        </label>

        <div class="actions" style="margin-top:1.2rem">
          <button class="btn primary" type="submit">Publier ce profil</button>
        </div>
      </form>
    </section>

    <aside>
      <section class="card">
        <h2><span class="step">2</span> Déploiement — <?= h($settings->label()) ?></h2>
        <p class="hint" style="margin-top:-.4rem">Script spécifique à ce profil. Les clients tirent toujours la même URL après install.</p>

        <div class="vm-tags" style="margin:1rem 0">
          <a class="btn primary" href="<?= h($installRel) ?>" download>install-on-client.sh</a>
        </div>

        <p class="muted" style="font-size:.88rem;margin:0 0 .4rem">Sur le client (root) :</p>
        <pre class="code-light"><code>curl -fsSL <?= h($installUrl) ?> | sudo bash</code></pre>
        <p class="hint">Forcer un sync : <code>systemctl start nix-builder-mdm-sync.service</code></p>
      </section>

      <section class="card" id="pv-versions" style="margin-top:1rem">
        <h2>Versions
          <span class="count"><?= count($versionRows) ?></span>
        </h2>
        <p class="muted" style="margin-top:-.45rem">
          Chaque publication ou changement de logiciels crée une version.
          <?= $latestVerNum !== null ? 'Courante : <strong>v' . (int) $latestVerNum . '</strong>.' : '' ?>
        </p>
        <?php if ($versionRows === []): ?>
          <div class="empty" style="padding:1.2rem">Aucune version enregistrée.</div>
        <?php else: ?>
          <div class="table-wrap pv-table-wrap">
            <table class="data-table pv-table">
              <thead>
                <tr>
                  <th>Version</th>
                  <th>Date</th>
                  <th>Paquets</th>
                  <th>Changements</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($versionRows as $vr): ?>
                  <?php
                    $isView = $viewSnap !== null && (int) $vr['id'] === (int) $viewSnap['id'];
                    $isLatest = $latestVerNum !== null && (int) $vr['version_num'] === (int) $latestVerNum;
                  ?>
                  <tr class="<?= $isView ? 'is-selected' : '' ?>">
                    <td>
                      <strong>v<?= (int) $vr['version_num'] ?></strong>
                      <?php if ($isLatest): ?><span class="sw-badge">actuelle</span><?php endif; ?>
                      <div class="muted" style="font-size:.78rem"><?= h($vr['reason']) ?></div>
                    </td>
                    <td class="mono" style="white-space:nowrap;font-size:.82rem"><?= h($vr['created_at']) ?></td>
                    <td><?= (int) $vr['packages_count'] ?></td>
                    <td style="font-size:.84rem;max-width:18rem"><?= h($vr['change_summary']) ?></td>
                    <td>
                      <a class="btn small <?= $isView ? 'primary' : '' ?>"
                         href="/mdm/?profile=<?= $settings->profileId() ?>&amp;v=<?= (int) $vr['id'] ?>#pv-detail">
                        Voir
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
    </aside>
  </div>

  <?php if ($viewSnap !== null): ?>
  <section class="card" id="pv-detail" style="margin-top:1rem">
    <div class="pv-detail-head">
      <div>
        <h2 style="margin:0">Version v<?= (int) $viewSnap['version_num'] ?></h2>
        <p class="muted" style="margin:.35rem 0 0">
          <?= h($viewSnap['created_at']) ?> · <?= h($viewSnap['reason']) ?>
          · <?= (int) $viewSnap['packages_count'] ?> paquet(s)
        </p>
        <p class="hint" style="margin:.35rem 0 0"><?= h($viewSnap['change_summary']) ?></p>
      </div>
      <div class="pv-detail-actions">
        <a class="btn small" href="/mdm/?profile=<?= $settings->profileId() ?>">Fermer</a>
        <?php if ($latestVerNum === null || (int) $viewSnap['version_num'] !== (int) $latestVerNum): ?>
          <form method="post" action="/mdm/?profile=<?= $settings->profileId() ?>"
                onsubmit="return confirm('Restaurer la version v<?= (int) $viewSnap['version_num'] ?> ? Une nouvelle version sera créée.');">
            <input type="hidden" name="action" value="restore_version">
            <input type="hidden" name="version_id" value="<?= (int) $viewSnap['id'] ?>">
            <button class="btn primary small" type="submit">Restaurer cette version</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($viewDiff !== null && ($viewDiff['added'] !== [] || $viewDiff['removed'] !== [])): ?>
      <div class="pv-diff">
        <?php if ($viewDiff['added'] !== []): ?>
          <p><strong class="pv-add">Dans cette version (pas dans l’actuelle)</strong> :
            <?php foreach ($viewDiff['added'] as $a): ?>
              <code class="pv-pkg pv-pkg-add"><?= h($a) ?></code>
            <?php endforeach; ?>
          </p>
        <?php endif; ?>
        <?php if ($viewDiff['removed'] !== []): ?>
          <p><strong class="pv-rem">Dans l’actuelle (absents de cette version)</strong> :
            <?php foreach ($viewDiff['removed'] as $r): ?>
              <code class="pv-pkg pv-pkg-rem"><?= h($r) ?></code>
            <?php endforeach; ?>
          </p>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <p class="muted">Même liste de paquets que la version actuelle (réglages éventuellement différents).</p>
    <?php endif; ?>

    <h3 class="sc-section" style="margin-top:1rem">Paquets de v<?= (int) $viewSnap['version_num'] ?></h3>
    <?php if ($viewSnap['packages'] === []): ?>
      <div class="empty" style="padding:1rem">Aucun paquet.</div>
    <?php else: ?>
      <div class="pv-pkg-list">
        <?php foreach ($viewSnap['packages'] as $pkg): ?>
          <?php
            $inCurrent = in_array($pkg, $packages, true);
          ?>
          <code class="pv-pkg <?= $inCurrent ? '' : 'pv-pkg-add' ?>"><?= h($pkg) ?></code>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <details class="pv-meta" style="margin-top:1rem">
      <summary>Réglages de sync (cette version)</summary>
      <ul class="muted" style="font-size:.9rem">
        <li>Fréquence : <code><?= h((string) ($viewSnap['data']['datesLabel'] ?? $viewSnap['data']['dates'] ?? '—')) ?></code></li>
        <li>Opération : <code><?= h((string) ($viewSnap['data']['operation'] ?? '—')) ?></code></li>
        <li>Hostname : auto <code>nixos-&lt;mac&gt;</code> sur chaque poste</li>
        <li>Auto-upgrade : <?= !empty($viewSnap['data']['autoUpgradeEnable']) ? 'oui' : 'non' ?></li>
        <li>Reboot auto : <?= !empty($viewSnap['data']['allowReboot']) ? 'oui' : 'non' ?></li>
      </ul>
    </details>
  </section>
  <?php endif; ?>
</div>

<script>window.LINUX_MDM = <?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<?php
layout_footer('common.js', 'mdm.js', 'profiles-folders.js', 'folders-rename.js');
