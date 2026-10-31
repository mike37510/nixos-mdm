<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use NixVm\{Builder, DockerGenerator, NixGenerator, Repository};

/**
 * Envoie $path, ou seulement les plages [offset, longueur] de $segments mises bout à bout.
 *
 * @param list<array{int, int}>|null $segments
 */
function send_file(string $path, string $name, string $type, ?array $segments = null): never
{
    $segments ??= [[0, (int) filesize($path)]];
    $size = array_sum(array_column($segments, 1));
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    set_time_limit(0);
    if (function_exists('apache_setenv')) {
        apache_setenv('no-gzip', '1');
    }
    $start = 0;
    $end = $size - 1;
    header('Accept-Ranges: bytes');
    if (preg_match('/^bytes=(\d*)-(\d*)$/', (string) ($_SERVER['HTTP_RANGE'] ?? ''), $m) && ($m[1] !== '' || $m[2] !== '')) {
        if ($m[1] === '') {
            $start = max(0, $size - (int) $m[2]);
        } else {
            $start = (int) $m[1];
            if ($m[2] !== '') {
                $end = min((int) $m[2], $size - 1);
            }
        }
        if ($start > $end) {
            http_response_code(416);
            header("Content-Range: bytes */$size");
            exit;
        }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Type: ' . $type);
    header('Content-Length: ' . ($end - $start + 1));
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
    header('X-Content-Type-Options: nosniff');
    $fh = fopen($path, 'rb');
    $skip = $start;
    $left = $end - $start + 1;
    foreach ($segments as [$offset, $length]) {
        if ($skip >= $length) {
            $skip -= $length;
            continue;
        }
        fseek($fh, $offset + $skip);
        $n = min($length - $skip, $left);
        $skip = 0;
        while ($n > 0 && connection_status() === CONNECTION_NORMAL) {
            $chunk = fread($fh, min(1 << 20, $n));
            if ($chunk === false || $chunk === '') {
                break 2;
            }
            echo $chunk;
            $n -= strlen($chunk);
            $left -= strlen($chunk);
            flush();
        }
        if ($left <= 0) {
            break;
        }
    }
    fclose($fh);
    exit;
}

function not_found(string $msg): never
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

if (isset($_GET['config'])) {
    $cfg = Repository::getConfig((int) $_GET['config']) ?? not_found('Configuration introuvable.');
    $spec = $cfg['data'];
    $format = (string) ($_GET['format'] ?? 'ova');
    if ($format !== 'docker' && $format !== 'ova') {
        $format = 'ova';
    }
    $tmp = tempnam(sys_get_temp_dir(), 'nixvm');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $root = $spec['hostname'] . '/';
    $files = $format === 'docker'
        ? (new DockerGenerator($spec))->files()
        : (new NixGenerator($spec))->files();
    foreach ($files as $name => $content) {
        $zip->addFromString($root . $name, $content);
        if (in_array($name, ['build.sh', 'start-desktop.sh'], true)) {
            $zip->setExternalAttributesName($root . $name, ZipArchive::OPSYS_UNIX, 0100755 << 16);
        }
    }
    $zip->close();
    register_shutdown_function(static fn () => @unlink($tmp));
    $suffix = $format === 'docker' ? '-docker.zip' : '-nixos-vmware.zip';
    send_file($tmp, $spec['hostname'] . $suffix, 'application/zip');
}

$buildId = (int) ($_GET['build'] ?? 0);
$build = Repository::getBuild($buildId) ?? not_found('Build introuvable.');
$file = (string) ($_GET['file'] ?? '');

if ($file === 'log') {
    $log = Builder::buildDir($buildId) . '/build.log';
    is_file($log) || not_found('Journal introuvable.');
    send_file($log, "build-$buildId.log", 'text/plain; charset=utf-8');
}

$artifacts = Builder::artifacts($buildId);
$a = $artifacts[$file] ?? not_found('Fichier introuvable.');
$types = [
    'ova' => 'application/x-tar',
    'vmdk' => 'application/octet-stream',
    'vmx' => 'text/plain',
    'ovf' => 'application/xml',
    'mf' => 'text/plain',
    'docker' => 'application/zip',
];
send_file($a['path'], $a['name'] ?? basename($a['path']), $types[$file] ?? 'application/octet-stream', $a['segments'] ?? null);
