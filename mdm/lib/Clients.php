<?php

declare(strict_types=1);

/**
 * Inventaire des clients MDM (token appareil + MAC/hostname + profil assigné).
 */
final class Clients
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = \NixVm\Db::pdo();
    }

    /**
     * @param array{device_token?:string,hostname?:string,mac?:string,profile_id?:int,ip?:string,meta?:array<string,mixed>} $info
     * @return array<string, mixed>
     */
    public function register(array $info): array
    {
        $token = trim((string) ($info['device_token'] ?? ''));
        if ($token === '' || !preg_match('/^[a-f0-9]{32,64}$/i', $token)) {
            $token = bin2hex(random_bytes(32));
        }
        $hostname = self::cleanHost((string) ($info['hostname'] ?? ''));
        $mac = self::normalizeMac((string) ($info['mac'] ?? ''));
        $ip = substr((string) ($info['ip'] ?? ''), 0, 45);
        $meta = $info['meta'] ?? null;
        $metaJson = is_array($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        $profileId = isset($info['profile_id']) ? (int) $info['profile_id'] : $this->defaultProfileId();

        $existing = $this->findByToken($token);
        if ($existing) {
            $stmt = $this->pdo->prepare(
                'UPDATE mdm_clients SET hostname = COALESCE(NULLIF(:hostname, \'\'), hostname),
                    mac = COALESCE(NULLIF(:mac, \'\'), mac),
                    last_seen_at = NOW(), last_ip = NULLIF(:ip, \'\'),
                    meta = COALESCE(:meta, meta), updated_at = NOW()
                 WHERE device_token = :token'
            );
            $stmt->execute([
                'hostname' => $hostname,
                'mac' => $mac,
                'ip' => $ip,
                'meta' => $metaJson,
                'token' => $token,
            ]);
            return $this->findByToken($token) ?? $existing;
        }

        // Si un client existe déjà pour cette MAC, réutilise son token (réinstall)
        if ($mac !== '') {
            $byMac = $this->findByMac($mac);
            if ($byMac) {
                $token = (string) $byMac['device_token'];
                $stmt = $this->pdo->prepare(
                    'UPDATE mdm_clients SET hostname = COALESCE(NULLIF(:hostname, \'\'), hostname),
                        last_seen_at = NOW(), last_ip = NULLIF(:ip, \'\'),
                        meta = COALESCE(:meta, meta), updated_at = NOW()
                     WHERE id = :id'
                );
                $stmt->execute([
                    'hostname' => $hostname,
                    'ip' => $ip,
                    'meta' => $metaJson,
                    'id' => (int) $byMac['id'],
                ]);
                return $this->findByToken($token) ?? $byMac;
            }
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO mdm_clients (device_token, hostname, mac, profile_id, last_seen_at, last_ip, meta)
             VALUES (:token, NULLIF(:hostname, \'\'), NULLIF(:mac, \'\'), :profile_id, NOW(), NULLIF(:ip, \'\'), :meta)'
        );
        $stmt->execute([
            'token' => $token,
            'hostname' => $hostname,
            'mac' => $mac,
            'profile_id' => $profileId,
            'ip' => $ip,
            'meta' => $metaJson,
        ]);

        return $this->findByToken($token) ?? [];
    }

    public function markSync(string $token, ?string $deployedSlug = null): void
    {
        if ($deployedSlug !== null && $deployedSlug !== '') {
            $stmt = $this->pdo->prepare(
                'UPDATE mdm_clients SET last_sync_at = NOW(), last_seen_at = NOW(),
                    meta = JSON_SET(COALESCE(meta, \'{}\'), \'$.deployed_profile\', :slug),
                    updated_at = NOW()
                 WHERE device_token = :token'
            );
            $stmt->execute(['slug' => $deployedSlug, 'token' => $token]);
            return;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE mdm_clients SET last_sync_at = NOW(), last_seen_at = NOW(), updated_at = NOW() WHERE device_token = :token'
        );
        $stmt->execute(['token' => $token]);
    }

    public function assignProfile(int $clientId, int $profileId): void
    {
        $check = $this->pdo->prepare('SELECT id FROM mdm_profiles WHERE id = :id');
        $check->execute(['id' => $profileId]);
        if (!$check->fetch()) {
            throw new InvalidArgumentException('Profil introuvable.');
        }
        $stmt = $this->pdo->prepare('UPDATE mdm_clients SET profile_id = :pid, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['pid' => $profileId, 'id' => $clientId]);
    }

    /**
     * Assigne un profil à toutes les machines d’un dossier (null = « Sans dossier »).
     * @return int nombre de machines mises à jour
     */
    public function assignProfileToFolder(?int $folderId, int $profileId): int
    {
        $check = $this->pdo->prepare('SELECT id FROM mdm_profiles WHERE id = :id');
        $check->execute(['id' => $profileId]);
        if (!$check->fetch()) {
            throw new InvalidArgumentException('Profil introuvable.');
        }
        if ($folderId !== null && $folderId > 0) {
            if (!class_exists('MachineFolders', false)) {
                require_once __DIR__ . '/MachineFolders.php';
            }
            $ids = (new \MachineFolders($this->pdo))->subtreeIds($folderId);
            if ($ids === []) {
                throw new InvalidArgumentException('Dossier introuvable.');
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->pdo->prepare(
                "UPDATE mdm_clients SET profile_id = ?, updated_at = NOW() WHERE folder_id IN ($placeholders)"
            );
            $stmt->execute([$profileId, ...$ids]);
        } else {
            $stmt = $this->pdo->prepare(
                'UPDATE mdm_clients SET profile_id = :pid, updated_at = NOW() WHERE folder_id IS NULL'
            );
            $stmt->execute(['pid' => $profileId]);
        }
        return $stmt->rowCount();
    }

    /** Déplace une machine dans un dossier (null = sans dossier). */
    public function assignFolder(int $clientId, ?int $folderId): void
    {
        if ($clientId <= 0) {
            throw new InvalidArgumentException('Machine invalide.');
        }
        if ($folderId !== null && $folderId > 0) {
            $check = $this->pdo->prepare('SELECT id FROM mdm_machine_folders WHERE id = :id');
            $check->execute(['id' => $folderId]);
            if (!$check->fetch()) {
                throw new InvalidArgumentException('Dossier introuvable.');
            }
        } else {
            $folderId = null;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE mdm_clients SET folder_id = :fid, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['fid' => $folderId, 'id' => $clientId]);
        if ($stmt->rowCount() === 0 && $this->findById($clientId) === null) {
            throw new InvalidArgumentException('Machine introuvable.');
        }
    }

    public function delete(int $clientId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM mdm_clients WHERE id = :id');
        $stmt->execute(['id' => $clientId]);
    }

    /** @return list<array<string, mixed>> */
    public function listAll(): array
    {
        $sql = <<<SQL
            SELECT c.*, p.slug AS profile_slug, p.label AS profile_label,
                   f.name AS folder_name
            FROM mdm_clients c
            JOIN mdm_profiles p ON p.id = c.profile_id
            LEFT JOIN mdm_machine_folders f ON f.id = c.folder_id
            ORDER BY
              (c.folder_id IS NULL) ASC,
              f.sort_order ASC,
              f.name ASC,
              c.last_seen_at IS NULL,
              c.last_seen_at DESC,
              c.id DESC
            SQL;
        return $this->pdo->query($sql)->fetchAll();
    }

    public function countUnfiled(): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM mdm_clients WHERE folder_id IS NULL'
        )->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function findByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*, p.slug AS profile_slug, p.label AS profile_label, p.data AS profile_data
             FROM mdm_clients c
             JOIN mdm_profiles p ON p.id = c.profile_id
             WHERE c.device_token = :token LIMIT 1'
        );
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public function findByMac(string $mac): ?array
    {
        $mac = self::normalizeMac($mac);
        if ($mac === '') {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT c.*, p.slug AS profile_slug, p.label AS profile_label
             FROM mdm_clients c
             JOIN mdm_profiles p ON p.id = c.profile_id
             WHERE c.mac = :mac LIMIT 1'
        );
        $stmt->execute(['mac' => $mac]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*, p.slug AS profile_slug, p.label AS profile_label
             FROM mdm_clients c
             JOIN mdm_profiles p ON p.id = c.profile_id
             WHERE c.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @param array<string, mixed> $row */
    public static function optionalPackagesFromRow(array $row): array
    {
        $meta = [];
        if (!empty($row['meta'])) {
            $decoded = json_decode((string) $row['meta'], true);
            $meta = is_array($decoded) ? $decoded : [];
        }
        $pkgs = $meta['optional_packages'] ?? [];
        if (!is_array($pkgs)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('strval', $pkgs))));
    }

    /** @return list<string> */
    public function getOptionalPackages(string $token): array
    {
        $row = $this->findByToken($token);
        return $row ? self::optionalPackagesFromRow($row) : [];
    }

    public function addOptionalPackage(string $token, string $attr): void
    {
        $row = $this->findByToken($token);
        if ($row === null) {
            throw new RuntimeException('Appareil inconnu.');
        }
        $pkgs = self::optionalPackagesFromRow($row);
        if (!in_array($attr, $pkgs, true)) {
            $pkgs[] = $attr;
        }
        $this->setOptionalPackages((int) $row['id'], $pkgs);
    }

    public function removeOptionalPackage(string $token, string $attr): void
    {
        $row = $this->findByToken($token);
        if ($row === null) {
            throw new RuntimeException('Appareil inconnu.');
        }
        $pkgs = array_values(array_filter(
            self::optionalPackagesFromRow($row),
            static fn (string $p) => $p !== $attr
        ));
        $this->setOptionalPackages((int) $row['id'], $pkgs);
    }

    /** @param list<string> $packages */
    private function setOptionalPackages(int $clientId, array $packages): void
    {
        $stmt = $this->pdo->prepare('SELECT meta FROM mdm_clients WHERE id = :id');
        $stmt->execute(['id' => $clientId]);
        $raw = $stmt->fetchColumn();
        $meta = [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $meta = is_array($decoded) ? $decoded : [];
        }
        $meta['optional_packages'] = array_values($packages);
        $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $upd = $this->pdo->prepare('UPDATE mdm_clients SET meta = :meta, updated_at = NOW() WHERE id = :id');
        $upd->execute(['meta' => $json, 'id' => $clientId]);
    }

    private function defaultProfileId(): int
    {
        $id = $this->pdo->query('SELECT id FROM mdm_profiles WHERE is_default = 1 ORDER BY id ASC LIMIT 1')->fetchColumn();
        if ($id === false) {
            $id = $this->pdo->query('SELECT id FROM mdm_profiles ORDER BY id ASC LIMIT 1')->fetchColumn();
        }
        if ($id === false) {
            throw new RuntimeException('Aucun profil MDM disponible pour rattacher un client.');
        }
        return (int) $id;
    }

    private static function cleanHost(string $host): string
    {
        $host = preg_replace('/[^a-zA-Z0-9._-]/', '', $host) ?? '';
        return substr($host, 0, 120);
    }

    public static function normalizeMac(string $mac): string
    {
        $hex = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $mac) ?? '');
        if (strlen($hex) !== 12) {
            return '';
        }
        return implode(':', str_split($hex, 2));
    }
}
