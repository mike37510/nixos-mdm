<?php

declare(strict_types=1);

/**
 * Dossiers d’organisation des profils MDM (arbre, classement UI).
 */
final class ProfileFolders
{
    private \PDO $pdo;

    public function __construct(?\PDO $pdo = null)
    {
        $this->pdo = $pdo ?? \NixVm\Db::pdo();
    }

    /**
     * @return list<array{id:int,name:string,parent_id:?int,sort_order:int,created_at:string,profile_count:int}>
     */
    public function listAll(): array
    {
        $sql = <<<SQL
            SELECT f.id, f.name, f.parent_id, f.sort_order, f.created_at,
                   (SELECT COUNT(*) FROM mdm_profiles p WHERE p.folder_id = f.id) AS profile_count
            FROM mdm_profile_folders f
            ORDER BY f.sort_order ASC, f.name ASC
            SQL;
        $rows = $this->pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return array_map(static function (array $r): array {
            $pid = $r['parent_id'] ?? null;
            return [
                'id' => (int) $r['id'],
                'name' => (string) $r['name'],
                'parent_id' => $pid !== null && $pid !== '' ? (int) $pid : null,
                'sort_order' => (int) $r['sort_order'],
                'created_at' => (string) $r['created_at'],
                'profile_count' => (int) $r['profile_count'],
            ];
        }, $rows);
    }

    /**
     * @return list<array{id:int,name:string,parent_id:?int,sort_order:int,created_at:string,profile_count:int,children:list}>
     */
    public function tree(): array
    {
        return $this->buildTree($this->listAll(), null);
    }

    /**
     * @return list<array{id:int,label:string,depth:int}>
     */
    public function flatOptions(?int $excludeSubtreeOf = null): array
    {
        $exclude = $excludeSubtreeOf !== null && $excludeSubtreeOf > 0
            ? array_fill_keys($this->subtreeIds($excludeSubtreeOf), true)
            : [];
        $out = [];
        $walk = function (array $nodes, int $depth) use (&$walk, &$out, $exclude): void {
            foreach ($nodes as $n) {
                $id = (int) $n['id'];
                if (isset($exclude[$id])) {
                    continue;
                }
                $pad = $depth > 0 ? str_repeat(' — ', $depth) : '';
                $out[] = [
                    'id' => $id,
                    'label' => $pad . (string) $n['name'],
                    'depth' => $depth,
                ];
                if (!empty($n['children'])) {
                    $walk($n['children'], $depth + 1);
                }
            }
        };
        $walk($this->tree(), 0);
        return $out;
    }

    /** @return list<int> */
    public function subtreeIds(int $id): array
    {
        if ($id <= 0) {
            return [];
        }
        $byParent = [];
        foreach ($this->listAll() as $f) {
            $p = $f['parent_id'] ?? 0;
            $byParent[$p][] = (int) $f['id'];
        }
        $out = [];
        $stack = [$id];
        while ($stack !== []) {
            $cur = array_pop($stack);
            if ($cur <= 0 || in_array($cur, $out, true)) {
                continue;
            }
            $out[] = $cur;
            foreach ($byParent[$cur] ?? [] as $child) {
                $stack[] = $child;
            }
        }
        return $out;
    }

    public function create(string $name, ?int $parentId = null): int
    {
        $name = $this->normalizeName($name);
        $parentId = $this->normalizeParent($parentId);
        $this->assertSiblingNameUnique($name, $parentId, null);

        $max = (int) $this->pdo->query(
            'SELECT COALESCE(MAX(sort_order), 0) FROM mdm_profile_folders'
        )->fetchColumn();
        $stmt = $this->pdo->prepare(
            'INSERT INTO mdm_profile_folders (name, parent_id, sort_order) VALUES (:name, :pid, :ord)'
        );
        $stmt->execute(['name' => $name, 'pid' => $parentId, 'ord' => $max + 10]);
        return (int) $this->pdo->lastInsertId();
    }

    public function rename(int $id, string $name): void
    {
        $name = $this->normalizeName($name);
        if ($id <= 0) {
            throw new InvalidArgumentException('Dossier invalide.');
        }
        $row = $this->find($id);
        if ($row === null) {
            throw new InvalidArgumentException('Dossier introuvable.');
        }
        $this->assertSiblingNameUnique($name, $row['parent_id'], $id);
        $stmt = $this->pdo->prepare(
            'UPDATE mdm_profile_folders SET name = :name, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['name' => $name, 'id' => $id]);
    }

    public function move(int $id, ?int $parentId): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Dossier invalide.');
        }
        if (!$this->exists($id)) {
            throw new InvalidArgumentException('Dossier introuvable.');
        }
        $parentId = $this->normalizeParent($parentId);
        if ($parentId === $id) {
            throw new InvalidArgumentException('Un dossier ne peut pas être son propre parent.');
        }
        if ($parentId !== null && in_array($parentId, $this->subtreeIds($id), true)) {
            throw new InvalidArgumentException('Impossible de déplacer un dossier dans l’un de ses sous-dossiers.');
        }
        $row = $this->find($id);
        $this->assertSiblingNameUnique((string) ($row['name'] ?? ''), $parentId, $id);
        $stmt = $this->pdo->prepare(
            'UPDATE mdm_profile_folders SET parent_id = :pid, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['pid' => $parentId, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Dossier invalide.');
        }
        $ids = $this->subtreeIds($id);
        if ($ids === []) {
            throw new InvalidArgumentException('Dossier introuvable.');
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "UPDATE mdm_profiles SET folder_id = NULL WHERE folder_id IN ($placeholders)"
        );
        $stmt->execute($ids);
        $del = $this->pdo->prepare('DELETE FROM mdm_profile_folders WHERE id = :id');
        $del->execute(['id' => $id]);
    }

    public function exists(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM mdm_profile_folders WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return (bool) $stmt->fetchColumn();
    }

    public function countUnfiled(): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM mdm_profiles WHERE folder_id IS NULL'
        )->fetchColumn();
    }

    /** @return array{id:int,name:string,parent_id:?int}|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, parent_id FROM mdm_profile_folders WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$r) {
            return null;
        }
        $pid = $r['parent_id'] ?? null;
        return [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'parent_id' => $pid !== null && $pid !== '' ? (int) $pid : null,
        ];
    }

    /**
     * @param list<array{id:int,name:string,parent_id:?int,sort_order:int,created_at:string,profile_count:int}> $flat
     * @return list<array{id:int,name:string,parent_id:?int,sort_order:int,created_at:string,profile_count:int,children:list}>
     */
    private function buildTree(array $flat, ?int $parentId): array
    {
        $out = [];
        foreach ($flat as $f) {
            $pid = $f['parent_id'] ?? null;
            $match = ($parentId === null && $pid === null)
                || ($parentId !== null && $pid === $parentId);
            if (!$match) {
                continue;
            }
            $node = $f;
            $node['children'] = $this->buildTree($flat, (int) $f['id']);
            $out[] = $node;
        }
        return $out;
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Nom de dossier vide.');
        }
        if (mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Nom de dossier trop long.');
        }
        return $name;
    }

    private function normalizeParent(?int $parentId): ?int
    {
        if ($parentId === null || $parentId <= 0) {
            return null;
        }
        if (!$this->exists($parentId)) {
            throw new InvalidArgumentException('Dossier parent introuvable.');
        }
        return $parentId;
    }

    private function assertSiblingNameUnique(string $name, ?int $parentId, ?int $exceptId): void
    {
        if ($parentId === null) {
            $sql = 'SELECT id FROM mdm_profile_folders WHERE parent_id IS NULL AND name = :name';
            $params = ['name' => $name];
        } else {
            $sql = 'SELECT id FROM mdm_profile_folders WHERE parent_id = :pid AND name = :name';
            $params = ['pid' => $parentId, 'name' => $name];
        }
        if ($exceptId !== null) {
            $sql .= ' AND id <> :ex';
            $params['ex'] = $exceptId;
        }
        $stmt = $this->pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        if ($stmt->fetch()) {
            throw new InvalidArgumentException('Un dossier porte déjà ce nom à cet endroit.');
        }
    }
}
