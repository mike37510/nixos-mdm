<?php
declare(strict_types=1);

/**
 * API publique — renvoie le parc.nix du profil assigné + apps magasin choisies.
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Clients.php';
require_once dirname(__DIR__) . '/lib/Settings.php';
require_once dirname(__DIR__) . '/lib/NixGenerator.php';
require_once dirname(__DIR__) . '/lib/AppSettings.php';
require_once dirname(__DIR__) . '/lib/ProfileVersions.php';

$token = trim((string) ($_GET['token'] ?? ''));
if ($token === '' || !preg_match('/^[a-f0-9]{32,64}$/i', $token)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "# token manquant ou invalide\n";
    exit;
}

$clients = new Clients();
$row = $clients->findByToken($token);
if ($row === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "# appareil inconnu — relancer install-on-client.sh\n";
    exit;
}

$settings = new Settings(dirname(__DIR__) . '/data/settings.json', (int) $row['profile_id']);
$baseUrl = (new AppSettings())->publicHubUrl();
$latest = (new ProfileVersions())->latest((int) $row['profile_id']);
$profileVer = $latest !== null ? (int) $latest['version_num'] : 0;

$data = $settings->all();
$mandatory = array_values(array_map('strval', (array) ($data['packages'] ?? [])));
$optional = Clients::optionalPackagesFromRow($row);
$data['packages'] = array_values(array_unique([...$mandatory, ...$optional]));
$data['hubUrl'] = $baseUrl;
$data['_parcPathSuffix'] = 'parc-for-device.php?token=' . rawurlencode($token);
$data['_profileSlug'] = $settings->slug();
$data['_profileVersion'] = $profileVer;
$data['_includeSoftwareCenter'] = true;

$module = (new NixGenerator(sys_get_temp_dir()))->renderModuleString($data);
$clients->markConfigPull($token, $settings->slug());

header('Content-Type: text/plain; charset=utf-8');
header('X-Nix-MDM-Profile: ' . $settings->slug());
header('X-Nix-MDM-Profile-Version: ' . (string) $profileVer);
header('Cache-Control: no-store');
echo $module;
