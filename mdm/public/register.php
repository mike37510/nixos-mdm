<?php
declare(strict_types=1);

/**
 * API publique — enregistrement / ré-enregistrement d’un client NixOS.
 * POST JSON ou form : hostname, mac, device_token?, profile_slug?
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Clients.php';
require_once dirname(__DIR__) . '/lib/Settings.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST requis']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$input = [];
if (str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    $decoded = json_decode($raw, true);
    $input = is_array($decoded) ? $decoded : [];
} else {
    $input = $_POST;
}

$profileId = null;
$slug = trim((string) ($input['profile_slug'] ?? ''));
if ($slug !== '') {
    $settings = new Settings(dirname(__DIR__) . '/data/settings.json');
    foreach ($settings->listProfiles() as $p) {
        if ($p['slug'] === $slug) {
            $profileId = $p['id'];
            break;
        }
    }
}

try {
    $clients = new Clients();
    $row = $clients->register([
        'device_token' => (string) ($input['device_token'] ?? ''),
        'hostname' => (string) ($input['hostname'] ?? ''),
        'mac' => (string) ($input['mac'] ?? ''),
        'profile_id' => $profileId,
        'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        'meta' => ['registered_via' => 'install'],
    ]);
    $payload = [
        'ok' => true,
        'device_token' => $row['device_token'] ?? null,
        'profile_slug' => $row['profile_slug'] ?? null,
        'profile_label' => $row['profile_label'] ?? null,
        'parc_url' => '/mdm/parc-for-device.php?token=' . rawurlencode((string) ($row['device_token'] ?? '')),
    ];
    $format = (string) ($input['format'] ?? $_GET['format'] ?? 'json');
    if ($format === 'shell') {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'MDM_DEVICE_TOKEN=' . ($payload['device_token'] ?? '') . "\n";
        echo 'MDM_PROFILE=' . ($payload['profile_slug'] ?? '') . "\n";
        exit;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
