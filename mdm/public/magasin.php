<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require dirname(__DIR__, 2) . '/src/layout.php';

use NixVm\{Auth, Catalog, CatalogStore, PackageSearch};

Auth::requireLogin();

$store = new CatalogStore();
$message = null;
$error = null;
$tab = (string) ($_GET['tab'] ?? 'store');
if (!in_array($tab, ['store', 'admin'], true)) {
    $tab = 'store';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'add') {
            $attr = trim((string) ($_POST['attr'] ?? ''));
            if (!Catalog::isValidAttr($attr)) {
                throw new InvalidArgumentException('Attribut nixpkgs invalide.');
            }
            $label = trim((string) ($_POST['label'] ?? $attr));
            $desc = trim((string) ($_POST['description'] ?? ''));
            $version = null;
            try {
                $meta = (new PackageSearch())->resolveMany([$attr], '26.05');
                if (isset($meta[$attr])) {
                    $version = $meta[$attr]['version'];
                    if ($desc === '') {
                        $desc = $meta[$attr]['description'];
                    }
                }
            } catch (Throwable) {
                // métadonnées optionnelles
            }
            $store->upsert($attr, $label !== '' ? $label : $attr, $desc, $version, null, true, !empty($_POST['store_visible']));
            $message = "Paquet « {$attr} » ajouté au catalogue DSI.";
            $tab = 'admin';
        } elseif ($action === 'flags') {
            $id = (int) ($_POST['id'] ?? 0);
            $store->setFlags($id, !empty($_POST['approved']), !empty($_POST['store_visible']));
            $message = 'Catalogue mis à jour.';
            $tab = 'admin';
        } elseif ($action === 'delete') {
            $store->delete((int) ($_POST['id'] ?? 0));
            $message = 'Paquet retiré du catalogue.';
            $tab = 'admin';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $tab = 'admin';
    }
}

$all = $store->listAll(false);
$visible = array_values(array_filter($all, static fn ($r) => !empty($r['approved']) && !empty($r['store_visible'])));

layout_header('Magasin', 'magasin');
?>
<section class="hero">
  <div>
    <h1>Magasin d’applications</h1>
    <p>
      Pool de paquets <strong>validés par la DSI</strong>. Les postes clients les installent
      via <strong>Logiciels</strong> (self-service client).
    </p>
  </div>
</section>

<?php if ($message): ?><div class="banner info"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="banner error"><?= e($error) ?></div><?php endif; ?>

<div class="mdm-profile-tabs" style="margin-bottom:1rem">
  <a class="mdm-profile-tab <?= $tab === 'store' ? 'is-active' : '' ?>" href="/mdm/magasin.php?tab=store">
    <span class="mdm-profile-tab-name">Vue magasin</span>
    <span class="mdm-profile-tab-count"><?= count($visible) ?> disponibles</span>
  </a>
  <a class="mdm-profile-tab <?= $tab === 'admin' ? 'is-active' : '' ?>" href="/mdm/magasin.php?tab=admin">
    <span class="mdm-profile-tab-name">Gestion DSI</span>
    <span class="mdm-profile-tab-count"><?= count($all) ?> au catalogue</span>
  </a>
</div>

<?php if ($tab === 'store'): ?>
  <section class="card">
    <h2>Applications validées</h2>
    <?php if ($visible === []): ?>
      <div class="empty">Aucun paquet publié dans le magasin. Ajoutez-en dans <a href="/mdm/magasin.php?tab=admin">Gestion DSI</a>.</div>
    <?php else: ?>
      <div class="sw-grid">
        <?php foreach ($visible as $row): ?>
          <article class="sw-card">
            <div class="sw-icon">
              <?php if (!empty($row['icon_url'])): ?>
                <img src="<?= e((string) $row['icon_url']) ?>" alt="" width="40" height="40" loading="lazy">
              <?php else: ?>
                <?= package_icon_svg((string) $row['attr']) ?>
              <?php endif; ?>
            </div>
            <div class="sw-body">
              <h3><?= e((string) $row['label']) ?></h3>
              <p class="sw-meta">
                <code><?= e((string) $row['attr']) ?></code>
                <?php if (!empty($row['version_cache'])): ?>
                  <span class="sw-version"><?= e((string) $row['version_cache']) ?></span>
                <?php endif; ?>
              </p>
              <?php if (!empty($row['description'])): ?>
                <p class="sw-desc"><?= e(mb_strimwidth((string) $row['description'], 0, 140, '…')) ?></p>
              <?php endif; ?>
              <p class="sw-status">Validé DSI · installable via Logiciels</p>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
<?php else: ?>
  <section class="card" style="margin-bottom:1rem">
    <h2>Ajouter un paquet au pool</h2>
    <p class="hint" style="margin-top:-.35rem">
      Recherchez dans nixpkgs (nom, description…) puis cliquez pour ajouter au catalogue DSI.
    </p>
    <div class="pkg-search" id="magasin-search" style="margin:1rem 0 1.25rem">
      <input type="search" id="magasin-query" placeholder="Rechercher un logiciel (ex : navigateur, office, htop, vscode)…" autocomplete="off">
      <div class="pkg-results" id="magasin-results" hidden></div>
    </div>

    <form method="post" id="magasin-add-form" class="mdm-create-form" style="grid-template-columns:1fr 1fr auto;align-items:end">
      <input type="hidden" name="action" value="add">
      <label>Attribut nixpkgs
        <input type="text" name="attr" id="magasin-attr" class="mono" placeholder="sélectionnez un résultat ou saisissez le nom" required>
      </label>
      <label>Libellé affiché
        <input type="text" name="label" id="magasin-label" placeholder="ex : Navigateur Brave">
      </label>
      <label class="check" style="grid-column:1/-1">
        <input type="checkbox" name="store_visible" value="1" checked>
        Visible dans le magasin utilisateur
      </label>
      <label style="grid-column:1/-1">Description (optionnel)
        <input type="text" name="description" id="magasin-description" placeholder="Remplie auto depuis nixpkgs si vide">
      </label>
      <button class="btn primary" type="submit" style="grid-column:1/-1;justify-self:start">Ajouter au catalogue</button>
    </form>
  </section>

  <section class="card">
    <h2>Catalogue DSI <span class="count"><?= count($all) ?></span></h2>
    <?php if ($all === []): ?>
      <div class="empty">Catalogue vide.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th></th>
              <th>Paquet</th>
              <th>Version</th>
              <th>Statut</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($all as $row): ?>
              <tr>
                <td class="sw-icon-cell"><?= package_icon_svg((string) $row['attr'], 32) ?></td>
                <td>
                  <strong><?= e((string) $row['label']) ?></strong><br>
                  <code><?= e((string) $row['attr']) ?></code>
                </td>
                <td class="mono"><?= e((string) ($row['version_cache'] ?: '—')) ?></td>
                <td colspan="2">
                  <form method="post" class="inline-flags">
                    <input type="hidden" name="action" value="flags">
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <label class="check"><input type="checkbox" name="approved" value="1" <?= !empty($row['approved']) ? 'checked' : '' ?> onchange="this.form.submit()"> Validé</label>
                    <label class="check"><input type="checkbox" name="store_visible" value="1" <?= !empty($row['store_visible']) ? 'checked' : '' ?> onchange="this.form.submit()"> Magasin</label>
                  </form>
                </td>
                <td>
                  <form method="post" onsubmit="return confirm('Retirer ce paquet du catalogue ?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <button class="btn small danger" type="submit">Retirer</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>
<script>
  window.LINUX_MDM_MAGASIN = <?= json_encode([
      'release' => '26.05',
      'existing' => array_values(array_map(static fn ($r) => (string) $r['attr'], $all)),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<?php
layout_footer('common.js', 'magasin.js');
