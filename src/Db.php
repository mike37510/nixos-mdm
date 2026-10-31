<?php
declare(strict_types=1);

namespace NixVm;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            /** @var array<string, mixed> $db */
            $db = app_config()['db'] ?? require app_config()['db_file'];
            self::$pdo = new PDO((string) $db['dsn'], (string) $db['user'], (string) $db['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::migrate(self::$pdo);
        }
        return self::$pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS vm_configs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                base VARCHAR(40) NOT NULL,
                data JSON NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS vm_builds (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                config_id INT UNSIGNED NOT NULL,
                status ENUM('queued','running','success','failed','cancelled') NOT NULL DEFAULT 'queued',
                snapshot JSON NOT NULL,
                pid INT NULL,
                artifact VARCHAR(255) NULL,
                artifact_size BIGINT UNSIGNED NULL,
                error TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                started_at DATETIME NULL,
                finished_at DATETIME NULL,
                INDEX (config_id),
                CONSTRAINT fk_build_config FOREIGN KEY (config_id) REFERENCES vm_configs(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);

        // --- MDM : profils de parc + inventaire clients ---
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS mdm_profiles (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(64) NOT NULL,
                label VARCHAR(120) NOT NULL,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                data JSON NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_mdm_profiles_slug (slug),
                KEY idx_mdm_profiles_default (is_default)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS mdm_clients (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                device_token CHAR(64) NOT NULL,
                hostname VARCHAR(120) NULL,
                mac VARCHAR(32) NULL,
                profile_id INT UNSIGNED NOT NULL,
                last_seen_at DATETIME NULL,
                last_sync_at DATETIME NULL,
                last_ip VARCHAR(45) NULL,
                meta JSON NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_mdm_clients_token (device_token),
                KEY idx_mdm_clients_mac (mac),
                KEY idx_mdm_clients_hostname (hostname),
                CONSTRAINT fk_mdm_client_profile FOREIGN KEY (profile_id) REFERENCES mdm_profiles(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS mdm_users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(64) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                role ENUM('admin','dsi') NOT NULL DEFAULT 'admin',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_mdm_users_username (username)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS mdm_catalog (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                attr VARCHAR(120) NOT NULL,
                label VARCHAR(160) NOT NULL,
                description TEXT NULL,
                version_cache VARCHAR(80) NULL,
                icon_url VARCHAR(512) NULL,
                approved TINYINT(1) NOT NULL DEFAULT 1,
                store_visible TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_mdm_catalog_attr (attr)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS mdm_profile_versions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                profile_id INT UNSIGNED NOT NULL,
                version_num INT UNSIGNED NOT NULL,
                reason VARCHAR(120) NOT NULL DEFAULT 'Publication',
                change_summary VARCHAR(500) NOT NULL DEFAULT '',
                packages_count INT UNSIGNED NOT NULL DEFAULT 0,
                fingerprint CHAR(64) NOT NULL,
                data JSON NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_mdm_profile_ver (profile_id, version_num),
                KEY idx_mdm_profile_ver_created (profile_id, created_at),
                CONSTRAINT fk_mdm_profile_ver_profile
                  FOREIGN KEY (profile_id) REFERENCES mdm_profiles(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS mdm_machine_folders (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                parent_id INT UNSIGNED NULL DEFAULT NULL,
                name VARCHAR(120) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_mdm_machine_folders_parent (parent_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS mdm_profile_folders (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                parent_id INT UNSIGNED NULL DEFAULT NULL,
                name VARCHAR(120) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_mdm_profile_folders_parent (parent_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);

        // Migration douce : parent_id + suppression UNIQUE global sur le nom
        self::ensureFolderNesting($pdo, 'mdm_machine_folders', 'uq_mdm_machine_folders_name', 'fk_mdm_machine_folders_parent');
        self::ensureFolderNesting($pdo, 'mdm_profile_folders', 'uq_mdm_profile_folders_name', 'fk_mdm_profile_folders_parent');

        $hasProfFolder = false;
        try {
            $cols = $pdo->query('SHOW COLUMNS FROM mdm_profiles LIKE \'folder_id\'')->fetchAll();
            $hasProfFolder = $cols !== [];
        } catch (\Throwable $e) {
            $hasProfFolder = false;
        }
        if (!$hasProfFolder) {
            $pdo->exec(
                'ALTER TABLE mdm_profiles
                 ADD COLUMN folder_id INT UNSIGNED NULL DEFAULT NULL AFTER is_default,
                 ADD KEY idx_mdm_profiles_folder (folder_id)'
            );
            try {
                $pdo->exec(
                    'ALTER TABLE mdm_profiles
                     ADD CONSTRAINT fk_mdm_profile_folder
                       FOREIGN KEY (folder_id) REFERENCES mdm_profile_folders(id) ON DELETE SET NULL'
                );
            } catch (\Throwable $e) {
                // déjà présent
            }
        }

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS mdm_app_settings (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
                data JSON NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);

        // folder_id sur clients existants (migration douce)
        $hasFolder = false;
        try {
            $cols = $pdo->query('SHOW COLUMNS FROM mdm_clients LIKE \'folder_id\'')->fetchAll();
            $hasFolder = $cols !== [];
        } catch (\Throwable $e) {
            $hasFolder = false;
        }
        if (!$hasFolder) {
            $pdo->exec(
                'ALTER TABLE mdm_clients
                 ADD COLUMN folder_id INT UNSIGNED NULL DEFAULT NULL AFTER profile_id,
                 ADD KEY idx_mdm_clients_folder (folder_id)'
            );
            try {
                $pdo->exec(
                    'ALTER TABLE mdm_clients
                     ADD CONSTRAINT fk_mdm_client_folder
                       FOREIGN KEY (folder_id) REFERENCES mdm_machine_folders(id) ON DELETE SET NULL'
                );
            } catch (\Throwable $e) {
                // contrainte déjà présente ou moteur sans FK
            }
        }

        // Statut de sync client (rapport post nixos-rebuild)
        self::ensureClientColumn($pdo, 'sync_status', "VARCHAR(16) NULL DEFAULT NULL AFTER last_sync_at");
        self::ensureClientColumn($pdo, 'sync_error', 'TEXT NULL AFTER sync_status');
        self::ensureClientColumn($pdo, 'nix_generation', 'INT UNSIGNED NULL AFTER sync_error');
        self::ensureClientColumn($pdo, 'profile_version', 'INT UNSIGNED NULL AFTER nix_generation');
        self::ensureClientColumn($pdo, 'last_sync_ok_at', 'DATETIME NULL AFTER profile_version');
        self::ensureClientColumn($pdo, 'rolled_back', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER last_sync_ok_at');
        try {
            $pdo->exec('ALTER TABLE mdm_clients ADD KEY idx_mdm_clients_sync_status (sync_status)');
        } catch (\Throwable $e) {
            // index déjà présent
        }
    }

    private static function ensureClientColumn(\PDO $pdo, string $name, string $definition): void
    {
        try {
            $cols = $pdo->query('SHOW COLUMNS FROM mdm_clients LIKE ' . $pdo->quote($name))->fetchAll();
            if ($cols === []) {
                $pdo->exec("ALTER TABLE mdm_clients ADD COLUMN `{$name}` {$definition}");
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    private static function ensureFolderNesting(
        \PDO $pdo,
        string $table,
        string $oldUniqueName,
        string $fkName
    ): void {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'parent_id'")->fetchAll();
            if ($cols === []) {
                $pdo->exec(
                    "ALTER TABLE `{$table}`
                     ADD COLUMN parent_id INT UNSIGNED NULL DEFAULT NULL AFTER id,
                     ADD KEY idx_{$table}_parent (parent_id)"
                );
            }
        } catch (\Throwable $e) {
            // ignore
        }
        try {
            $indexes = $pdo->query("SHOW INDEX FROM `{$table}` WHERE Key_name = " . $pdo->quote($oldUniqueName))->fetchAll();
            if ($indexes !== []) {
                $pdo->exec("ALTER TABLE `{$table}` DROP INDEX `{$oldUniqueName}`");
            }
        } catch (\Throwable $e) {
            // ignore
        }
        try {
            $pdo->exec(
                "ALTER TABLE `{$table}`
                 ADD CONSTRAINT `{$fkName}`
                   FOREIGN KEY (parent_id) REFERENCES `{$table}`(id) ON DELETE CASCADE"
            );
        } catch (\Throwable $e) {
            // déjà présent
        }
    }
}
