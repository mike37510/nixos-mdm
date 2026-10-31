<?php
declare(strict_types=1);

namespace NixVm;

final class Repository
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function listConfigs(): array
    {
        $rows = Db::pdo()->query(<<<SQL
            SELECT c.id, c.name, c.base, c.data, c.updated_at,
                   b.id AS build_id, b.status AS build_status, b.finished_at AS build_finished_at, b.artifact, b.artifact_size
            FROM vm_configs c
            LEFT JOIN vm_builds b ON b.id = (SELECT MAX(id) FROM vm_builds WHERE config_id = c.id)
            ORDER BY c.updated_at DESC
            SQL)->fetchAll();
        foreach ($rows as &$r) {
            $r['data'] = json_decode((string) $r['data'], true);
        }
        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getConfig(int $id): ?array
    {
        $st = Db::pdo()->prepare('SELECT * FROM vm_configs WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            return null;
        }
        $row['data'] = json_decode((string) $row['data'], true);
        return $row;
    }

    /**
     * @param array<string, mixed> $spec
     */
    public static function saveConfig(?int $id, array $spec): int
    {
        $json = json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $pdo = Db::pdo();
        if ($id !== null) {
            $pdo->prepare('UPDATE vm_configs SET name = ?, base = ?, data = ? WHERE id = ?')
                ->execute([$spec['name'], $spec['base'], $json, $id]);
            return $id;
        }
        $pdo->prepare('INSERT INTO vm_configs (name, base, data) VALUES (?, ?, ?)')
            ->execute([$spec['name'], $spec['base'], $json]);
        return (int) $pdo->lastInsertId();
    }

    public static function deleteConfig(int $id): void
    {
        foreach (self::listBuilds($id) as $b) {
            Builder::deleteBuildFiles((int) $b['id']);
        }
        Db::pdo()->prepare('DELETE FROM vm_configs WHERE id = ?')->execute([$id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listBuilds(int $configId): array
    {
        $st = Db::pdo()->prepare('SELECT id, config_id, status, artifact, artifact_size, error, created_at, started_at, finished_at FROM vm_builds WHERE config_id = ? ORDER BY id DESC');
        $st->execute([$configId]);
        return $st->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getBuild(int $id): ?array
    {
        $st = Db::pdo()->prepare('SELECT * FROM vm_builds WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            return null;
        }
        $row['snapshot'] = json_decode((string) $row['snapshot'], true);
        return $row;
    }

    /**
     * @param array<string, mixed> $fields
     */
    public static function updateBuild(int $id, array $fields): void
    {
        $sets = [];
        $vals = [];
        foreach ($fields as $k => $v) {
            if (!preg_match('/^[a-z_]+$/', $k)) {
                continue;
            }
            if ($v === 'NOW()') {
                $sets[] = "$k = NOW()";
                continue;
            }
            $sets[] = "$k = ?";
            $vals[] = $v;
        }
        $vals[] = $id;
        Db::pdo()->prepare('UPDATE vm_builds SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
    }
}
