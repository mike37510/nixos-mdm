<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use NixVm\{Auth, Builder, Catalog, DockerGenerator, NixGenerator, PackageSearch, Repository};

Auth::requireLogin();

$action = (string) ($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = [];

if ($method === 'POST') {
    // Protection CSRF minimale : requêtes JSON same-origin uniquement.
    $origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (!str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')
        || ($origin !== '' && parse_url($origin, PHP_URL_HOST) . (parse_url($origin, PHP_URL_PORT) ? ':' . parse_url($origin, PHP_URL_PORT) : '') !== $host)) {
        json_response(['error' => 'Requête refusée.'], 403);
    }
    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) {
        json_response(['error' => 'JSON invalide.'], 400);
    }
}

function previous_spec(array $input): ?array
{
    $id = isset($input['id']) ? (int) $input['id'] : 0;
    return $id > 0 ? (Repository::getConfig($id)['data'] ?? null) : null;
}

/** @param array<string, mixed> $build */
function build_view(array $build): array
{
    $id = (int) $build['id'];
    $artifacts = [];
    foreach (Builder::artifacts($id) as $ext => $a) {
        $artifacts[] = ['ext' => $ext, 'name' => $a['name'] ?? basename($a['path']), 'size' => $a['size']];
    }
    return [
        'id' => $id,
        'configId' => (int) $build['config_id'],
        'status' => $build['status'],
        'error' => $build['error'],
        'artifact' => $build['artifact'],
        'artifactSize' => $build['artifact_size'] !== null ? (int) $build['artifact_size'] : null,
        'createdAt' => $build['created_at'],
        'startedAt' => $build['started_at'],
        'finishedAt' => $build['finished_at'],
        'artifacts' => $artifacts,
        'progress' => Builder::progress($build),
    ];
}

try {
    switch ("$method $action") {
        case 'GET env':
            json_response(Builder::environment());

        case 'GET search':
            $release = (string) ($_GET['release'] ?? '26.05');
            json_response(['results' => (new PackageSearch())->search((string) ($_GET['q'] ?? ''), $release)]);

        case 'POST check-packages':
            $attrs = array_values(array_filter((array) ($input['packages'] ?? []), static fn ($p) => is_string($p) && Catalog::isValidAttr($p)));
            json_response(['missing' => (new PackageSearch())->missing($attrs, (string) ($input['release'] ?? '26.05'))]);

        case 'POST preview':
            [$spec, $errors] = Catalog::normalize((array) ($input['spec'] ?? []), previous_spec($input));
            $format = (string) ($input['format'] ?? 'ova');
            $files = $format === 'docker'
                ? (new DockerGenerator($spec))->files()
                : (new NixGenerator($spec))->files();
            json_response(['files' => $files, 'errors' => $errors, 'format' => $format === 'docker' ? 'docker' : 'ova']);

        case 'POST save':
            $prev = previous_spec($input);
            [$spec, $errors] = Catalog::normalize((array) ($input['spec'] ?? []), $prev);
            if ($errors !== []) {
                json_response(['errors' => $errors], 422);
            }
            $id = Repository::saveConfig($prev !== null ? (int) $input['id'] : null, $spec);
            $buildId = !empty($input['build']) ? Builder::enqueue($id) : null;
            json_response(['id' => $id, 'buildId' => $buildId]);

        case 'POST build':
            json_response(['buildId' => Builder::enqueue((int) ($input['id'] ?? 0))]);

        case 'POST cancel':
            Builder::cancel((int) ($input['id'] ?? 0));
            json_response(['ok' => true]);

        case 'POST duplicate':
            $cfg = Repository::getConfig((int) ($input['id'] ?? 0)) ?? throw new RuntimeException('Configuration introuvable.');
            $spec = $cfg['data'];
            $spec['name'] .= ' (copie)';
            json_response(['id' => Repository::saveConfig(null, $spec)]);

        case 'POST delete':
            Repository::deleteConfig((int) ($input['id'] ?? 0));
            json_response(['ok' => true]);

        case 'POST delete-build':
            $b = Repository::getBuild((int) ($input['id'] ?? 0)) ?? throw new RuntimeException('Build introuvable.');
            if (in_array($b['status'], ['queued', 'running'], true)) {
                Builder::cancel((int) $b['id']);
            }
            Builder::deleteBuildFiles((int) $b['id']);
            NixVm\Db::pdo()->prepare('DELETE FROM vm_builds WHERE id = ?')->execute([(int) $b['id']]);
            json_response(['ok' => true]);

        case 'GET builds':
            Builder::dispatch();
            $configId = (int) ($_GET['config'] ?? 0);
            json_response(['builds' => array_map(build_view(...), Repository::listBuilds($configId))]);

        case 'GET build':
            Builder::dispatch();
            $b = Repository::getBuild((int) ($_GET['id'] ?? 0)) ?? throw new RuntimeException('Build introuvable.');
            json_response(['build' => build_view($b), 'log' => Builder::logTail((int) $b['id'])]);

        default:
            json_response(['error' => 'Action inconnue.'], 404);
    }
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 500);
}
