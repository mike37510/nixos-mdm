<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/layout.php';

use NixVm\Auth;

Auth::start();
Auth::ensureSeeded();

if (Auth::check()) {
    header('Location: /mdm/');
    exit;
}

$error = null;
$next = (string) ($_GET['next'] ?? '/mdm/');
if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//')) {
    $next = '/mdm/';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim((string) ($_POST['username'] ?? ''));
    $pass = (string) ($_POST['password'] ?? '');
    $next = (string) ($_POST['next'] ?? $next);
    if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//')) {
        $next = '/mdm/';
    }
    if (Auth::attempt($user, $pass)) {
        header('Location: ' . $next);
        exit;
    }
    $error = 'Identifiants incorrects.';
}

layout_header('Connexion', 'login');
$brand = app_branding();
?>
<div class="login-wrap">
  <div class="card login-card">
    <div class="login-brand">
      <img src="<?= e($brand['logo_url']) ?>" alt="<?= e($brand['logo_alt']) ?>" class="login-logo">
      <h1>Connexion</h1>
      <p class="muted">Accès administrateur <?= e($brand['product_name']) ?></p>
    </div>
    <?php if ($error): ?>
      <div class="banner error"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/login.php" autocomplete="username">
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <label>Utilisateur
        <input type="text" name="username" required autofocus value="<?= e((string) ($_POST['username'] ?? '')) ?>">
      </label>
      <label>Mot de passe
        <input type="password" name="password" required autocomplete="current-password">
      </label>
      <div class="actions" style="margin-top:1.2rem">
        <button class="btn primary" type="submit" style="width:100%">Se connecter</button>
      </div>
    </form>
  </div>
</div>
<?php
layout_footer();
