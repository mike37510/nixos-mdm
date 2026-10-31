<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Settings.php';
require_once dirname(__DIR__) . '/lib/NixGenerator.php';
require_once dirname(__DIR__) . '/lib/ProfileVersions.php';
require_once dirname(__DIR__) . '/lib/AppSettings.php';

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require dirname(__DIR__, 2) . '/src/layout.php';

use NixVm\{Auth, CatalogStore, PackageSearch};

Auth::requireLogin();

$settingsPath = dirname(__DIR__) . '/data/settings.json';
$generatedDir = dirname(__DIR__) . '/public/generated';
$profileId = isset($_GET['profile']) ? (int) $_GET['profile'] : null;
if ($profileId !== null && $profileId <= 0) {
    $profileId = null;
}

$baseUrl = (new AppSettings())->publicHubUrl();

$settings = new Settings($settingsPath, $profileId);
$generator = new NixGenerator($generatedDir);
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        $pid = (int) ($_POST['profile_id'] ?? $settings->profileId());
        if ($pid > 0 && $pid !== $settings->profileId()) {
            $settings->loadProfile($pid);
        }

        if ($action === 'remove') {
            $attr = trim((string) ($_POST['attr'] ?? ''));
            if ($attr === '') {
                throw new InvalidArgumentException('Paquet manquant.');
            }
            $settings->removePackage($attr);
            $settings->setHubUrl($baseUrl);
            $generator->generate($settings->all(), [
                'baseUrl' => $baseUrl,
                'profileSlug' => $settings->slug(),
                'isDefault' => $settings->isDefault(),
            ]);
            (new ProfileVersions())->record($settings->profileId(), $settings->all(), 'Retrait « ' . $attr . ' »');
            header('Location: /mdm/centre.php?profile=' . $settings->profileId() . '&msg=removed&attr=' . rawurlencode($attr));
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$msgKey = (string) ($_GET['msg'] ?? '');
if ($message === null && $msgKey === 'removed') {
    $attr = (string) ($_GET['attr'] ?? '');
    $message = $attr !== ''
        ? "« {$attr} » retiré du profil « {$settings->label()} ». Effectif au prochain sync client."
        : 'Paquet retiré du profil.';
}

$profiles = $settings->listProfiles();
$data = $settings->all();
$packages = array_values(array_map('strval', (array) ($data['packages'] ?? [])));
$release = '26.05';
$freq = (string) ($data['datesLabel'] ?? $data['dates'] ?? '');

$meta = [];
try {
    $meta = (new PackageSearch())->resolveMany($packages, $release);
} catch (Throwable $e) {
    if ($error === null) {
        $error = $e->getMessage();
    }
}

$catalog = new CatalogStore();
$inStore = [];
foreach ($catalog->listAll() as $row) {
    $inStore[(string) $row['attr']] = $row;
}

layout_header('Logiciels', 'centre');
?>
<section class="hero">
  <div>
    <h1>Logiciels</h1>
    <p>
      Paquets du profil MDM : versions nixpkgs, descriptions.
      Retirez un logiciel ici pour le retirer du profil (sync client suivante).
    </p>
  </div>
</section>

<?php if ($message): ?><div class="banner info"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?>
  <div class="banner error"><strong>Erreur :</strong> <?= e($error) ?></div>
<?php endif; ?>

<div class="card" style="margin-bottom:1rem">
  <div class="mdm-profiles-head">
    <h2 style="margin:0">Profil</h2>
    <p class="muted mdm-profiles-meta">Sync : <?= e($freq !== '' ? $freq : '—') ?></p>
  </div>
  <div class="mdm-profile-tabs">
    <?php foreach ($profiles as $p): ?>
      <a class="mdm-profile-tab <?= (int) $p['id'] === $settings->profileId() ? 'is-active' : '' ?>"
         href="/mdm/centre.php?profile=<?= (int) $p['id'] ?>">
        <span class="mdm-profile-tab-name"><?= e($p['label']) ?><?= $p['is_default'] ? ' ★' : '' ?></span>
        <span class="mdm-profile-tab-count"><?= (int) $p['packages'] ?> paquets</span>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<section class="card">
  <h2>À installer / maintenir
    <span class="count"><?= count($packages) ?></span>
  </h2>
  <p class="hint" style="margin-top:-.5rem">
    Profil <strong><?= e($settings->label()) ?></strong> —
    les clients appliquent les changements au prochain cycle MDM.
    Historique : <a href="/mdm/?profile=<?= $settings->profileId() ?>#pv-versions">versions du profil</a>.
  </p>

  <?php if ($packages === []): ?>
    <div class="empty">Aucun paquet dans ce profil. Ajoutez-en depuis <a href="/mdm/?profile=<?= $settings->profileId() ?>">Profils</a>.</div>
  <?php else: ?>
    <div class="sw-grid">
      <?php foreach ($packages as $attr):
          $info = $meta[$attr] ?? null;
          $cat = $inStore[$attr] ?? null;
          $version = $info['version'] ?? ($cat['version_cache'] ?? '');
          $desc = $info['description'] ?? ($cat['description'] ?? '');
          $label = $cat['label'] ?? $attr;
          $iconUrl = $cat['icon_url'] ?? null;
      ?>
        <article class="sw-card">
          <div class="sw-icon">
            <?php if ($iconUrl): ?>
              <img src="<?= e((string) $iconUrl) ?>" alt="" width="40" height="40" loading="lazy">
            <?php else: ?>
              <?= package_icon_svg($attr) ?>
            <?php endif; ?>
          </div>
          <div class="sw-body">
            <h3><?= e((string) $label) ?></h3>
            <p class="sw-meta">
              <code><?= e($attr) ?></code>
              <?php if ($version !== ''): ?>
                <span class="sw-version"><?= e((string) $version) ?></span>
              <?php endif; ?>
              <?php if (!empty($info['unfree'])): ?>
                <span class="sw-badge">unfree</span>
              <?php endif; ?>
            </p>
            <?php if ($desc !== ''): ?>
              <p class="sw-desc"><?= e(mb_strimwidth((string) $desc, 0, 140, '…')) ?></p>
            <?php endif; ?>
            <p class="sw-status">Prochaine sync · profil <?= e($settings->slug()) ?></p>
            <form method="post" class="sc-actions" style="margin-top:.55rem"
                  onsubmit="return confirm('Retirer « <?= e($attr) ?> » du profil <?= e($settings->label()) ?> ?');">
              <input type="hidden" name="action" value="remove">
              <input type="hidden" name="profile_id" value="<?= $settings->profileId() ?>">
              <input type="hidden" name="attr" value="<?= e($attr) ?>">
              <button class="btn small danger" type="submit">Retirer du profil</button>
            </form>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php
layout_footer();
