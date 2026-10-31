<?php
declare(strict_types=1);

/**
 * Centre Logiciels client (self-service) — accès par token appareil.
 */

$token = trim((string) ($_GET['token'] ?? ''));
$okToken = $token !== '' && (bool) preg_match('/^[a-f0-9]{32,64}$/i', $token);
$deviceOk = false;
$deviceError = null;

if ($okToken) {
    try {
        require dirname(__DIR__, 2) . '/src/bootstrap.php';
        require_once dirname(__DIR__) . '/lib/Clients.php';
        $row = (new Clients())->findByToken($token);
        if ($row === null) {
            $deviceError = 'Appareil inconnu. Relancez install-on-client.sh sur ce poste.';
        } else {
            $deviceOk = true;
        }
    } catch (Throwable $e) {
        $deviceError = 'Erreur serveur : ' . $e->getMessage();
    }
}

$brandLogo = '/assets/logo.svg';
$brandIcon = '/assets/logo-icon.png';
$brandAlt = 'Université de Tours';
$brandProduct = 'Linux MDM';
if ($deviceOk) {
    try {
        require_once dirname(__DIR__) . '/lib/AppSettings.php';
        $b = (new AppSettings())->branding();
        $brandLogo = $b['logo_url'];
        $brandIcon = $b['icon_url'];
        $brandAlt = $b['logo_alt'];
        $brandProduct = $b['product_name'];
    } catch (Throwable $e) {
        // defaults
    }
} elseif (is_file(dirname(__DIR__, 2) . '/src/bootstrap.php')) {
    try {
        require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
        require_once dirname(__DIR__) . '/lib/AppSettings.php';
        $b = (new AppSettings())->branding();
        $brandLogo = $b['logo_url'];
        $brandIcon = $b['icon_url'];
        $brandAlt = $b['logo_alt'];
        $brandProduct = $b['product_name'];
    } catch (Throwable $e) {
        // defaults
    }
}

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="application-name" content="Centre Logiciels">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="theme-color" content="#1e88e5">
  <title>Centre Logiciels</title>
  <link rel="icon" href="<?= htmlspecialchars($brandIcon, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="/assets/app.css?v=<?= (int) @filemtime(dirname(__DIR__, 2) . '/assets/app.css') ?>">
  <link rel="stylesheet" href="/assets/software-center.css?v=<?= (int) @filemtime(dirname(__DIR__, 2) . '/assets/software-center.css') ?>">
  <script>
    (() => {
      try {
        const k = 'linuxmdm-theme';
        const s = localStorage.getItem(k);
        const dark = s === 'dark' || (s !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
      } catch (e) {}
    })();
  </script>
</head>
<body class="sc-body sc-app">
  <header class="sc-top">
    <div class="sc-brand">
      <img src="<?= htmlspecialchars($brandLogo, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($brandAlt, ENT_QUOTES, 'UTF-8') ?>" class="brand-logo">
      <div>
        <strong>Centre Logiciels</strong>
        <span class="muted" id="sc-host"><?= htmlspecialchars($brandProduct, ENT_QUOTES, 'UTF-8') ?></span>
      </div>
    </div>
    <nav class="sc-tabs" role="tablist">
      <button type="button" class="sc-tab is-active" data-tab="installed">Installés</button>
      <button type="button" class="sc-tab" data-tab="store">Magasin DSI</button>
      <button type="button" class="sc-tab" data-tab="log">Journal</button>
    </nav>
    <button type="button" class="theme-toggle" id="theme-toggle" aria-label="Basculer le thème" style="margin-left:.35rem">
      <svg class="ico-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M21 14.5A8.5 8.5 0 1 1 9.5 3 7 7 0 0 0 21 14.5z"/>
      </svg>
      <svg class="ico-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/>
      </svg>
    </button>
    <?php if ($deviceOk): ?>
    <div class="sc-sync-bar" id="sc-sync-bar">
      <span class="sc-sync-status" id="sc-sync-status" hidden></span>
      <button type="button" class="sc-sync-btn" id="sc-sync-now" title="Forcer une synchronisation maintenant">
        <span class="sc-sync-spin" aria-hidden="true"></span>
        <span class="sc-sync-label">Synchroniser</span>
      </button>
    </div>
    <?php endif; ?>
  </header>

  <main class="sc-main">
    <?php if (!$okToken): ?>
      <div class="banner error">
        Jeton appareil manquant. Ouvrez <strong>Centre Logiciels</strong> depuis le menu Applications.
      </div>
    <?php elseif (!$deviceOk): ?>
      <div class="banner error"><?= htmlspecialchars((string) $deviceError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php else: ?>
      <div id="sc-banner" class="banner info" hidden></div>
      <section id="panel-installed" class="sc-panel">
        <h1>Applications du profil</h1>
        <p class="muted" id="sc-profile-line">Chargement…</p>
        <h2 class="sc-section">Obligatoires (DSI / profil)</h2>
        <div class="sw-grid" id="list-mandatory"></div>
        <h2 class="sc-section">Choisies dans le magasin</h2>
        <div class="sw-grid" id="list-optional"></div>
        <p class="hint">Installer / retirer déclenche une <strong>sync immédiate</strong>.
          Vous pouvez aussi cliquer sur <strong>Synchroniser</strong> dans la barre du haut.</p>
      </section>
      <section id="panel-store" class="sc-panel" hidden>
        <h1>Magasin DSI</h1>
        <p class="muted">Applications validées — cliquez pour installer ou retirer.</p>
        <div class="sw-grid" id="list-store"></div>
      </section>
      <section id="panel-log" class="sc-panel" hidden>
        <div class="sc-log-head">
          <div>
            <h1>Journal client</h1>
            <p class="muted">Événements du Centre Logiciels et sortie de la sync locale (<code>nix-builder-mdm-sync</code>).</p>
          </div>
          <div class="sc-log-actions">
            <button type="button" class="btn small" id="sc-log-refresh">Actualiser</button>
            <button type="button" class="btn small" id="sc-log-clear">Effacer</button>
          </div>
        </div>
        <p class="sc-log-status muted" id="sc-log-status">—</p>
        <pre class="sc-log" id="sc-log" aria-live="polite"></pre>
      </section>
    <?php endif; ?>
  </main>

  <footer class="sc-foot">Linux MDM v<?= htmlspecialchars(
    defined('APP_VERSION')
      ? (string) APP_VERSION
      : (trim((string) @file_get_contents(dirname(__DIR__, 2) . '/VERSION')) ?: '0.3.0'),
    ENT_QUOTES,
    'UTF-8'
  ) ?> · Centre Logiciels</footer>
  <script src="/assets/theme.js?v=<?= (int) @filemtime(dirname(__DIR__, 2) . '/assets/theme.js') ?>"></script>
  <?php if ($deviceOk): ?>
  <script>
    window.LINUX_MDM_SC = <?= json_encode(['token' => $token], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  </script>
  <script src="/assets/software-center.js?v=<?= (int) @filemtime(dirname(__DIR__, 2) . '/assets/software-center.js') ?>"></script>
  <?php endif; ?>
</body>
</html>
