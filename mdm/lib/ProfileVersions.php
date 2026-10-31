<?php

declare(strict_types=1);

/**
 * Historique versionné des profils MDM (snapshot JSON à chaque publication / changement de paquets).
 */
final class ProfileVersions
{
    private const KEEP = 80;

    private \PDO $pdo;

    public function __construct(?\PDO $pdo = null)
    {
        $this->pdo = $pdo ?? \NixVm\Db::pdo();
    }

    /**
     * Enregistre un snapshot si le contenu utile a changé (ou si aucune version n’existe).
     *
     * @param array<string, mixed> $data
     * @return array{id:int,version_num:int}|null version créée, ou null si inchangé
     */
    public function record(int $profileId, array $data, string $reason = 'Publication'): ?array
    {
        if ($profileId <= 0) {
            return null;
        }

        $packages = self::normalizePackages($data['packages'] ?? []);
        $fingerprint = self::fingerprint($data);
        $latest = $this->latest($profileId);
        if ($latest !== null && hash_equals((string) $latest['fingerprint'], $fingerprint)) {
            return null;
        }

        $prevPkgs = $latest !== null
            ? self::normalizePackages($this->decodeData((string) $latest['data'])['packages'] ?? [])
            : [];
        $summary = self::diffSummary($prevPkgs, $packages, $reason);

        $payload = $data;
        $payload['packages'] = $packages;
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Impossible d’encoder le snapshot de profil.');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COALESCE(MAX(version_num), 0) FROM mdm_profile_versions WHERE profile_id = :pid FOR UPDATE'
            );
            $stmt->execute(['pid' => $profileId]);
            $next = ((int) $stmt->fetchColumn()) + 1;

            $ins = $this->pdo->prepare(
                'INSERT INTO mdm_profile_versions
                  (profile_id, version_num, reason, change_summary, packages_count, fingerprint, data)
                 VALUES
                  (:pid, :v, :reason, :summary, :count, :fp, :data)'
            );
            $ins->execute([
                'pid' => $profileId,
                'v' => $next,
                'reason' => mb_substr(trim($reason) !== '' ? trim($reason) : 'Publication', 0, 120),
                'summary' => mb_substr($summary, 0, 500),
                'count' => count($packages),
                'fp' => $fingerprint,
                'data' => $json,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->prune($profileId);
        return ['id' => $id, 'version_num' => $next];
    }

    /** @return list<array<string, mixed>> */
    public function listForProfile(int $profileId, int $limit = 40): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, profile_id, version_num, reason, change_summary, packages_count, fingerprint, created_at
             FROM mdm_profile_versions
             WHERE profile_id = :pid
             ORDER BY version_num DESC
             LIMIT ' . max(1, min(200, $limit))
        );
        $stmt->execute(['pid' => $profileId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'profile_id' => (int) $r['profile_id'],
                'version_num' => (int) $r['version_num'],
                'reason' => (string) $r['reason'],
                'change_summary' => (string) $r['change_summary'],
                'packages_count' => (int) $r['packages_count'],
                'fingerprint' => (string) $r['fingerprint'],
                'created_at' => (string) $r['created_at'],
            ];
        }, $rows);
    }

    /** @return array<string, mixed>|null */
    public function get(int $id, ?int $profileId = null): ?array
    {
        if ($profileId !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM mdm_profile_versions WHERE id = :id AND profile_id = :pid LIMIT 1'
            );
            $stmt->execute(['id' => $id, 'pid' => $profileId]);
        } else {
            $stmt = $this->pdo->prepare('SELECT * FROM mdm_profile_versions WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
        }
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $data = $this->decodeData((string) $row['data']);
        return [
            'id' => (int) $row['id'],
            'profile_id' => (int) $row['profile_id'],
            'version_num' => (int) $row['version_num'],
            'reason' => (string) $row['reason'],
            'change_summary' => (string) $row['change_summary'],
            'packages_count' => (int) $row['packages_count'],
            'fingerprint' => (string) $row['fingerprint'],
            'created_at' => (string) $row['created_at'],
            'data' => $data,
            'packages' => self::normalizePackages($data['packages'] ?? []),
        ];
    }

    /** @return array<string, mixed>|null */
    public function latest(int $profileId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM mdm_profile_versions WHERE profile_id = :pid ORDER BY version_num DESC LIMIT 1'
        );
        $stmt->execute(['pid' => $profileId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function countForProfile(int $profileId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM mdm_profile_versions WHERE profile_id = :pid');
        $stmt->execute(['pid' => $profileId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param list<string> $current
     * @param list<string> $target
     * @return array{added:list<string>,removed:list<string>}
     */
    public static function packageDiff(array $current, array $target): array
    {
        $a = self::normalizePackages($current);
        $b = self::normalizePackages($target);
        return [
            'added' => array_values(array_diff($b, $a)),
            'removed' => array_values(array_diff($a, $b)),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fingerprint(array $data): string
    {
        $norm = [
            'packages' => self::normalizePackages($data['packages'] ?? []),
            'autoUpgradeEnable' => !empty($data['autoUpgradeEnable']),
            'dates' => (string) ($data['dates'] ?? ''),
            'frequencyPreset' => (string) ($data['frequencyPreset'] ?? ''),
            'operation' => (string) ($data['operation'] ?? 'switch'),
            'allowReboot' => !empty($data['allowReboot']),
            'randomizedDelaySec' => (string) ($data['randomizedDelaySec'] ?? '0'),
            'hostname' => (string) ($data['hostname'] ?? ''),
        ];
        return hash('sha256', (string) json_encode($norm, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param list<string>|mixed $packages
     * @return list<string>
     */
    public static function normalizePackages(mixed $packages): array
    {
        if (!is_array($packages)) {
            return [];
        }
        $out = [];
        foreach ($packages as $p) {
            $p = trim((string) $p);
            if ($p !== '') {
                $out[] = $p;
            }
        }
        $out = array_values(array_unique($out));
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * @param list<string> $before
     * @param list<string> $after
     */
    public static function diffSummary(array $before, array $after, string $reason): string
    {
        $diff = self::packageDiff($before, $after);
        $bits = [];
        if ($diff['added'] !== []) {
            $bits[] = '+' . implode(', +', array_slice($diff['added'], 0, 12))
                . (count($diff['added']) > 12 ? '…' : '');
        }
        if ($diff['removed'] !== []) {
            $bits[] = '−' . implode(', −', array_slice($diff['removed'], 0, 12))
                . (count($diff['removed']) > 12 ? '…' : '');
        }
        if ($bits === []) {
            return $reason . ' (réglages, paquets inchangés)';
        }
        return $reason . ' · ' . implode(' · ', $bits);
    }

    private function prune(int $profileId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM mdm_profile_versions WHERE profile_id = :pid ORDER BY version_num DESC'
        );
        $stmt->execute(['pid' => $profileId]);
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
        if (count($ids) <= self::KEEP) {
            return;
        }
        $drop = array_slice($ids, self::KEEP);
        $in = implode(',', array_fill(0, count($drop), '?'));
        $del = $this->pdo->prepare("DELETE FROM mdm_profile_versions WHERE id IN ($in)");
        $del->execute($drop);
    }

    /** @return array<string, mixed> */
    private function decodeData(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
