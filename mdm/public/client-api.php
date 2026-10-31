<?php
declare(strict_types=1);

/**
 * API publique Centre logiciels (authentifiée par device token).
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Clients.php';
require_once dirname(__DIR__) . '/lib/Settings.php';

use NixVm\{Catalog, CatalogStore, PackageSearch};

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/** @var array<string, mixed>|null */
$GLOBALS['_client_json_body'] = null;

function client_json_body(): array
{
    if (is_array($GLOBALS['_client_json_body'])) {
        return $GLOBALS['_client_json_body'];
    }
    if (str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        $body = json_decode((string) file_get_contents('php://input'), true);
        $GLOBALS['_client_json_body'] = is_array($body) ? $body : [];
    } else {
        $GLOBALS['_client_json_body'] = $_POST;
    }
    return $GLOBALS['_client_json_body'];
}

function client_token(): string
{
    $token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
    if ($token === '') {
        $token = trim((string) (client_json_body()['token'] ?? ''));
    }
    if ($token === '' || !preg_match('/^[a-f0-9]{32,64}$/i', $token)) {
        http_response_code(401);
        echo json_encode(['error' => 'Token appareil manquant.']);
        exit;
    }
    return $token;
}

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
$token = client_token();
$clients = new Clients();
$row = $clients->findByToken($token);
if ($row === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Appareil inconnu.']);
    exit;
}

try {
    switch ($action) {
        case 'status': {
            $settings = new Settings(dirname(__DIR__) . '/data/settings.json', (int) $row['profile_id']);
            $profilePkgs = array_values(array_map('strval', (array) ($settings->all()['packages'] ?? [])));
            $optional = Clients::optionalPackagesFromRow($row);
            $all = array_values(array_unique([...$profilePkgs, ...$optional]));
            $meta = [];
            try {
                $meta = (new PackageSearch())->resolveMany($all, '26.05');
            } catch (Throwable) {
            }
            $enrich = static function (array $attrs) use ($meta): array {
                $out = [];
                foreach ($attrs as $attr) {
                    $info = $meta[$attr] ?? null;
                    $out[] = [
                        'attr' => $attr,
                        'version' => $info['version'] ?? '',
                        'description' => $info['description'] ?? '',
                        'unfree' => $info['unfree'] ?? false,
                    ];
                }
                return $out;
            };
            echo json_encode([
                'hostname' => $row['hostname'] ?? null,
                'profile' => [
                    'slug' => $row['profile_slug'] ?? null,
                    'label' => $row['profile_label'] ?? null,
                ],
                'mandatory' => $enrich($profilePkgs),
                'optional' => $enrich($optional),
                'last_sync_at' => $row['last_sync_at'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            break;
        }

        case 'catalog': {
            $store = new CatalogStore();
            $items = [];
            $attrs = [];
            foreach ($store->listAll(true) as $r) {
                $attrs[] = (string) $r['attr'];
                $items[] = $r;
            }
            $meta = [];
            try {
                $meta = (new PackageSearch())->resolveMany($attrs, '26.05');
            } catch (Throwable) {
            }
            $optional = Clients::optionalPackagesFromRow($row);
            $settings = new Settings(dirname(__DIR__) . '/data/settings.json', (int) $row['profile_id']);
            $mandatory = array_map('strval', (array) ($settings->all()['packages'] ?? []));
            $out = [];
            foreach ($items as $r) {
                $attr = (string) $r['attr'];
                $info = $meta[$attr] ?? null;
                $state = 'available';
                if (in_array($attr, $mandatory, true)) {
                    $state = 'mandatory';
                } elseif (in_array($attr, $optional, true)) {
                    $state = 'installed';
                }
                $out[] = [
                    'attr' => $attr,
                    'label' => (string) $r['label'],
                    'description' => (string) ($r['description'] ?: ($info['description'] ?? '')),
                    'version' => (string) ($r['version_cache'] ?: ($info['version'] ?? '')),
                    'icon_url' => $r['icon_url'] ?? null,
                    'state' => $state,
                ];
            }
            echo json_encode(['items' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            break;
        }

        case 'install': {
            $body = client_json_body();
            $attr = trim((string) ($body['attr'] ?? $_GET['attr'] ?? ''));
            if (!Catalog::isValidAttr($attr)) {
                throw new InvalidArgumentException('Paquet invalide.');
            }
            $store = new CatalogStore();
            $cat = $store->findByAttr($attr);
            if ($cat === null || empty($cat['approved']) || empty($cat['store_visible'])) {
                throw new RuntimeException('Ce paquet n’est pas publié dans le magasin DSI.');
            }
            $clients->addOptionalPackage($token, $attr);
            echo json_encode(['ok' => true, 'attr' => $attr, 'message' => 'Ajouté — sync immédiate en cours…']);
            break;
        }

        case 'remove': {
            $body = client_json_body();
            $attr = trim((string) ($body['attr'] ?? $_GET['attr'] ?? ''));
            if (!Catalog::isValidAttr($attr)) {
                throw new InvalidArgumentException('Paquet invalide.');
            }
            $clients->removeOptionalPackage($token, $attr);
            echo json_encode(['ok' => true, 'attr' => $attr, 'message' => 'Retiré — sync immédiate en cours…']);
            break;
        }

        case 'sync-now': {
            // Le déclenchement réel est local (agent 17380 / linux-mdm-sync-now).
            echo json_encode([
                'ok' => true,
                'hint' => 'systemctl start nix-builder-mdm-sync.service',
                'agent' => 'http://127.0.0.1:17380/sync-now',
            ]);
            break;
        }

        case 'sync-report': {
            $body = client_json_body();
            $clients->reportSync($token, [
                'status' => (string) ($body['status'] ?? ''),
                'error' => (string) ($body['error'] ?? ''),
                'nix_generation' => $body['nix_generation'] ?? null,
                'profile_version' => $body['profile_version'] ?? null,
                'profile_slug' => (string) ($body['profile_slug'] ?? ''),
                'rolled_back' => !empty($body['rolled_back']),
                'hostname' => (string) ($body['hostname'] ?? ''),
                'ip' => (string) ($body['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')),
            ]);
            echo json_encode(['ok' => true]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Action inconnue.']);
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
