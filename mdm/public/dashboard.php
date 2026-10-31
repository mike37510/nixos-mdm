<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require dirname(__DIR__, 2) . '/src/layout.php';
require_once dirname(__DIR__) . '/lib/Clients.php';
require_once dirname(__DIR__) . '/lib/Settings.php';
require_once dirname(__DIR__) . '/lib/ProfileVersions.php';

\NixVm\Auth::requireLogin();

$clients = new Clients();
$stats = $clients->dashboardStats();
$alerts = $clients->listRecentAlerts(10);

$pdo = \NixVm\Db::pdo();
$publications = $pdo->query(
    'SELECT v.id, v.profile_id, v.version_num, v.reason, v.change_summary, v.packages_count, v.created_at,
            p.label AS profile_label, p.slug AS profile_slug
     FROM mdm_profile_versions v
     JOIN mdm_profiles p ON p.id = v.profile_id
     ORDER BY v.created_at DESC, v.id DESC
     LIMIT 12'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$profileCount = (int) $pdo->query('SELECT COUNT(*) FROM mdm_profiles')->fetchColumn();

layout_header('Tableau de bord', 'dashboard');
?>
<section class="hero">
  <div>
    <h1>Tableau de bord</h1>
    <p>Vue d’ensemble du parc : sync, alertes et dernières publications de profils.</p>
  </div>
</section>

<section class="dash-stats">
  <a class="dash-stat card" href="/mdm/machines.php">
    <span class="dash-stat-label">Machines</span>
    <strong class="dash-stat-value"><?= (int) $stats['total'] ?></strong>
  </a>
  <a class="dash-stat card" href="/mdm/machines.php">
    <span class="dash-stat-label">Sync OK</span>
    <strong class="dash-stat-value dash-ok"><?= (int) $stats['ok'] ?></strong>
  </a>
  <a class="dash-stat card is-warn" href="/mdm/machines.php">
    <span class="dash-stat-label">Hors sync</span>
    <strong class="dash-stat-value dash-warn"><?= (int) $stats['out_of_sync'] ?></strong>
  </a>
  <a class="dash-stat card is-err" href="/mdm/machines.php">
    <span class="dash-stat-label">Échecs / rollback</span>
    <strong class="dash-stat-value dash-err"><?= (int) $stats['failed'] ?></strong>
    <?php if ($stats['rolled_back'] > 0): ?>
      <span class="dash-stat-sub"><?= (int) $stats['rolled_back'] ?> rollback</span>
    <?php endif; ?>
  </a>
  <a class="dash-stat card" href="/mdm/">
    <span class="dash-stat-label">Profils</span>
    <strong class="dash-stat-value"><?= $profileCount ?></strong>
  </a>
</section>

<div class="grid-2" style="align-items:start;margin-top:1rem">
  <section class="card">
    <h2>Alertes récentes
      <span class="count"><?= count($alerts) ?></span>
    </h2>
    <?php if ($alerts === []): ?>
      <p class="muted">Aucune alerte sync pour le moment.</p>
    <?php else: ?>
      <ul class="dash-alerts">
        <?php foreach ($alerts as $a): ?>
          <li>
            <div class="dash-alert-head">
              <strong><?= e((string) ($a['hostname'] ?: '—')) ?></strong>
              <span class="sync-pill sync-fail">
                <?= !empty($a['rolled_back']) ? 'Rollback' : 'Échec' ?>
              </span>
            </div>
            <div class="muted" style="font-size:.82rem">
              <?= e((string) ($a['profile_label'] ?? '')) ?>
              · <?= !empty($a['last_sync_at']) ? e((string) $a['last_sync_at']) : '—' ?>
              <?php if (isset($a['nix_generation']) && $a['nix_generation'] !== null): ?>
                · gen <?= (int) $a['nix_generation'] ?>
              <?php endif; ?>
            </div>
            <?php if (!empty($a['sync_error'])): ?>
              <div class="mf-card-error"><?= e(mb_strlen((string) $a['sync_error']) > 180
                ? mb_substr((string) $a['sync_error'], 0, 180) . '…'
                : (string) $a['sync_error']) ?></div>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Dernières publications
      <span class="count"><?= count($publications) ?></span>
    </h2>
    <?php if ($publications === []): ?>
      <p class="muted">Aucune version de profil enregistrée.</p>
    <?php else: ?>
      <ul class="dash-pubs">
        <?php foreach ($publications as $p): ?>
          <li>
            <a href="/mdm/?profile=<?= (int) $p['profile_id'] ?>&amp;view_version=<?= (int) $p['id'] ?>">
              <strong><?= e((string) $p['profile_label']) ?></strong>
              <span class="sw-version">v<?= (int) $p['version_num'] ?></span>
            </a>
            <div class="muted" style="font-size:.82rem">
              <?= e((string) $p['created_at']) ?>
              · <?= (int) $p['packages_count'] ?> paquets
              · <?= e((string) $p['reason']) ?>
            </div>
            <?php if (!empty($p['change_summary'])): ?>
              <div class="dash-pub-sum"><?= e((string) $p['change_summary']) ?></div>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>
<?php
layout_footer();
