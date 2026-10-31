<?php
declare(strict_types=1);

namespace NixVm;

use PDO;

/**
 * Catalogue DSI : paquets validés, visibles ou non dans le magasin.
 */
final class CatalogStore
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(bool $storeOnly = false): array
    {
        $sql = 'SELECT * FROM mdm_catalog';
        if ($storeOnly) {
            $sql .= ' WHERE approved = 1 AND store_visible = 1';
        }
        $sql .= ' ORDER BY label ASC, attr ASC';
        return $this->pdo->query($sql)->fetchAll();
    }

    public function upsert(string $attr, string $label, string $description = '', ?string $version = null, ?string $iconUrl = null, bool $approved = true, bool $storeVisible = true): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO mdm_catalog (attr, label, description, version_cache, icon_url, approved, store_visible)
             VALUES (:attr, :label, :description, :version, :icon, :approved, :store)
             ON DUPLICATE KEY UPDATE
               label = VALUES(label),
               description = VALUES(description),
               version_cache = COALESCE(VALUES(version_cache), version_cache),
               icon_url = COALESCE(VALUES(icon_url), icon_url),
               approved = VALUES(approved),
               store_visible = VALUES(store_visible),
               updated_at = NOW()'
        );
        $stmt->execute([
            'attr' => $attr,
            'label' => $label !== '' ? $label : $attr,
            'description' => $description,
            'version' => $version,
            'icon' => $iconUrl,
            'approved' => $approved ? 1 : 0,
            'store' => $storeVisible ? 1 : 0,
        ]);
    }

    public function setFlags(int $id, bool $approved, bool $storeVisible): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE mdm_catalog SET approved = :a, store_visible = :s, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['a' => $approved ? 1 : 0, 's' => $storeVisible ? 1 : 0, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM mdm_catalog WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function findByAttr(string $attr): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mdm_catalog WHERE attr = :a LIMIT 1');
        $stmt->execute(['a' => $attr]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
