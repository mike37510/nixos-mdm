<?php
declare(strict_types=1);

namespace NixVm;

use RuntimeException;

final class Builder
{
    public static function buildDir(int $buildId): string
    {
        return STORAGE_DIR . '/builds/' . $buildId;
    }

    public static function nixBin(): ?string
    {
        $configured = app_config()['build']['nix_bin'];
        $candidates = $configured !== null ? [$configured] : [
            STORAGE_DIR . '/nix-bin',
            STORAGE_DIR . '/home/.nix-profile/bin/nix',
            '/nix/var/nix/profiles/default/bin/nix',
            '/run/current-system/sw/bin/nix',
            '/usr/local/bin/nix',
            '/usr/bin/nix',
        ];
        foreach ($candidates as $c) {
            if (is_file($c) && is_executable($c)) {
                return realpath($c) ?: $c;
            }
        }
        $found = trim((string) shell_exec('command -v nix 2>/dev/null'));
        return $found !== '' ? $found : null;
    }

    /**
     * @return array{nix: ?string, nixVersion: ?string, kvm: bool, storageWritable: bool, canBuild: bool}
     */
    public static function environment(): array
    {
        $nix = self::nixBin();
        $version = $nix !== null ? trim((string) shell_exec(escapeshellarg($nix) . ' --version 2>/dev/null')) : null;
        $storage = is_dir(STORAGE_DIR) ? is_writable(STORAGE_DIR) : is_writable(dirname(STORAGE_DIR));
        $remote = trim((string) app_config()['build']['extra_args']) !== '';
        return [
            'nix' => $nix,
            'nixVersion' => $version ?: null,
            'kvm' => file_exists('/dev/kvm') || $remote,
            'storageWritable' => $storage,
            'canBuild' => $nix !== null && $storage,
        ];
    }

    /**
     * Enregistre un build pour la config, écrit les sources et lance le worker si possible.
     */
    public static function enqueue(int $configId): int
    {
        $config = Repository::getConfig($configId) ?? throw new RuntimeException('Configuration introuvable.');
        $pdo = Db::pdo();
        $pdo->prepare('INSERT INTO vm_builds (config_id, status, snapshot) VALUES (?, ?, ?)')
            ->execute([$configId, 'queued', json_encode($config['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        $buildId = (int) $pdo->lastInsertId();

        $src = ensure_dir(self::buildDir($buildId) . '/src');
        foreach ((new NixGenerator($config['data']))->files() as $name => $content) {
            file_put_contents("$src/$name", $content);
        }
        chmod("$src/build.sh", 0755);
        file_put_contents(self::buildDir($buildId) . '/build.log', '[' . date('H:i:s') . "] Build #$buildId en file d'attente\n");

        self::dispatch();
        return $buildId;
    }

    /**
     * Démarre des workers pour les builds en attente, dans la limite de max_parallel.
     */
    public static function dispatch(): void
    {
        $pdo = Db::pdo();
        self::reapDeadWorkers();
        $running = (int) $pdo->query("SELECT COUNT(*) FROM vm_builds WHERE status = 'running'")->fetchColumn();
        $slots = max(0, (int) app_config()['build']['max_parallel'] - $running);
        if ($slots === 0) {
            return;
        }
        $queued = $pdo->query("SELECT id FROM vm_builds WHERE status = 'queued' ORDER BY id LIMIT $slots")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($queued as $id) {
            $claimed = $pdo->prepare("UPDATE vm_builds SET status = 'running', started_at = NOW() WHERE id = ? AND status = 'queued'");
            $claimed->execute([$id]);
            if ($claimed->rowCount() === 1) {
                self::spawn((int) $id);
            }
        }
    }

    private static function spawn(int $buildId): void
    {
        $php = app_config()['build']['php_bin'];
        if (str_contains($php, 'php-fpm') || str_contains($php, 'apache') || !is_executable($php)) {
            $php = trim((string) shell_exec('command -v php 2>/dev/null')) ?: '/usr/local/bin/php';
        }
        $cmd = sprintf(
            'setsid %s %s %d >> %s 2>&1 < /dev/null &',
            escapeshellarg($php),
            escapeshellarg(APP_ROOT . '/bin/build-worker.php'),
            $buildId,
            escapeshellarg(self::buildDir($buildId) . '/build.log'),
        );
        exec($cmd);
    }

    public static function cancel(int $buildId): void
    {
        $b = Repository::getBuild($buildId) ?? throw new RuntimeException('Build introuvable.');
        if (!in_array($b['status'], ['queued', 'running'], true)) {
            return;
        }
        Repository::updateBuild($buildId, ['status' => 'cancelled', 'finished_at' => 'NOW()', 'error' => 'Annulé par l\'utilisateur.']);
        if ($b['pid'] && function_exists('posix_kill')) {
            // Le worker est lancé via setsid : tuer son groupe arrête aussi nix et qemu.
            // nix ignore parfois SIGTERM (15) pendant un build : SIGKILL (9) après 3 s. Constantes pcntl indisponibles.
            $pgid = (int) $b['pid'];
            posix_kill(-$pgid, 15);
            for ($i = 0; $i < 30 && posix_kill(-$pgid, 0); $i++) {
                usleep(100_000);
            }
            if (posix_kill(-$pgid, 0)) {
                posix_kill(-$pgid, 9);
                usleep(500_000);
            }
        }
        self::cleanTempBuilds();
        self::log($buildId, 'Build annulé.');
        self::dispatch();
    }

    /** Dossier des builds temporaires de Nix (option build-dir de /etc/nix/nix.conf). */
    public static function nixTempDir(): string
    {
        $conf = @file_get_contents('/etc/nix/nix.conf') ?: '';
        if (preg_match('/^\s*build-dir\s*=\s*(\S+)\s*$/m', $conf, $m)) {
            return $m[1];
        }
        return '/nix/var/nix/builds';
    }

    /** Supprime les dossiers temporaires laissés par un nix tué (plusieurs Go par build avec bureau). */
    private static function cleanTempBuilds(): void
    {
        $dirs = array_merge(glob('/nix/var/nix/builds/nix-*', GLOB_ONLYDIR) ?: [], glob(self::nixTempDir() . '/nix-*', GLOB_ONLYDIR) ?: []);
        foreach (array_unique($dirs) as $tmp) {
            if (preg_match('#/nix-(\d+)-\d+$#', $tmp, $m) && !file_exists('/proc/' . $m[1])) {
                exec('chmod -R u+w ' . escapeshellarg($tmp) . ' 2>/dev/null; rm -rf ' . escapeshellarg($tmp));
            }
        }
    }

    /** Marque en échec les builds dont le worker a disparu (redémarrage serveur, kill...). */
    private static function reapDeadWorkers(): void
    {
        $rows = Db::pdo()->query("SELECT id, pid, started_at FROM vm_builds WHERE status = 'running'")->fetchAll();
        foreach ($rows as $r) {
            $alive = $r['pid'] ? file_exists('/proc/' . (int) $r['pid']) : strtotime((string) $r['started_at']) > time() - 60;
            if (!$alive) {
                Repository::updateBuild((int) $r['id'], ['status' => 'failed', 'finished_at' => 'NOW()', 'error' => 'Le processus de build s\'est arrêté de façon inattendue.']);
            }
        }
    }

    public static function log(int $buildId, string $message): void
    {
        file_put_contents(self::buildDir($buildId) . '/build.log', '[' . date('H:i:s') . "] $message\n", FILE_APPEND);
        // PHASE:* écrit aussi un fichier dédié : le journal Nix est trop long pour s'y fier.
        if (preg_match('/^PHASE:(\w+)\s+(.+)$/u', $message, $m)) {
            self::writePhase($buildId, $m[1], trim($m[2]));
        }
    }

    /** @return array{phase: string, label: string, percent: int}|null */
    private static function readPhase(int $buildId): ?array
    {
        $file = self::buildDir($buildId) . '/phase.json';
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || !isset($data['phase'], $data['label'], $data['percent'])) {
            return null;
        }
        return [
            'phase' => (string) $data['phase'],
            'label' => (string) $data['label'],
            'percent' => (int) $data['percent'],
        ];
    }

    private static function writePhase(int $buildId, string $phase, string $label): void
    {
        $map = [
            'prepare' => 8,
            'nix' => 18,
            'package' => 88,
            'docker' => 95,
            'done' => 100,
        ];
        $payload = [
            'phase' => $phase,
            'label' => $label,
            'percent' => $map[$phase] ?? 15,
            'at' => date('c'),
        ];
        file_put_contents(
            self::buildDir($buildId) . '/phase.json',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    public static function logTail(int $buildId, int $bytes = 60000): string
    {
        $file = self::buildDir($buildId) . '/build.log';
        if (!is_file($file)) {
            return '';
        }
        $size = filesize($file);
        $fh = fopen($file, 'rb');
        if ($size > $bytes) {
            fseek($fh, -$bytes, SEEK_END);
        }
        $data = (string) stream_get_contents($fh);
        fclose($fh);
        return $size > $bytes ? "…\n" . substr($data, (int) strpos($data, "\n") + 1) : $data;
    }

    /**
     * Estimation de progression d'un build OVA (fichier phase + activité du journal).
     *
     * @param array<string, mixed> $build
     * @return array{percent: int, phase: string, label: string}
     */
    public static function progress(array $build): array
    {
        $status = (string) ($build['status'] ?? '');
        if ($status === 'queued') {
            return ['percent' => 3, 'phase' => 'queued', 'label' => 'En file d\'attente…'];
        }
        if ($status === 'success') {
            return ['percent' => 100, 'phase' => 'done', 'label' => 'Terminé'];
        }
        if ($status === 'failed') {
            return ['percent' => 100, 'phase' => 'failed', 'label' => 'Échec'];
        }
        if ($status === 'cancelled') {
            return ['percent' => 100, 'phase' => 'cancelled', 'label' => 'Annulé'];
        }

        $buildId = (int) $build['id'];
        $logFile = self::buildDir($buildId) . '/build.log';
        $logSize = is_file($logFile) ? (int) filesize($logFile) : 0;
        $log = self::logTail($buildId, 200000);

        $phaseInfo = self::readPhase($buildId);
        $phase = $phaseInfo['phase'] ?? 'running';
        $label = $phaseInfo['label'] ?? 'Construction en cours…';
        $percent = $phaseInfo['percent'] ?? 12;

        // Repli si pas encore de phase.json (builds déjà lancés) : chercher dans tout le début du log.
        if ($phaseInfo === null && is_file($logFile)) {
            $head = (string) file_get_contents($logFile, false, null, 0, 8000);
            if (preg_match_all('/^PHASE:(\w+)\s+(.+)$/m', $head . "\n" . $log, $all, PREG_SET_ORDER)) {
                $last = $all[array_key_last($all)];
                $phase = $last[1];
                $label = trim($last[2]);
                $map = ['prepare' => 8, 'nix' => 18, 'package' => 88, 'docker' => 95, 'done' => 100];
                $percent = $map[$phase] ?? 15;
            }
        }

        // Pendant nix : faire avancer la barre selon l'activité (sinon ça reste figé à ~18 %).
        if ($phase === 'nix' || $phase === 'running') {
            $hints = [
                '/downloading|fetch|copying path/i' => [22, 'Téléchargement des paquets Nix…'],
                '/building \'|building path|nixos-system|nixos-vmware/i' => [35, 'Compilation / construction Nix…'],
                '/making image|mkfs|disk image|cptofs|virtiofsd/i' => [55, 'Création du disque VM…'],
                '/qemu-img|convert.*vmdk|subformat=streamOptimized/i' => [75, 'Conversion VMDK…'],
                '/PHASE:package|Création de l.OVA|Copie de l.image/i' => [88, 'Emballage de l\'OVA…'],
                '/PHASE:docker|ZIP Docker/i' => [95, 'ZIP Docker / KasmVNC…'],
            ];
            foreach ($hints as $re => [$p, $l]) {
                if (preg_match($re, $log)) {
                    $percent = max($percent, $p);
                    $label = $l;
                    if ($p >= 88) {
                        $phase = 'package';
                    } elseif ($p >= 55) {
                        $phase = 'nix';
                    }
                }
            }
            // Taille réelle du journal (pas seulement le tail) → progression continue 18→82 %.
            $bump = min(64, (int) ($logSize / 250_000));
            $percent = min(82, max($percent, 18 + $bump));
            if ($phase === 'running') {
                $phase = 'nix';
                $label = $label === 'Construction en cours…' ? 'Construction NixOS…' : $label;
            }
        }

        if ($phase === 'package') {
            $percent = max($percent, 88);
        }
        if ($phase === 'docker') {
            $percent = max($percent, 95);
        }

        if (str_contains($log, 'PHASE:done') || str_contains($log, 'Build terminé')) {
            return ['percent' => 100, 'phase' => 'done', 'label' => 'Terminé'];
        }
        if (str_contains($log, 'ERREUR :')) {
            return ['percent' => 100, 'phase' => 'failed', 'label' => 'Échec'];
        }

        return ['percent' => min(99, $percent), 'phase' => $phase, 'label' => $label];
    }

    /**
     * @return array<string, array{path: string, size: int}>
     */
    public static function artifacts(int $buildId): array
    {
        $out = [];
        foreach (glob(self::buildDir($buildId) . '/out/*') ?: [] as $f) {
            $ext = pathinfo($f, PATHINFO_EXTENSION);
            $base = pathinfo($f, PATHINFO_FILENAME);
            // ZIP Docker/Kasm : clé dédiée (pas « zip » générique).
            if ($ext === 'zip' && str_ends_with($base, '-docker')) {
                $out['docker'] = ['path' => $f, 'size' => (int) filesize($f), 'name' => basename($f)];
                continue;
            }
            $out[$ext] = ['path' => $f, 'size' => (int) filesize($f)];
        }
        if (isset($out['ova'])) {
            $ova = $out['ova']['path'];
            $pos = 0;
            $segments = [];
            foreach (self::ovaMembers($ova) as $name => $m) {
                if (str_ends_with($name, '.vmdk') && !isset($out['vmdk'])) {
                    $out['vmdk'] = ['path' => $ova, 'size' => $m['size'], 'segments' => [[$m['offset'], $m['size']]], 'name' => $name];
                }
                // VMware Workstation rejette le manifeste de ces OVA alors que les empreintes sont justes :
                // l'OVA est servie sans son .mf (facultatif), disponible en téléchargement séparé.
                if (str_ends_with($name, '.mf')) {
                    if ($m['header'] > $pos) {
                        $segments[] = [$pos, $m['header'] - $pos];
                    }
                    $pos = $m['header'] + $m['span'];
                }
            }
            if ($pos > 0) {
                $segments[] = [$pos, $out['ova']['size'] - $pos];
                $out['ova']['segments'] = $segments;
                $out['ova']['size'] = array_sum(array_column($segments, 1));
            }
        }
        return $out;
    }

    /**
     * Fichiers contenus dans une OVA (archive tar), sans l'extraire.
     *
     * @return array<string, array{header: int, span: int, offset: int, size: int}>
     */
    public static function ovaMembers(string $ova): array
    {
        $members = [];
        $fh = @fopen($ova, 'rb');
        if (!$fh) {
            return [];
        }
        $pos = 0;
        $total = (int) filesize($ova);
        while ($pos + 512 <= $total && fseek($fh, $pos) === 0) {
            $h = (string) fread($fh, 512);
            if (strlen($h) < 512 || trim($h, "\0") === '') {
                break;
            }
            $size = (int) octdec(trim(substr($h, 124, 12), "\0 "));
            $span = 512 + (int) ceil($size / 512) * 512;
            if (in_array($h[156], ['0', "\0"], true)) {
                $members[rtrim(substr($h, 0, 100), "\0")] = ['header' => $pos, 'span' => $span, 'offset' => $pos + 512, 'size' => $size];
            }
            $pos += $span;
        }
        fclose($fh);
        return $members;
    }

    public static function deleteBuildFiles(int $buildId): void
    {
        $dir = self::buildDir($buildId);
        if (is_dir($dir)) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    /**
     * Exécuté par bin/build-worker.php.
     */
    public static function run(int $buildId): void
    {
        $build = Repository::getBuild($buildId) ?? throw new RuntimeException("Build #$buildId introuvable");
        Repository::updateBuild($buildId, ['pid' => getmypid()]);
        $spec = $build['snapshot'];
        $dir = self::buildDir($buildId);
        $host = $spec['hostname'];

        try {
            $nix = self::nixBin() ?? throw new RuntimeException(
                "Nix n'est pas installé sur ce serveur. Installez-le (https://nixos.org/download) ou téléchargez le projet flake et lancez ./build.sh sur une machine Linux avec Nix."
            );
            if (!file_exists('/dev/kvm')) {
                self::log($buildId, 'Attention : /dev/kvm absent, la création du disque sera émulée par QEMU (plus lent).');
            }
            $home = ensure_dir(STORAGE_DIR . '/home');
            $env = [
                'HOME' => $home,
                'XDG_CACHE_HOME' => "$home/.cache",
                'PATH' => dirname($nix) . ':/usr/local/bin:/usr/bin:/bin',
                'NIX_REMOTE' => getenv('NIX_REMOTE') ?: (is_dir('/nix/var/nix/daemon-socket') ? 'daemon' : ''),
                'LANG' => 'C.UTF-8',
            ];
            $cmd = sprintf(
                '%s --extra-experimental-features %s build %s -L --out-link %s %s',
                escapeshellarg($nix),
                escapeshellarg('nix-command flakes'),
                escapeshellarg('path:' . $dir . '/src#vmware'),
                escapeshellarg("$dir/result"),
                (string) app_config()['build']['extra_args'],
            );
            self::log($buildId, 'PHASE:prepare Préparation de l\'espace disque…');
            self::cleanTempBuilds();
            self::ensureFreeSpace($buildId, $nix, $env);
            self::log($buildId, 'PHASE:nix Construction NixOS / image disque (peut prendre plusieurs minutes)…');
            self::log($buildId, "Lancement : $cmd");
            // cptofs (copie des fichiers dans le disque) affiche des milliers de faux « error while reading directory » :
            // la copie est pourtant complète, on les retire du journal.
            $filtered = "$cmd 2>&1 | { grep --line-buffered -v 'error while reading directory' || true; }";
            $code = self::exec(['bash', '-o', 'pipefail', '-c', $filtered], $buildId, "$dir/src", $env);
            if ($code !== 0) {
                throw new RuntimeException("nix build a échoué (code $code). Consultez le journal.");
            }

            $vmdk = glob("$dir/result/*.vmdk")[0] ?? throw new RuntimeException('Aucun fichier .vmdk dans le résultat du build.');
            $out = ensure_dir("$dir/out");
            $gen = new NixGenerator($spec);

            if ($spec['vm']['subformat'] === 'streamOptimized') {
                // L'OVA embarque le VMDK : on le lit directement depuis le store au lieu d'en garder une copie.
                self::log($buildId, 'PHASE:package Création de l\'OVA…');
                symlink(realpath($vmdk), "$out/$host.vmdk");
                try {
                    $artifact = self::packageOva($gen, $out, $host);
                } finally {
                    @unlink("$out/$host.vmdk");
                }
            } else {
                self::log($buildId, 'PHASE:package Copie de l\'image…');
                exec(sprintf('cp --sparse=always --no-preserve=mode %s %s', escapeshellarg($vmdk), escapeshellarg("$out/$host.vmdk")), $_, $rc);
                if ($rc !== 0) {
                    throw new RuntimeException('Copie du VMDK impossible.');
                }
                file_put_contents("$out/$host.vmx", $gen->vmx());
                $artifact = "$out/$host.vmdk";
            }
            // Sans ce lien, l'image du store n'est plus protégée et sera libérée au prochain nettoyage.
            @unlink("$dir/result");

            self::log($buildId, 'PHASE:docker Préparation du ZIP Docker / KasmVNC…');
            $dockerZip = self::packageDockerZip($spec, $out);
            self::log($buildId, 'ZIP Docker prêt : ' . basename($dockerZip));

            Repository::updateBuild($buildId, [
                'status' => 'success',
                'finished_at' => 'NOW()',
                'artifact' => basename($artifact),
                'artifact_size' => filesize($artifact),
            ]);
            self::log($buildId, 'PHASE:done Build terminé : ' . basename($artifact) . ' + ' . basename($dockerZip));
        } catch (\Throwable $e) {
            self::log($buildId, 'ERREUR : ' . $e->getMessage());
            $current = Repository::getBuild($buildId);
            if ($current && $current['status'] === 'running') {
                Repository::updateBuild($buildId, ['status' => 'failed', 'finished_at' => 'NOW()', 'error' => $e->getMessage()]);
            }
        } finally {
            self::dispatch();
        }
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     */
    private static function exec(array $command, int $buildId, string $cwd, array $env): int
    {
        $log = self::buildDir($buildId) . '/build.log';
        $proc = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $cwd, $env);
        if (!is_resource($proc)) {
            throw new RuntimeException('Impossible de lancer ' . $command[0] . '.');
        }
        return proc_close($proc);
    }

    /**
     * Une image avec bureau peut occuper beaucoup d'espace : on nettoie si besoin.
     * Au-dessous du seuil « soft » → GC + avertissement. Au-dessous du seuil « dur » → échec.
     *
     * @param array<string, string> $env
     */
    private static function ensureFreeSpace(int $buildId, string $nix, array $env): void
    {
        $cfg = app_config()['build'];
        $temp = self::nixTempDir();
        $hard = (float) ($cfg['hard_min_free_gb'] ?? 6);
        $checks = [
            // [dossier, minimum soft en Go, description]
            [$temp, (float) ($cfg['min_free_gb'] ?? 12), 'builds temporaires'],
            ['/nix', (float) ($cfg['min_free_store_gb'] ?? 8), 'store Nix'],
        ];
        $free = static fn (string $dir): float => ((float) @disk_free_space(is_dir($dir) ? $dir : '/nix')) / 1024 ** 3;
        $short = static fn (): array => array_filter($checks, static fn ($c) => $free($c[0]) < $c[1]);

        if ($short() === []) {
            return;
        }
        self::cleanTempBuilds();
        // Fichiers temproots laissés par un nix root : bloquent le GC pour www-data.
        foreach (glob('/nix/var/nix/temproots/*') ?: [] as $tr) {
            $owner = fileowner($tr);
            if ($owner !== false && $owner === 0 && !file_exists('/proc/' . basename($tr))) {
                @unlink($tr);
            }
        }
        self::log($buildId, 'Espace disque faible : nettoyage du store Nix…');
        self::exec([$nix, '--extra-experimental-features', 'nix-command', 'store', 'gc'], $buildId, STORAGE_DIR, $env);

        $critical = array_filter($checks, static fn ($c) => $free($c[0]) < $hard);
        if ($critical !== []) {
            throw new RuntimeException('Espace disque insuffisant : ' . implode(' ; ', array_map(
                static fn ($c) => sprintf('%s (%s) : %.1f Go libres, minimum %.0f Go', $c[2], $c[0], $free($c[0]), $hard),
                $critical,
            )) . '. Libérez de l\'espace puis relancez.');
        }

        $stillShort = $short();
        if ($stillShort !== []) {
            self::log($buildId, 'Attention : espace juste — ' . implode(' ; ', array_map(
                static fn ($c) => sprintf('%s : %.1f Go libres (cible %.0f Go)', $c[2], $free($c[0]), $c[1]),
                $stillShort,
            )) . '. Le build continue quand même.');
        }
    }

    private static function packageOva(NixGenerator $gen, string $out, string $host): string
    {
        $vmdk = "$out/$host.vmdk";
        $ovf = "$out/$host.ovf";
        file_put_contents($ovf, $gen->ovf((int) filesize($vmdk), self::vmdkCapacity($vmdk)));
        file_put_contents(
            "$out/$host.mf",
            sprintf("SHA256(%s.ovf)= %s\nSHA256(%s.vmdk)= %s\n", $host, hash_file('sha256', $ovf), $host, hash_file('sha256', $vmdk)),
        );
        $ova = "$out/$host.ova";
        // VMware ne lit de façon fiable que le format ustar (limité à 8 Gio par fichier).
        $format = filesize($vmdk) < 8 * 1024 ** 3 ? 'ustar' : 'posix';
        exec(sprintf('tar -chf %s --format=%s --owner=0 --group=0 --mode=0644 -C %s %s %s', escapeshellarg($ova), $format, escapeshellarg($out), escapeshellarg("$host.ovf"), escapeshellarg("$host.vmdk")), $_, $rc);
        if ($rc !== 0) {
            throw new RuntimeException('Création de l\'OVA impossible.');
        }
        return $ova;
    }

    /**
     * ZIP projet Docker / KasmVNC (flake + scripts) prêt à télécharger.
     *
     * @param array<string, mixed> $spec
     */
    public static function packageDockerZip(array $spec, string $outDir): string
    {
        $host = (string) $spec['hostname'];
        $path = "$outDir/{$host}-docker.zip";
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::OVERWRITE | \ZipArchive::CREATE) !== true) {
            throw new RuntimeException('Impossible de créer le ZIP Docker / KasmVNC.');
        }
        $root = $host . '/';
        foreach ((new DockerGenerator($spec))->files() as $name => $content) {
            $zip->addFromString($root . $name, $content);
            if (in_array($name, ['build.sh', 'start-desktop.sh'], true)) {
                $zip->setExternalAttributesName($root . $name, \ZipArchive::OPSYS_UNIX, 0100755 << 16);
            }
        }
        $zip->close();
        return $path;
    }

    /** Taille virtuelle (octets) lue dans l'en-tête d'un VMDK sparse. */
    public static function vmdkCapacity(string $vmdk): int
    {
        $fh = fopen($vmdk, 'rb');
        $header = (string) fread($fh, 20);
        fclose($fh);
        if (strlen($header) < 20 || substr($header, 0, 4) !== 'KDMV') {
            throw new RuntimeException('En-tête VMDK sparse invalide.');
        }
        return unpack('P', substr($header, 12, 8))[1] * 512;
    }
}
