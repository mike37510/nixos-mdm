<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/Users.php';

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require dirname(__DIR__, 2) . '/src/layout.php';

use NixVm\Auth;

Auth::requireAdmin();

$usersApi = new Users();
$me = Auth::user();
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'create') {
            $created = $usersApi->create(
                (string) ($_POST['username'] ?? ''),
                (string) ($_POST['password'] ?? ''),
                (string) ($_POST['role'] ?? 'dsi')
            );
            $message = 'Compte « ' . $created['username'] . ' » créé ('
                . Users::roleLabel($created['role']) . ').';
        } elseif ($action === 'set_role') {
            $id = (int) ($_POST['user_id'] ?? 0);
            $role = (string) ($_POST['role'] ?? '');
            $usersApi->setRole($id, $role);
            $u = $usersApi->find($id);
            if ($u !== null) {
                Auth::refreshSessionUser($u['id'], $u['username'], $u['role']);
            }
            $message = 'Rôle mis à jour.';
        } elseif ($action === 'set_password') {
            $id = (int) ($_POST['user_id'] ?? 0);
            $pass = (string) ($_POST['password'] ?? '');
            $pass2 = (string) ($_POST['password_confirm'] ?? '');
            if ($pass !== $pass2) {
                throw new InvalidArgumentException('Les mots de passe ne correspondent pas.');
            }
            $usersApi->setPassword($id, $pass);
            $message = 'Mot de passe mis à jour.';
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['user_id'] ?? 0);
            $usersApi->delete($id, $me !== null ? (int) $me['id'] : null);
            $message = 'Compte supprimé.';
        } else {
            throw new InvalidArgumentException('Action inconnue.');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$rows = $usersApi->listAll();
$adminCount = $usersApi->countAdmins();

layout_header('Utilisateurs', 'utilisateurs');
?>
<section class="hero">
  <div>
    <h1>Utilisateurs</h1>
    <p>
      Comptes d’accès au hub. Les <strong>administrateurs</strong> gèrent l’outil et les utilisateurs ;
      le rôle <strong>DSI</strong> gère profils, machines et logiciels.
    </p>
  </div>
</section>

<?php if ($message): ?><div class="banner info"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="banner error"><?= e($error) ?></div><?php endif; ?>

<section class="card">
  <h2>Nouveau compte</h2>
  <form method="post" class="cfg-form usr-create" style="margin-top:.75rem" autocomplete="off">
    <input type="hidden" name="action" value="create">
    <div class="grid-3">
      <label>Identifiant
        <input type="text" name="username" required maxlength="64" pattern="[a-zA-Z0-9._@+\-]+"
               placeholder="ex. jdupont" autocomplete="off">
      </label>
      <label>Mot de passe
        <input type="password" name="password" required minlength="8" maxlength="200"
               autocomplete="new-password">
      </label>
      <label>Rôle
        <select name="role">
          <option value="dsi">DSI</option>
          <option value="admin">Administrateur</option>
        </select>
      </label>
    </div>
    <p class="hint" style="margin:0">Mot de passe : 8 caractères minimum.</p>
    <div class="cfg-actions">
      <button class="btn primary" type="submit">Créer</button>
    </div>
  </form>
</section>

<section class="card" style="margin-top:1rem">
  <h2>Comptes <span class="count"><?= count($rows) ?></span></h2>
  <?php if ($rows === []): ?>
    <div class="empty">Aucun utilisateur.</div>
  <?php else: ?>
    <div class="table-wrap" style="margin-top:.75rem">
      <table class="data-table usr-table">
        <thead>
          <tr>
            <th>Identifiant</th>
            <th>Rôle</th>
            <th>Créé</th>
            <th>Mot de passe</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $u):
              $isMe = $me !== null && (int) $me['id'] === (int) $u['id'];
              $isLastAdmin = $u['role'] === 'admin' && $adminCount <= 1;
              ?>
            <tr>
              <td>
                <strong><?= e($u['username']) ?></strong>
                <?php if ($isMe): ?><span class="sw-badge">vous</span><?php endif; ?>
              </td>
              <td>
                <form method="post" class="usr-inline">
                  <input type="hidden" name="action" value="set_role">
                  <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                  <select name="role" onchange="this.form.submit()"
                          aria-label="Rôle de <?= e($u['username']) ?>"
                          <?= $isLastAdmin ? 'disabled title="Dernier administrateur"' : '' ?>>
                    <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Administrateur</option>
                    <option value="dsi" <?= $u['role'] === 'dsi' ? 'selected' : '' ?>>DSI</option>
                  </select>
                </form>
              </td>
              <td class="muted mono" style="white-space:nowrap;font-size:.82rem">
                <?= e($u['created_at'] !== '' ? substr($u['created_at'], 0, 16) : '—') ?>
              </td>
              <td>
                <details class="usr-pass">
                  <summary>Changer</summary>
                  <form method="post" class="usr-pass-form" autocomplete="off">
                    <input type="hidden" name="action" value="set_password">
                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                    <input type="password" name="password" required minlength="8" maxlength="200"
                           placeholder="Nouveau mot de passe" autocomplete="new-password">
                    <input type="password" name="password_confirm" required minlength="8" maxlength="200"
                           placeholder="Confirmer" autocomplete="new-password">
                    <button class="btn small primary" type="submit">Enregistrer</button>
                  </form>
                </details>
              </td>
              <td class="usr-del">
                <?php if ($isMe || $isLastAdmin): ?>
                  <span class="hint">—</span>
                <?php else: ?>
                  <form method="post"
                        onsubmit="return confirm('Supprimer le compte « <?= e($u['username']) ?> » ?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                    <button class="btn small danger" type="submit">Supprimer</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section class="card" style="margin-top:1rem">
  <h2>Rôles</h2>
  <ul class="hint" style="margin:.5rem 0 0;padding-left:1.2rem">
    <li><strong>Administrateur</strong> — accès complet, Configuration, gestion des utilisateurs</li>
    <li><strong>DSI</strong> — profils, machines, logiciels, magasin (pas Configuration ni Utilisateurs)</li>
  </ul>
</section>
<?php
layout_footer();
