<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/AppSettings.php';

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require dirname(__DIR__, 2) . '/src/layout.php';

use NixVm\Auth;

Auth::requireAdmin();

$app = new AppSettings();
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'save_branding') {
            $app->update([
                'org_name' => trim((string) ($_POST['org_name'] ?? '')),
                'product_name' => trim((string) ($_POST['product_name'] ?? 'Linux MDM')) ?: 'Linux MDM',
                'logo_alt' => trim((string) ($_POST['logo_alt'] ?? '')),
            ]);
            $message = 'Identité enregistrée.';
        } elseif ($action === 'save_hub_url') {
            $result = $app->savePublicHubUrl((string) ($_POST['public_hub_url'] ?? ''));
            $mode = $app->configuredHubUrl() === '' ? 'automatique (requête HTTP)' : 'fixe';
            $message = 'URL publique enregistrée (' . $mode . ') : ' . $result['hub']
                . ' — ' . $result['regenerated'] . ' profil(s) régénéré(s).';
        } elseif ($action === 'upload_logo') {
            if (!isset($_FILES['logo']) || !is_array($_FILES['logo'])) {
                throw new InvalidArgumentException('Fichier logo manquant.');
            }
            /** @var array{name:string,type:string,tmp_name:string,error:int,size:int} $f */
            $f = $_FILES['logo'];
            $app->saveLogoUpload($f, 'logo');
            $message = 'Logo mis à jour. Il s’affiche dans le hub et le Centre Logiciels.';
        } elseif ($action === 'upload_icon') {
            if (!isset($_FILES['icon']) || !is_array($_FILES['icon'])) {
                throw new InvalidArgumentException('Fichier icône manquant.');
            }
            /** @var array{name:string,type:string,tmp_name:string,error:int,size:int} $f */
            $f = $_FILES['icon'];
            $app->saveLogoUpload($f, 'icon');
            $message = 'Icône (favicon / lanceur) mise à jour.';
        } elseif ($action === 'reset_logo') {
            $app->resetLogo('logo');
            $message = 'Logo réinitialisé (défaut).';
        } elseif ($action === 'reset_icon') {
            $app->resetLogo('icon');
            $message = 'Icône réinitialisée (défaut).';
        } else {
            throw new InvalidArgumentException('Action inconnue.');
        }
        $app = new AppSettings();
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$brand = $app->branding();
$data = $app->all();
$detectedHub = AppSettings::detectRequestHubUrl();
$effectiveHub = $app->publicHubUrl($detectedHub);
$configuredHub = $app->configuredHubUrl();
$installExample = $app->installScriptUrl('default', true);
$curlExample = 'curl -fsSL ' . $installExample . ' | sudo bash';

layout_header('Configuration', 'configuration');
?>
<section class="hero">
  <div>
    <h1>Configuration</h1>
    <p>
      Réglages globaux de l’outil : identité visuelle et <strong>URL publique</strong>
      utilisée par les scripts d’installation client.
    </p>
  </div>
</section>

<?php if ($message): ?><div class="banner info"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="banner error"><?= e($error) ?></div><?php endif; ?>

<section class="card">
  <h2>URL publique du hub</h2>
  <p class="muted" style="margin-top:-.35rem">
    Adresse que les postes clients utilisent pour télécharger
    <code>install-on-client.sh</code> et synchroniser le parc.
    Laissez vide pour déduire automatiquement l’hôte de la requête HTTP.
  </p>
  <form method="post" class="cfg-form" style="margin-top:.75rem">
    <input type="hidden" name="action" value="save_hub_url">
    <label for="public_hub_url">URL du hub <span class="hint">(se termine par /mdm)</span></label>
    <div class="cfg-hub-row">
      <input type="url" id="public_hub_url" name="public_hub_url" value="<?= e($configuredHub) ?>"
             placeholder="<?= e($detectedHub) ?>"
             maxlength="260" autocomplete="off" spellcheck="false">
      <button class="btn primary" type="submit">Enregistrer et régénérer</button>
    </div>
    <p class="hint" style="margin:.5rem 0 0">
      Exemples :
      <code>https://mdmlinux.example.fr</code>,
      <code>…/mdm</code>,
      ou l’URL du script d’install.
      Détection : <code><?= e($detectedHub) ?></code>
      — effective : <code><?= e($effectiveHub) ?></code>
      <?= $configuredHub === '' ? '(auto)' : '(réglage admin)' ?>.
    </p>
  </form>
  <div style="margin-top:1rem">
    <p class="muted" style="margin:0 0 .35rem">Commande client (profil par défaut) :</p>
    <pre class="code-light"><code><?= e($curlExample) ?></code></pre>
  </div>
</section>

<section class="card" style="margin-top:1rem">
  <h2>Identité</h2>
  <form method="post" class="cfg-form" style="margin-top:.75rem">
    <input type="hidden" name="action" value="save_branding">
    <div class="grid-2">
      <label>Organisation
        <input type="text" name="org_name" value="<?= e((string) ($data['org_name'] ?? '')) ?>"
               placeholder="ex. Université de Tours" maxlength="120">
      </label>
      <label>Nom du produit
        <input type="text" name="product_name" value="<?= e((string) ($data['product_name'] ?? 'Linux MDM')) ?>"
               placeholder="Linux MDM" maxlength="80">
      </label>
    </div>
    <label>Texte alternatif du logo
      <input type="text" name="logo_alt" value="<?= e((string) ($data['logo_alt'] ?? '')) ?>"
             placeholder="Affiché si l’image ne charge pas" maxlength="160">
    </label>
    <div class="cfg-actions">
      <button class="btn primary" type="submit">Enregistrer</button>
    </div>
  </form>
</section>

<section class="card" style="margin-top:1rem">
  <h2>Logo</h2>
  <p class="muted" style="margin-top:-.35rem">
    Affiché dans la barre latérale, la page de connexion et le Centre Logiciels.
    Formats : PNG, JPG, WebP, SVG — max. 2&nbsp;Mo.
  </p>
  <div class="cfg-preview">
    <div class="cfg-preview-box">
      <img src="<?= e($brand['logo_url']) ?>" alt="<?= e($brand['logo_alt']) ?>" class="cfg-preview-logo">
    </div>
    <div class="cfg-preview-controls">
      <p class="hint" style="margin:0">
        <?= $brand['has_custom_logo'] ? 'Logo personnalisé actif.' : 'Logo par défaut (livré avec l’outil).' ?>
      </p>
      <div class="cfg-btn-row">
        <form method="post" enctype="multipart/form-data" class="cfg-upload">
          <input type="hidden" name="action" value="upload_logo">
          <input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/svg+xml,image/gif" required>
          <button class="btn primary small" type="submit">Remplacer</button>
        </form>
        <?php if ($brand['has_custom_logo']): ?>
          <form method="post" onsubmit="return confirm('Revenir au logo par défaut ?');">
            <input type="hidden" name="action" value="reset_logo">
            <button class="btn small" type="submit">Réinitialiser</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<section class="card" style="margin-top:1rem">
  <h2>Icône (favicon / lanceur)</h2>
  <p class="muted" style="margin-top:-.35rem">
    Utilisée comme favicon et, si possible, pour l’icône du Centre Logiciels sur les postes.
    Si vide, le logo principal (ou l’icône par défaut) est utilisé.
  </p>
  <div class="cfg-preview">
    <div class="cfg-preview-box cfg-preview-box-sm">
      <img src="<?= e($brand['icon_url']) ?>" alt="" class="cfg-preview-icon">
    </div>
    <div class="cfg-preview-controls">
      <p class="hint" style="margin:0">
        <?= $brand['has_custom_icon'] ? 'Icône personnalisée active.' : 'Icône par défaut ou logo principal.' ?>
      </p>
      <div class="cfg-btn-row">
        <form method="post" enctype="multipart/form-data" class="cfg-upload">
          <input type="hidden" name="action" value="upload_icon">
          <input type="file" name="icon" accept="image/png,image/jpeg,image/webp,image/svg+xml,image/gif" required>
          <button class="btn primary small" type="submit">Remplacer</button>
        </form>
        <?php if ($brand['has_custom_icon']): ?>
          <form method="post" onsubmit="return confirm('Revenir à l’icône par défaut ?');">
            <input type="hidden" name="action" value="reset_icon">
            <button class="btn small" type="submit">Réinitialiser</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
<?php
layout_footer();
