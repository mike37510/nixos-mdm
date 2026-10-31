<?php
declare(strict_types=1);

/** @return array{logo_url:string,icon_url:string,logo_alt:string,org_name:string,product_name:string,has_custom_logo:bool,has_custom_icon:bool} */
function app_branding(): array
{
    static $brand = null;
    if ($brand !== null) {
        return $brand;
    }
    try {
        if (!class_exists('AppSettings', false)) {
            $path = dirname(__DIR__) . '/mdm/lib/AppSettings.php';
            if (is_file($path)) {
                require_once $path;
            }
        }
        if (class_exists('AppSettings')) {
            $brand = (new AppSettings())->branding();
            return $brand;
        }
    } catch (Throwable $e) {
        // fallback ci-dessous
    }
    $brand = [
        'logo_url' => '/assets/logo.svg',
        'icon_url' => '/assets/logo-icon.png',
        'logo_alt' => 'Université de Tours',
        'org_name' => 'Université de Tours',
        'product_name' => 'Linux MDM',
        'has_custom_logo' => false,
        'has_custom_icon' => false,
    ];
    return $brand;
}

function layout_header(string $title, string $active = ''): void
{
    $v = (string) @filemtime(APP_ROOT . '/assets/app.css');
    $user = class_exists(\NixVm\Auth::class) ? \NixVm\Auth::user() : null;
    $isLogin = $active === 'login';
    $brand = app_branding();
    $product = $brand['product_name'];
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> · <?= e($product) ?></title>
  <link rel="icon" href="<?= e($brand['icon_url']) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/app.css?v=<?= e($v) ?>">
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
<body class="mp-body<?= $isLogin ? ' mp-login' : '' ?>">
<?php if (!$isLogin): ?>
  <aside class="mp-sidebar" id="mp-sidebar">
    <div class="mp-sidebar-head">
      <img src="<?= e($brand['logo_url']) ?>" alt="<?= e($brand['logo_alt']) ?>" class="mp-sidebar-logo">
      <div class="mp-sidebar-user">
        <strong><?= e($product) ?></strong>
        <span><?= $user ? e($user['username']) : 'Administration' ?></span>
      </div>
    </div>
    <nav class="mp-nav">
      <div class="mp-nav-label">Gestion</div>
      <a href="/mdm/" class="<?= $active === 'mdm' ? 'is-active' : '' ?>">
        <span class="mp-nav-ico" aria-hidden="true"><?= mp_ico('grid') ?></span> Profils
      </a>
      <a href="/mdm/machines.php" class="<?= $active === 'machines' ? 'is-active' : '' ?>">
        <span class="mp-nav-ico" aria-hidden="true"><?= mp_ico('monitor') ?></span> Machines
      </a>
      <div class="mp-nav-label">Logiciels</div>
      <a href="/mdm/centre.php" class="<?= $active === 'centre' ? 'is-active' : '' ?>">
        <span class="mp-nav-ico" aria-hidden="true"><?= mp_ico('apps') ?></span> Logiciels
      </a>
      <a href="/mdm/magasin.php" class="<?= $active === 'magasin' ? 'is-active' : '' ?>">
        <span class="mp-nav-ico" aria-hidden="true"><?= mp_ico('store') ?></span> Magasin
      </a>
      <div class="mp-nav-label">Outil</div>
      <?php if ($user && ($user['role'] ?? '') === 'admin'): ?>
      <a href="/mdm/configuration.php" class="<?= $active === 'configuration' ? 'is-active' : '' ?>">
        <span class="mp-nav-ico" aria-hidden="true"><?= mp_ico('settings') ?></span> Configuration
      </a>
      <a href="/mdm/utilisateurs.php" class="<?= $active === 'utilisateurs' ? 'is-active' : '' ?>">
        <span class="mp-nav-ico" aria-hidden="true"><?= mp_ico('users') ?></span> Utilisateurs
      </a>
      <?php endif; ?>
      <div class="mp-nav-label">Aide</div>
      <a href="/mdm/aide.php" class="<?= $active === 'aide' ? 'is-active' : '' ?>">
        <span class="mp-nav-ico" aria-hidden="true"><?= mp_ico('book') ?></span> Documentation
      </a>
      <a href="/mdm/changelog.php" class="<?= $active === 'changelog' ? 'is-active' : '' ?>">
        <span class="mp-nav-ico" aria-hidden="true"><?= mp_ico('list') ?></span> Changelog
      </a>
    </nav>
  </aside>
  <div class="mp-shell">
    <header class="mp-topbar">
      <button type="button" class="mp-menu-btn" id="mp-menu-btn" aria-label="Menu">☰</button>
      <a class="mp-top-brand" href="/mdm/">
        <span><?= e($product) ?></span>
      </a>
      <div class="mp-topbar-spacer"></div>
      <button type="button" class="theme-toggle" id="theme-toggle" aria-label="Basculer le thème">
        <svg class="ico-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M21 14.5A8.5 8.5 0 1 1 9.5 3 7 7 0 0 0 21 14.5z"/>
        </svg>
        <svg class="ico-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/>
        </svg>
      </button>
      <?php if ($user): ?>
        <div class="mp-topbar-user">
          <span class="mp-avatar"><?= e(strtoupper(substr($user['username'], 0, 1))) ?></span>
          <span class="mp-username"><?= e($user['username']) ?></span>
          <a class="btn small ghost light" href="/logout.php">Déconnexion</a>
        </div>
      <?php endif; ?>
    </header>
    <main class="mp-main">
<?php else: ?>
  <div class="mp-shell mp-shell-login">
    <header class="mp-topbar">
      <a class="mp-top-brand" href="/login.php">
        <span><?= e($product) ?></span>
      </a>
      <div class="mp-topbar-spacer"></div>
      <button type="button" class="theme-toggle" id="theme-toggle" aria-label="Basculer le thème">
        <svg class="ico-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M21 14.5A8.5 8.5 0 1 1 9.5 3 7 7 0 0 0 21 14.5z"/>
        </svg>
        <svg class="ico-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/>
        </svg>
      </button>
    </header>
    <main class="mp-main">
<?php endif; ?>
<?php
}

function layout_footer(string ...$scripts): void
{
    ?>
    </main>
    <footer class="mp-footer">
      <span><?= e(app_branding()['org_name']) ?> · <?= e(app_branding()['product_name']) ?>
        <a class="mp-footer-ver" href="/mdm/changelog.php" title="Voir le changelog">v<?= e(APP_VERSION) ?></a>
      </span>
      <a class="mp-footer-cl" href="/mdm/changelog.php">Changelog</a>
    </footer>
  </div>
  <div id="toast" class="toast" role="status" aria-live="polite"></div>
  <dialog id="progress-dialog" class="progress-dialog">
    <div class="progress-dialog-body">
      <h3 id="progress-title">Préparation…</h3>
      <p class="muted" id="progress-label">Veuillez patienter.</p>
      <div class="progress-track" aria-hidden="true">
        <div class="progress-fill" id="progress-fill" style="width: 0%"></div>
      </div>
      <p class="mono progress-pct" id="progress-pct">0 %</p>
    </div>
  </dialog>
  <script>
    (() => {
      const btn = document.getElementById('mp-menu-btn');
      const side = document.getElementById('mp-sidebar');
      if (btn && side) {
        btn.addEventListener('click', () => document.body.classList.toggle('mp-sidebar-open'));
      }
    })();
  </script>
  <script src="/assets/theme.js?v=<?= e((string) @filemtime(APP_ROOT . '/assets/theme.js')) ?>"></script>
<?php foreach ($scripts as $s): ?>
  <script src="/assets/<?= e($s) ?>?v=<?= e((string) @filemtime(APP_ROOT . '/assets/' . $s)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}

function mp_ico(string $name): string
{
    $paths = [
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'monitor' => '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>',
        'apps' => '<rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="8" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/><rect x="13" y="13" width="8" height="8" rx="1.5"/>',
        'store' => '<path d="M4 7h16l-1 12H5L4 7z"/><path d="M8 7V5a4 4 0 0 1 8 0v2"/>',
        'book' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
        'list' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/>',
        'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    ];
    $p = $paths[$name] ?? $paths['grid'];
    return '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
}

/** Icône texte colorée pour un paquet (équivalent simple aux icônes SCCM). */
function package_icon_svg(string $attr, int $size = 40): string
{
    $letter = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $attr) ?: 'P', 0, 1));
    $hash = crc32($attr);
    $h = $hash % 360;
    $bg = "hsl({$h} 42% 38%)";
    $fg = '#fff';
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 40 40" role="img" aria-hidden="true">'
        . '<rect width="40" height="40" rx="10" fill="' . $bg . '"/>'
        . '<text x="20" y="26" text-anchor="middle" font-family="Roboto,sans-serif" font-size="18" font-weight="700" fill="' . $fg . '">'
        . htmlspecialchars($letter, ENT_QUOTES, 'UTF-8')
        . '</text></svg>';
}

function env_banner(): void
{
    $env = NixVm\Builder::environment();
    if ($env['canBuild'] && $env['kvm']) {
        return;
    }
    ?>
    <?php if ($env['canBuild']): ?>
    <p class="note">
      Information : les images se construisent normalement, sans accélération matérielle
      (environ 3 min pour un serveur, 10 min pour un bureau). Aucune action requise.
    </p>
    <?php return; endif; ?>
    <div class="banner info">
      <?php if (!$env['nix']): ?>
        <strong>Nix n'est pas installé sur ce serveur.</strong>
        Vous pouvez créer et télécharger vos projets (flake + script de build), mais pas construire l'image ici.
        Lancez <code>sudo bin/install-nix.sh</code> (ou redémarrez le conteneur Docker) pour activer la construction des VMDK/OVA.
      <?php else: ?>
        <strong>Le dossier <code>storage/</code> n'est pas accessible en écriture</strong> par le serveur web.
      <?php endif; ?>
    </div>
    <?php
}

function format_bytes(?int $bytes): string
{
    if ($bytes === null) {
        return '';
    }
    $u = ['o', 'Ko', 'Mo', 'Go', 'To'];
    $i = 0;
    $n = (float) $bytes;
    while ($n >= 1024 && $i < count($u) - 1) {
        $n /= 1024;
        $i++;
    }
    return number_format($n, $i > 1 ? 1 : 0, ',', ' ') . ' ' . $u[$i];
}

/** @return array{0: string, 1: string} libellé, classe CSS */
function status_label(?string $status): array
{
    return match ($status) {
        'queued' => ['En file', 'queued'],
        'running' => ['En cours', 'running'],
        'success' => ['OVA + Docker prêts', 'success'],
        'failed' => ['Échec', 'failed'],
        'cancelled' => ['Annulé', 'cancelled'],
        default => ['Jamais construite', 'none'],
    };
}
